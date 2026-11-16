<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Restoration;

use Db;
use Pixel\Module\Audit\Configuration\Settings;

/** Undo explicitly declared product fields, with an atomic business write + immutable audit record. */
final class ProductRestore
{
    public function __construct(private \Pixel_audit $module)
    {
    }

    public function preview(int $product, int $event, array $fields): array
    {
        $plan = $this->plan($product, $event, $fields);
        $plan['token'] = '';
        if (!$plan['conflict']) {
            $employee = \Context::getContext()->employee;
            $plan['token'] = (new PreviewToken(_COOKIE_KEY_))->issue([
                'product' => $product, 'event' => $event, 'fields' => $plan['fields'],
                'fingerprint' => $plan['fingerprint'], 'employee' => (int) $employee->id,
                'session' => hash('sha256', $this->module->correlationSession()),
                'expires' => time() + 300, 'nonce' => bin2hex(random_bytes(16)),
            ]);
        }
        return $plan;
    }

    public function apply(string $token, int $product): array
    {
        $employee = \Context::getContext()->employee;
        $claims = (new PreviewToken(_COOKIE_KEY_))->verify($token, (int) $employee->id, $this->module->correlationSession(), time());
        if ($claims['product'] !== $product) {
            throw new RestoreException('The preview product has changed.');
        }
        $db = Db::getInstance();
        $link = $db->getLink();
        if (!$link instanceof \PDO || $link->inTransaction()) {
            throw new RestoreException('Restoration requires an independent transaction.', 503);
        }
        $this->assertTransactionalTables();
        $this->execute('START TRANSACTION');

        try {
            // Lock before the first consistent read in this transaction, so ObjectModel also loads fresh values.
            foreach (['product', 'product_shop', 'product_lang'] as $table) {
                $locked = $db->executeS('SELECT id_product FROM `' . _DB_PREFIX_ . $table . '` WHERE id_product = ' . $product . ' FOR UPDATE', true, false);
                if ($locked === false) {
                    throw new \RuntimeException('Cannot lock product restoration.');
                }
            }
            $provider = $db->getValue('SELECT provider FROM `' . _DB_PREFIX_ . 'pixel_audit_event` WHERE id_event=' . (int) $claims['event'] . ' FOR UPDATE', false);
            $storage = is_string($provider) ? $this->module->registry()->restorationStorage($provider) : null;
            if ($storage !== null) {
                $this->assertTransactionalTables($storage->tables());
                $storage->lockProduct($product);
            }
            $plan = $this->plan($product, $claims['event'], $claims['fields'], true);

            if ($plan['conflict'] || !hash_equals($plan['fingerprint'], (string) ($claims['fingerprint'] ?? ''))) {
                throw new RestoreException('The data or scope has changed. Preview the restoration again.');
            }
            if ($db->getValue('SELECT id_operation FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` WHERE operation_key = "' . pSQL($claims['nonce']) . '"', false)) {
                throw new RestoreException('This preview has already been applied.');
            }
            // Reserve the one-use nonce before invoking business hooks; rollback removes it on any failure.
            $this->insert('pixel_audit_operation', [
                'operation_key' => $claims['nonce'], 'actor_id' => (int) $employee->id,
                'actor_name' => pSQL(trim($employee->firstname . ' ' . $employee->lastname)),
                'origin' => 'backoffice', 'route' => 'AdminPixelAuditRestore', 'observation' => 'observed',
                'date_add' => gmdate('Y-m-d H:i:s'), 'source_operation_id' => $plan['operation'], 'source_event_id' => $claims['event'],
            ]);
            $operation = (int) $db->Insert_ID();

            $this->module->runProductRestoration(function () use ($plan, $product): void {
                $plan['storage'] !== null ? $plan['storage']->write($plan) : $this->writeProduct($product, $plan);
            });
            $this->assertTransactionStillOpen();
            // Re-read uncached values before commit. A native hook changing a requested value invalidates the whole write.
            $after = $plan['storage'] !== null ? $plan['storage']->read($plan['subject'], $plan['fields'], $plan['definition']) : $this->readValues($product, $plan['shop'], $plan['fields'], $plan['definition']);

            foreach ($plan['rows'] as $row) {
                if (!array_key_exists($row['key'], $after) || $plan['definition']->field($row['key'])->normalize($after[$row['key']]) !== $row['target']) {
                    throw new RestoreException('Business validation changed the proposed values. Restoration cancelled.');
                }
            }
            $events = [];
            foreach ($plan['rows'] as $row) {
                $shops = $plan['definition']->field($row['key'])->scope === 'global' ? $plan['affected_shops'] : [$plan['shop']];
                foreach ($shops as $shop) {
                    $events[$shop][$row['key']] = ['label' => $plan['definition']->field($row['key'])->describe($row['key'])['label'], 'type' => $row['type'], 'before' => $row['current'], 'after' => $row['target']];
                }
            }
            foreach ($events as $shop => $changes) {
                $json = json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (strlen($json) > 2097152) {
                    throw new RestoreException('The restoration exceeds the maximum audit event size.', 400);
                }
                $this->insert('pixel_audit_event', [
                    'id_operation' => $operation, 'family' => $plan['definition']->family, 'provider' => $plan['definition']->provider,
                    'subject_type' => $plan['subject_type'], 'subject_id' => $plan['subject'], 'root_type' => 'product', 'root_id' => $product,
                    'id_shop' => $shop, 'action' => 'update', 'changes' => pSQL($json, true),
                ]);
            }
            $this->execute('COMMIT');
        } catch (\Throwable $error) {
            $db->execute('ROLLBACK');

            \Cache::clean('objectmodel_Product_*');
            throw $error;
        }
        // Product::update emits these after its ObjectModel write. Faceted Search TRUNCATEs its cache here,
        // which implicitly commits MySQL transactions: dispatch only after our business + journal COMMIT.
        $warning = false;
        try {
            \Cache::clean('objectmodel_Product_*');
            if ($plan['storage'] !== null) {
                $plan['storage']->afterCommit($product);
            }
            $updated = new \Product($product, false, null, $plan['shop']);
            \Hook::exec('actionProductSave', ['id_product' => $product, 'product' => $updated]);
            \Hook::exec('actionProductUpdate', ['id_product' => $product, 'product' => $updated]);
        } catch (\Throwable $error) {
            \Pixel\Module\Audit\Core\Diagnostics::report($error);
            $warning = true;
        }
        return ['operation' => $operation, 'synchronization_warning' => $warning];
    }

