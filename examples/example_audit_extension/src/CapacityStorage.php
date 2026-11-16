<?php

declare(strict_types=1);
namespace ExampleAudit;
use Pixel\Module\Audit\Restoration\RestoreException;

use Db;
use Pixel\Module\Audit\Api\ProductRestorationDefinition;

/** Fixed storage adapter. SQL identifiers never come from history or HTTP. */
final class CapacityStorage implements \Pixel\Module\Audit\Api\RestorationStorageInterface
{
    public function subjectType(): string { return 'combination'; }
    public function tables(): array { return ['product_attribute', 'product_attribute_shop', 'example_audit_combination']; }
    public function afterCommit(int $product): void {}

    private const COLUMNS = ['capacity' => 'capacity', 'unit' => 'unit', 'label' => 'label'];

    public function lockProduct(int $product): void
    {
        foreach (['SELECT id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product = ' . $product . ' ORDER BY id_product_attribute FOR UPDATE',
            'SELECT s.id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute_shop` s INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` p ON p.id_product_attribute=s.id_product_attribute WHERE p.id_product=' . $product . ' FOR UPDATE',
            'SELECT c.id_product_attribute FROM `' . _DB_PREFIX_ . 'example_audit_combination` c INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` p ON p.id_product_attribute=c.id_product_attribute WHERE p.id_product=' . $product . ' FOR UPDATE'] as $sql) {
            if (Db::getInstance()->executeS($sql, true, false) === false) { throw new \RuntimeException('Cannot lock variant containers.'); }
        }
    }

    public function check(int $product, int $subject, int $shop, bool $lock): void
    {
        $rows = Db::getInstance()->executeS('SELECT p.id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute` p INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` s ON s.id_product_attribute=p.id_product_attribute WHERE p.id_product=' . $product . ' AND p.id_product_attribute=' . $subject . ' AND s.id_shop=' . $shop . ($lock ? ' FOR UPDATE' : ''), true, false);
        if (!$rows) { throw new RestoreException('Combination unavailable in this shop.', 404); }
    }

    public function read(int $subject, array $fields, ProductRestorationDefinition $definition): array
    {
        $row = Db::getInstance()->getRow('SELECT capacity, unit, label FROM `' . _DB_PREFIX_ . 'example_audit_combination` WHERE id_product_attribute=' . $subject, false);
        if (!$row) { throw new RestoreException('Capacity row missing; restoration unavailable.', 404); }
        $values = [];
        foreach ($fields as $field) { $values[$field] = $definition->field($field)->normalize($row[self::COLUMNS[$field]]); }
        return $values;
    }

    public function write(array $plan): void
    {
        $updates = [];
        foreach ($plan['rows'] as $row) {
            $value = $row['target'];
            $updates[] = '`' . self::COLUMNS[$row['key']] . '`=' . ($value === null ? 'NULL' : "'" . pSQL((string) $value, true) . "'");
        }
        if (!Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'example_audit_combination` SET ' . implode(',', $updates) . ' WHERE id_product_attribute=' . (int) $plan['subject'])) { throw new \RuntimeException('Cannot restore variant container.'); }
    }
}
