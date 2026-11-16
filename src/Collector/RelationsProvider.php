<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Collector;

use Db;
use Pixel\Module\Audit\Api\Field;
use Pixel\Module\Audit\Api\ProviderInterface;
use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Api\Subject;

final class RelationsProvider implements ProviderInterface, \Pixel\Module\Audit\Api\FieldLabelProviderInterface, \Pixel\Module\Audit\Api\ResettableProviderInterface
{
    private array $initialCombinations = [];
    private array $customFeatures = [];

    public function __construct(private string $type, private string $familyName)
    {
    }
    public function name(): string
    {
        return 'native.' . $this->type . '.relations';
    }
    public function family(): string
    {
        return $this->familyName;
    }
    public function supports(Subject $subject): bool
    {
        return $subject->type === $this->type;
    }

    public function captureProductRelations(int $product): void
    {
        if ($this->type !== 'combination' || isset($this->initialCombinations[$product])) {
            return;
        }
        $initial = [];
        foreach (['attributes' => ['product_attribute_combination', 'id_attribute'], 'images' => ['product_attribute_image', 'id_image']] as $field => [$table, $primary]) {
            $rows = Db::getInstance()->executeS('SELECT c.id_product_attribute, r.`' . $primary . '` AS value FROM `' . _DB_PREFIX_ . 'product_attribute` c LEFT JOIN `' . _DB_PREFIX_ . $table . '` r ON r.id_product_attribute = c.id_product_attribute WHERE c.id_product = ' . $product, true, false);
            if ($rows === false) {
                throw new \RuntimeException('Cannot read initial combination relations.');
            }
            foreach ($rows as $row) {
                $initial[$row['id_product_attribute']][$field] ??= [];
                if ($row['value'] !== null) {
                    $initial[$row['id_product_attribute']][$field][] = (int) $row['value'];
                }
            }
        }
        $this->initialCombinations[$product] = $initial;
    }

    public function initialSnapshot(Subject $subject, Snapshot $snapshot): Snapshot
    {
        $initial = $this->initialCombinations[$subject->rootId ?? 0][$subject->id] ?? null;
        if ($this->type !== 'combination' || $initial === null || !$snapshot->exists) {
            return $snapshot;
        }
        $fields = $snapshot->fields;
        foreach ($initial as $name => $values) {
            if (isset($fields[$name])) {
                $field = $fields[$name];
                $fields[$name] = new Field($values, $field->label, $field->type);
            }
        }
        return new Snapshot(true, $fields);
    }

    public function comparisonSnapshots(Subject $subject, Snapshot $before, Snapshot $after): array
    {
        if ($this->type !== 'product' || !$before->exists || !$after->exists) {
            return [$before, $after];
        }
        return CustomFeatureComparison::snapshots($before, $after, $this->customFeatures[$subject->key()] ?? []);
    }

    public function reset(): void
    {
        $this->initialCombinations = [];
        $this->customFeatures = [];
    }

    public function fieldLabel(string $field): ?string
    {
        $labels = ['categories' => 'Categories','carriers' => 'Carriers','attachments' => 'Associated documents','accessories' => 'Accessories',
            'admin_permissions' => 'Back-office page permissions','module_permissions' => 'Module permissions','groups' => 'Groups',
            'attributes' => 'Attributes','images' => 'Combination images','features' => 'Features (feature:value)','tags' => 'Associated tags'];
        if (isset($labels[$field])) {
            return $labels[$field];
        }
        if (preg_match('/^feature\.(\d+)\.(\d+)$/D', $field, $match)) {
            return \Pixel\Module\Audit\Presentation\I18n::trans('Feature #%feature% - value #%value%', ['%feature%' => $match[1],'%value%' => $match[2]]);
        }
        if (preg_match('/^tag\.(\d+)$/D', $field, $match)) {
            return 'Tag #' . $match[1];
        }
        if (preg_match('/^pack\.(\d+)\.(\d+)$/D', $field, $match)) {
            return \Pixel\Module\Audit\Presentation\I18n::trans('Pack - product #%product%, combination #%combination%', ['%product%' => $match[1],'%combination%' => $match[2]]);
        }
        if (preg_match('/^supplier\.(\d+)\.(\d+)\.(reference|price|currency)$/D', $field, $match)) {
            $label = ['reference' => 'Reference','price' => 'Price (tax excl.)','currency' => 'Currency'][$match[3]];
            return \Pixel\Module\Audit\Presentation\I18n::trans('Supplier #%supplier%, combination #%combination% - %field%', [
                '%supplier%' => $match[1],'%combination%' => $match[2],'%field%' => \Pixel\Module\Audit\Presentation\I18n::trans($label)]);
        }
        return null;
    }

