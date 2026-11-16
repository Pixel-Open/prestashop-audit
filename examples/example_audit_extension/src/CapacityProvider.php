<?php

declare(strict_types=1);
namespace ExampleAudit;

use Pixel\Module\Audit\Api\{ProviderInterface, FieldLabelProviderInterface, Subject, Snapshot, Field, ProductRestorationDefinition, ProductRestorationField};

final class CapacityProvider implements ProviderInterface, FieldLabelProviderInterface
{
    public function name(): string { return 'example_audit_extension.capacity'; }
    public function family(): string { return 'example_capacities'; }
    public function supports(Subject $subject): bool { return $subject->type === 'combination'; }
    public function fieldLabel(string $field): ?string { return ['capacity'=>'Capacity', 'unit'=>'Unit', 'label'=>'Container label'][$field] ?? null; }

    public function snapshot(Subject $subject): Snapshot
    {
        $row = \Db::getInstance()->getRow('SELECT c.* FROM `' . _DB_PREFIX_ . 'example_audit_combination` c INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` p ON p.id_product_attribute=c.id_product_attribute WHERE c.id_product_attribute=' . $subject->id, false);
        if (!$row) { return new Snapshot(false); }
        return new Snapshot(true, [
            'capacity'=>new Field($row['capacity'], 'Capacity', 'decimal'),
            'unit'=>new Field($row['unit'], 'Unit'),
            'label'=>new Field($row['label'], 'Container label'),
        ]);
    }

    public static function definition(): ProductRestorationDefinition
    {
        return new ProductRestorationDefinition('example_audit_extension.capacity', 'example_capacities', 'example_audit_extension', '1', [
            new ProductRestorationField('capacity','Capacity','decimal',nullable:true,minimum:0,precision:10,scale:3),
            new ProductRestorationField('unit','Unit',nullable:true,choices:['L','kg'],maxLength:8),
            new ProductRestorationField('label','Container label',nullable:true,maxLength:64),
        ]);
    }
}