    private function plan(int $product, int $event, array $fields, bool $lock = false): array
    {
        if (!$this->module->canRestoreProducts()) {
            throw new RestoreException('Insufficient restoration permissions or product history disabled.', 403);
        }
        // Do not silently bypass a third-party update override with unknown validation/side effects.
        if ((new \ReflectionMethod(\Product::class, 'update'))->getDeclaringClass()->getName() !== 'ProductCore'
            || (new \ReflectionMethod(\ObjectModel::class, 'update'))->getDeclaringClass()->getName() !== 'ObjectModelCore') {
            throw new RestoreException('A product update override requires a dedicated restoration integration.', 503);
        }
        $shops = $this->module->productHistoryShops($product);
        if (!$shops) {
            throw new RestoreException('Product unavailable in this shop context.', 404);
        }
        $db = Db::getInstance();
        $rows = $db->executeS('SELECT e.*, o.date_add FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e INNER JOIN `' . _DB_PREFIX_ . 'pixel_audit_operation` o ON o.id_operation = e.id_operation WHERE e.id_event = ' . max(0, $event)
            . ' AND e.root_type = "product" AND e.root_id = ' . $product . ' AND ((e.subject_type = "product" AND e.subject_id = ' . $product . ') OR (e.subject_type = "combination"))'
            . ' AND e.action = "update" AND e.is_visible = 1 AND e.id_shop IN (' . implode(',', $shops) . ')'
            . ' LIMIT 1' . ($lock ? ' FOR UPDATE' : ''), true, false);
        if ($rows === false) {
            throw new \RuntimeException('Cannot read restoration source.');
        }
        if (!$rows) {
            throw new RestoreException('The source event is missing, expired or unavailable for restoration in this context.', 404);
        }
        $source = $rows[0];
        $shop = (int) $source['id_shop'];
        $definition = $this->module->productRestorationDefinition($source['provider']);
        if ($definition === null || $definition->family !== $source['family']) {
            throw new RestoreException('Restoration provider unavailable or family disabled.', 403);
        }
        if ($definition->owner !== 'prestashop' && !$db->getValue('SELECT ms.id_module FROM `' . _DB_PREFIX_ . 'module_shop` ms INNER JOIN `' . _DB_PREFIX_ . 'module` m ON m.id_module = ms.id_module WHERE m.name = "' . pSQL($definition->owner) . '" AND m.active = 1 AND ms.id_shop = ' . $shop, false)) {
            throw new RestoreException('The owning module must be enabled in the source event shop.', 403);
        }
        if (strtotime($source['date_add'] . ' UTC') < time() - (new Settings())->days($definition->family) * 86400) {
            throw new RestoreException('The source event has expired.', 404);
        }
        $changes = json_decode($source['changes'], true, 512, JSON_THROW_ON_ERROR);
        $selected = $definition->select($fields, $changes);
        $storage = $this->module->registry()->restorationStorage($source['provider']);
        $external = $storage !== null;
        $subject = (int) $source['subject_id'];
        if ($source['subject_type'] !== ($storage !== null ? $storage->subjectType() : 'product')) {
            throw new RestoreException('The source type does not match the restoration storage.', 400);
        }
        if ($storage !== null) {
            $storage->check($product, $subject, $shop, $lock);
        }
        $suffix = $lock ? ' FOR UPDATE' : '';
        $base = $db->executeS('SELECT id_product FROM `' . _DB_PREFIX_ . 'product` WHERE id_product = ' . $product . $suffix, true, false);
        $associations = $db->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . $product . ' ORDER BY id_shop' . $suffix, true, false);
        if ($base === false || $associations === false) {
            throw new \RuntimeException('Cannot read restoration product.');
        }
        if (!$base || !in_array($shop, array_map('intval', array_column($associations, 'id_shop')), true)) {
            throw new RestoreException('The product or its shop association no longer exists.', 404);
        }
        $global = array_filter($selected, static fn ($key) => $definition->field($key)->scope === 'global');
        $affectedShops = $global ? array_map('intval', array_column($associations, 'id_shop')) : [$shop];
        $employee = \Context::getContext()->employee;
        if (!$employee->isSuperAdmin() && array_diff($affectedShops, array_map('intval', $employee->getAssociatedShops()))) {
            throw new RestoreException('A selected field is shared: access to every associated product shop is required.', 403);
        }
        if ($lock) {
            $langs = $db->executeS('SELECT id_lang FROM `' . _DB_PREFIX_ . 'product_lang` WHERE id_product = ' . $product . ' AND id_shop = ' . $shop . ' ORDER BY id_lang FOR UPDATE', true, false);
            if ($langs === false) {
                throw new \RuntimeException('Cannot lock restoration translations.');
            }
        }
        \Cache::clean('objectmodel_Product_*');
        $model = new \Product($product, false, null, $shop);
        if (!$external) {
            $definition->assertModel();
        }
        $current = $storage !== null ? $storage->read($subject, $selected, $definition) : $this->readValues($product, $shop, $selected, $definition);
        foreach ($selected as $key) {
            if (!array_key_exists($key, $current)) {
                throw new RestoreException('The storage adapter did not return every selected field.', 503);
            }
            $current[$key] = $definition->field($key)->normalize($current[$key]);
        }
        $result = [];
        $conflict = false;
        foreach ($selected as $key) {
            $field = $definition->field($key);
            $description = $field->describe($key);
            $value = $field->normalize($changes[$key]['before']);
            $expected = $field->normalize($changes[$key]['after']);
            if ((!$external && $value !== null && $model->validateField($description['field'], $value, $description['language'] ?: null) !== true)
                || ($field->type === 'text' && $value !== null && !\Validate::isCleanHtml($value))
                || ($description['field'] === 'description_short' && \Tools::strlen(strip_tags($value)) > (int) (\Configuration::get('PS_PRODUCT_SHORT_DESC_LIMIT') ?: 800))) {
                throw new RestoreException('A historical value no longer meets the current product validation rules.', 400);
            }
            $fieldConflict = $current[$key] !== $expected;
            $conflict = $conflict || $fieldConflict;
            $result[] = ['key' => $key, 'label' => $this->module->auditFieldLabel($definition->provider, $key, $field->label), 'type' => $description['type'], 'scope' => $description['scope'],
                'current' => $current[$key], 'expected' => $expected, 'target' => $value, 'conflict' => $fieldConflict,
                'current_display' => \Pixel\Module\Audit\Presentation\Presenter::value($current[$key], $description['type']),
                'target_display' => \Pixel\Module\Audit\Presentation\Presenter::value($value, $description['type'])];
        }
        $context = [\Shop::getContext(), \Shop::getContextShopID(), \Shop::getContextShopGroupID()];
        return ['external' => $external, 'storage' => $storage, 'subject_type' => $source['subject_type'], 'subject' => $subject, 'fields' => $selected, 'rows' => $result, 'conflict' => $conflict, 'shop' => $shop,
            'definition' => $definition, 'affected_shops' => $affectedShops, 'operation' => (int) $source['id_operation'], 'event' => $event,
            'fingerprint' => hash('sha256', json_encode([$source, $result, $affectedShops, $context, $definition->fingerprint(), $definition->owner === 'prestashop' ? _PS_VERSION_ : \Module::getInstanceByName($definition->owner)->version], JSON_THROW_ON_ERROR))];
    }

    private function readValues(int $product, int $shop, array $keys, \Pixel\Module\Audit\Api\ProductRestorationDefinition $definition): array
    {
        $db = Db::getInstance();
        $rows = $db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'product` WHERE id_product = ' . $product, true, false);
        $shopRows = $db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . $product . ' AND id_shop = ' . $shop, true, false);
        $translations = $db->executeS('SELECT pl.* FROM `' . _DB_PREFIX_ . 'product_lang` pl INNER JOIN `' . _DB_PREFIX_ . 'lang` l ON l.id_lang = pl.id_lang WHERE pl.id_product = ' . $product . ' AND pl.id_shop = ' . $shop, true, false);
        if ($rows === false || $translations === false || $shopRows === false) {
            throw new \RuntimeException('Cannot read current product values.');
        }
        $languages = [];
        foreach ($translations as $row) {
            $languages[(int) $row['id_lang']] = $row;
        }
        $values = [];
        foreach ($keys as $key) {
            $descriptor = $definition->field($key);
            $field = $descriptor->describe($key);
            $row = $field['language'] ? ($languages[$field['language']] ?? []) : ($field['scope'] === 'shop' ? ($shopRows[0] ?? []) : ($rows[0] ?? []));
            if (!array_key_exists($field['field'], $row)) {
                throw new RestoreException('A product column or translation is unavailable.', 400);
            }
            $values[$key] = $descriptor->normalize($row[$field['field']]);
        }
        return $values;
    }

    private function writeProduct(int $id, array $plan): void
    {
        $groups = [];
        $global = [];
        foreach ($plan['rows'] as $row) {
            $field = $plan['definition']->field($row['key'])->describe($row['key']);
            if ($field['language']) {
                $groups[$field['language']][] = $row;
            } else {
                $global[] = $row;
            }
        }
        if (!$groups) {
            $language = (int) Db::getInstance()->getValue('SELECT pl.id_lang FROM `' . _DB_PREFIX_ . 'product_lang` pl INNER JOIN `' . _DB_PREFIX_ . 'lang` l ON l.id_lang = pl.id_lang WHERE pl.id_product = ' . $id . ' AND pl.id_shop = ' . $plan['shop'] . ' ORDER BY pl.id_lang', false);
            if (!$language) {
                throw new RestoreException('No existing translation is available for a partial product update.', 400);
            }
            $groups[$language] = [];
        }
        foreach ($groups as $language => $rows) {
            \Cache::clean('objectmodel_Product_*');
            $product = new \Product($id, false, $language, $plan['shop']);

            $product->id_shop_list = [$plan['shop']];
            $flags = [];
            foreach (array_merge($rows, $global) as $row) {
                $field = $plan['definition']->field($row['key'])->describe($row['key']);
                $product->{$field['field']} = $row['target'];
                if ($field['language']) {
                    $flags[$field['field']][$language] = true;
                } else {
                    $flags[$field['field']] = true;
                }
            }
            $global = []; // A shared property is written once, not once per language.
            $product->setFieldsToUpdate($flags);
            // Invoke the public native ObjectModel API on the Product instance: validation, partial field
            // formatting, cache invalidation and ObjectModel hooks remain active. ProductSave/Update are deferred.
            if (!(new \ReflectionMethod(\ObjectModel::class, 'update'))->invoke($product, true)) {
                throw new RestoreException('The product update failed. Restoration cancelled.', 422);
            }
        }
    }

    private function assertTransactionStillOpen(): void
    {
        $connection = Db::getInstance()->getLink();
        if (!$connection instanceof \PDO || !$connection->inTransaction()) {
            throw new \RuntimeException('A product hook interrupted the restoration transaction.');
        }
    }

    private function assertTransactionalTables(array $extraTables = []): void
    {
        $names = array_map(static fn ($table) => _DB_PREFIX_ . $table, ['product', 'product_shop', 'product_lang', 'pixel_audit_operation', 'pixel_audit_event']);
        $names = array_values(array_unique(array_merge($names, array_map(static fn ($table) => _DB_PREFIX_ . $table, $extraTables))));
        $quoted = implode(',', array_map(static fn ($table) => '"' . pSQL($table) . '"', $names));
        $tables = Db::getInstance()->executeS('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $quoted . ')', true, false);
        if ($tables === false || count($tables) !== count($names) || array_filter($tables, static fn ($row) => strtoupper($row['ENGINE']) !== 'INNODB')) {
            throw new RestoreException('Product and audit tables must use InnoDB.', 503);
        }
    }

    private function execute(string $sql): void
    {
        if (!Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Restoration SQL failed.');
        }
    }
    private function insert(string $table, array $data): void
    {
        if (!Db::getInstance()->insert($table, $data)) {
            throw new \RuntimeException('Restoration journal insert failed.');
        }
    }
}