    public function snapshot(Subject $subject): Snapshot
    {
        $db = Db::getInstance();
        $table = ['product' => 'product', 'customer' => 'customer', 'combination' => 'product_attribute', 'profile' => 'profile'][$this->type];
        $primary = $this->type === 'combination' ? 'id_product_attribute' : 'id_' . $this->type;
        $exists = (bool) $db->getValue('SELECT `' . $primary . '` FROM `' . _DB_PREFIX_ . $table . '` WHERE `' . $primary . '` = ' . $subject->id, false);
        $this->assertReadable($db);
        if (!$exists) {
            return new Snapshot(false);
        }
        if (in_array($this->type, ['product', 'combination'], true) && $subject->shopId > 0) {
            $associated = $db->getValue('SELECT `' . $primary . '` FROM `' . _DB_PREFIX_ . $table . '_shop` WHERE `' . $primary . '` = ' . $subject->id . ' AND id_shop = ' . $subject->shopId, false);
            $this->assertReadable($db);
            if (!$associated) {
                return new Snapshot(false);
            }
        }
        $fields = [];
        $queries = match ($this->type) {
            'product' => [
                'categories' => ['Categories', 'SELECT id_category AS value FROM `' . _DB_PREFIX_ . 'category_product` WHERE id_product = ' . $subject->id],
                'carriers' => ['Carriers', 'SELECT id_carrier_reference AS value FROM `' . _DB_PREFIX_ . 'product_carrier` WHERE id_product = ' . $subject->id . ' AND id_shop = ' . $subject->shopId],
                'attachments' => ['Associated documents', 'SELECT id_attachment AS value FROM `' . _DB_PREFIX_ . 'product_attachment` WHERE id_product = ' . $subject->id],
                'accessories' => ['Accessories', 'SELECT id_product_2 AS value FROM `' . _DB_PREFIX_ . 'accessory` WHERE id_product_1 = ' . $subject->id],
            ],
            'profile' => [
                'admin_permissions' => ['Back-office page permissions', 'SELECT r.slug AS value FROM `' . _DB_PREFIX_ . 'access` a INNER JOIN `' . _DB_PREFIX_ . 'authorization_role` r ON r.id_authorization_role = a.id_authorization_role WHERE a.id_profile = ' . $subject->id],
                'module_permissions' => ['Module permissions', 'SELECT r.slug AS value FROM `' . _DB_PREFIX_ . 'module_access` a INNER JOIN `' . _DB_PREFIX_ . 'authorization_role` r ON r.id_authorization_role = a.id_authorization_role WHERE a.id_profile = ' . $subject->id],
            ],
            'customer' => ['groups' => ['Groups', 'SELECT id_group AS value FROM `' . _DB_PREFIX_ . 'customer_group` WHERE id_customer = ' . $subject->id]],
            'combination' => [
                'attributes' => ['Attributes', 'SELECT id_attribute AS value FROM `' . _DB_PREFIX_ . 'product_attribute_combination` WHERE id_product_attribute = ' . $subject->id],
                'images' => ['Combination images', 'SELECT id_image AS value FROM `' . _DB_PREFIX_ . 'product_attribute_image` WHERE id_product_attribute = ' . $subject->id],
            ],
            default => throw new \LogicException('Unsupported relation provider.'),
        };
        foreach ($queries as $key => [$label, $sql]) {
            $rows = $db->executeS($sql, true, false);
            if ($rows === false) {
                throw new \RuntimeException('Cannot read audit associations.');
            }
            $fields[$key] = new Field($this->type === 'profile' ? array_column($rows, 'value') : array_map('intval', array_column($rows, 'value')), $label, 'set');
        }
        if ($this->type === 'product') {
            // Product form handlers also write these associations through direct SQL.
            $features = $db->executeS('SELECT fp.id_feature, fp.id_feature_value, fv.custom, fvl.id_lang, fvl.value FROM `' . _DB_PREFIX_ . 'feature_product` fp LEFT JOIN `' . _DB_PREFIX_ . 'feature_value` fv ON fv.id_feature_value = fp.id_feature_value LEFT JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl ON fvl.id_feature_value = fp.id_feature_value WHERE fp.id_product = ' . $subject->id, true, false);
            $tags = $db->executeS('SELECT t.id_tag, t.id_lang, t.name FROM `' . _DB_PREFIX_ . 'product_tag` pt INNER JOIN `' . _DB_PREFIX_ . 'tag` t ON t.id_tag = pt.id_tag WHERE pt.id_product = ' . $subject->id, true, false);
            if ($features === false || $tags === false) {
                throw new \RuntimeException('Cannot read product features or tags.');
            }
            $featureValues = [];
            foreach ($features as $row) {
                if (!empty($row['custom'])) {
                    $this->customFeatures[$subject->key()][$row['id_feature_value']] = true;
                }
                $featureValues[] = $row['id_feature'] . ':' . $row['id_feature_value'];
                if ($row['id_lang'] !== null) {
                    $key = 'feature.' . $row['id_feature'] . '.' . $row['id_feature_value'] . '.lang_' . $row['id_lang'];
                    $fields[$key] = new Field($row['value'], 'Feature #' . $row['id_feature'] . ' - value #' . $row['id_feature_value'] . ' [language ' . $row['id_lang'] . ']');
                }
            }
            $fields['features'] = new Field($featureValues, 'Features (feature:value)', 'set');
            $fields['tags'] = new Field(array_map('intval', array_column($tags, 'id_tag')), 'Associated tags', 'set');
            foreach ($tags as $row) {
                $fields['tag.' . $row['id_tag'] . '.lang_' . $row['id_lang']] = new Field($row['name'], 'Tag #' . $row['id_tag'] . ' [language ' . $row['id_lang'] . ']');
            }
            $packs = $db->executeS('SELECT id_product_item, id_product_attribute_item, quantity FROM `' . _DB_PREFIX_ . 'pack` WHERE id_product_pack = ' . $subject->id, true, false);
            $suppliers = $db->executeS('SELECT id_supplier, id_product_attribute, product_supplier_reference, product_supplier_price_te, id_currency FROM `' . _DB_PREFIX_ . 'product_supplier` WHERE id_product = ' . $subject->id, true, false);
            if ($packs === false || $suppliers === false) {
                throw new \RuntimeException('Cannot read product business associations.');
            }
            foreach ($packs as $row) {
                $key = 'pack.' . $row['id_product_item'] . '.' . $row['id_product_attribute_item'];
                $fields[$key] = new Field($row['quantity'], 'Pack - product #' . $row['id_product_item'] . ', combination #' . $row['id_product_attribute_item'], 'integer');
            }
            foreach ($suppliers as $row) {
                $prefix = 'supplier.' . $row['id_supplier'] . '.' . $row['id_product_attribute'] . '.';
                $label = 'Supplier #' . $row['id_supplier'] . ', combination #' . $row['id_product_attribute'];
                $fields[$prefix . 'reference'] = new Field($row['product_supplier_reference'], $label . ' - reference');
                $fields[$prefix . 'price'] = new Field($row['product_supplier_price_te'], $label . ' - price (tax excl.)', 'decimal');
                $fields[$prefix . 'currency'] = new Field($row['id_currency'], $label . ' - currency', 'integer');
            }
        }
        return new Snapshot(true, $fields);
    }
    private function assertReadable(Db $db): void
    {
        if ((int) $db->getNumberError() !== 0) {
            throw new \RuntimeException('Cannot read audit relations.');
        }
    }

}
