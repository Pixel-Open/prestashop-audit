<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) { exit; }

class Example_audit_extension extends Module
{
    public function __construct()
    {
        $this->name = 'example_audit_extension';
        $this->version = '1.0.0';
        $this->author = 'Pixel Open';
        $this->tab = 'administration';
        $this->ps_versions_compliancy = ['min'=>'8.2.0', 'max'=>'9.99.99'];
        parent::__construct();
        $this->displayName = 'Audit API example';
        $this->description = 'Generic combination capacity and configuration integration.';
    }

    public function install(): bool
    {
        return parent::install() && $this->registerHook('actionPixelAuditRegisterProviders')
            && Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'example_audit_combination` (
                id_product_attribute INT UNSIGNED NOT NULL PRIMARY KEY,
                capacity DECIMAL(10,3) UNSIGNED NULL, unit VARCHAR(8) NULL, label VARCHAR(64) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function uninstall(): bool
    {
        // Keep business data on uninstall, just like the audit log itself.
        return parent::uninstall();
    }

    public function hookActionPixelAuditRegisterProviders(array $params): void
    {
        $registry = $params['registry'] ?? null;
        if (!$registry instanceof \Pixel\Module\Audit\Api\Registry) { return; }
        if (!in_array('addRestorationStorage', get_class_methods($registry), true)) { return; }
        require_once __DIR__ . '/src/CapacityProvider.php';
        require_once __DIR__ . '/src/CapacityStorage.php';
        $registry->add(new \ExampleAudit\CapacityProvider(), 'Example capacities');
        $registry->addRestorationStorage(\ExampleAudit\CapacityProvider::definition(), new \ExampleAudit\CapacityStorage());
        $registry->addConfigurationParameter(new \Pixel\Module\Audit\Api\ConfigurationParameter(
            'EXAMPLE_AUDIT_ENABLED', $this->name, 'Enable the example option', 'boolean', defaultEnabled: false
        ));
    }

    /** Called by trusted business code after its own employee and shop authorization. */
    public function saveCapacity(int $variant, ?string $capacity, ?string $unit, ?string $label): bool
    {
        if ($capacity !== null && !preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,3})?$/D', $capacity)) { throw new InvalidArgumentException('Invalid capacity.'); }
        if ($unit !== null && !in_array($unit, ['L','kg'], true)) { throw new InvalidArgumentException('Invalid unit.'); }
        if ($label !== null && (mb_strlen($label)>64 || !Validate::isCleanHtml($label))) { throw new InvalidArgumentException('Invalid label.'); }
        $shop = (int) Context::getContext()->shop->id;
        $product = (int) Db::getInstance()->getValue('SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product_attribute` p INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` s ON s.id_product_attribute=p.id_product_attribute WHERE p.id_product_attribute=' . $variant . ' AND s.id_shop=' . $shop, false);
        if (!$product) { throw new InvalidArgumentException('Combination unavailable in this shop.'); }
        if (Module::isEnabled('pixel_audit')) {
            $auditModule = Module::getInstanceByName('pixel_audit');
            if ($auditModule instanceof Pixel_audit && ($audit = $auditModule->audit()) !== null) {
                $audit->watch(new \Pixel\Module\Audit\Api\Subject('combination', $variant, $shop, 'product', $product));
            }
        }
        $sqlValue = static fn (?string $value): string => $value === null ? 'NULL' : "'" . pSQL($value, true) . "'";
        return Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'example_audit_combination` (id_product_attribute,capacity,unit,label) VALUES (' . $variant . ',' . $sqlValue($capacity) . ',' . $sqlValue($unit) . ',' . $sqlValue($label) . ') ON DUPLICATE KEY UPDATE capacity=VALUES(capacity),unit=VALUES(unit),label=VALUES(label)');
    }
}
