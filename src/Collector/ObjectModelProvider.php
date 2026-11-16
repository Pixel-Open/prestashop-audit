<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Collector;

use Db;
use ObjectModel;
use Pixel\Module\Audit\Api\Field;
use Pixel\Module\Audit\Api\ProviderInterface;
use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Api\Subject;

/** Reads the master connection with SQL cache disabled, including all translations. */
final class ObjectModelProvider implements ProviderInterface, \Pixel\Module\Audit\Api\FieldLabelProviderInterface, \Pixel\Module\Audit\Api\ResettableProviderInterface
{
    private array $excludedPrefixes = [];
    private array $labels = [];
    private array $initialDefaults = [];

    private const ALLOWED = [
        'Customer' => ['firstname', 'lastname', 'email', 'company', 'siret', 'active', 'id_default_group', 'id_shop', 'id_shop_group', 'newsletter', 'optin', 'deleted'],
        'Address' => ['id_customer', 'id_country', 'id_state', 'id_manufacturer', 'id_supplier', 'id_warehouse', 'alias', 'company', 'lastname', 'firstname', 'address1', 'address2', 'postcode', 'city', 'phone', 'phone_mobile', 'vat_number', 'dni', 'deleted'],
        'Employee' => ['firstname', 'lastname', 'email', 'id_profile', 'active', 'id_lang'],
        'Order' => ['id_customer', 'id_address_delivery', 'id_address_invoice', 'id_carrier', 'current_state', 'shipping_number', 'total_paid', 'total_paid_real', 'total_paid_tax_incl', 'total_paid_tax_excl', 'total_products', 'total_products_wt', 'total_shipping', 'total_shipping_tax_incl', 'total_shipping_tax_excl', 'total_discounts', 'total_discounts_tax_incl', 'total_discounts_tax_excl', 'valid', 'id_currency', 'conversion_rate'],
        'OrderCarrier' => ['id_order', 'id_carrier', 'id_order_invoice', 'weight', 'shipping_cost_tax_excl', 'shipping_cost_tax_incl', 'tracking_number'],
        'OrderPayment' => ['order_reference', 'id_currency', 'amount', 'payment_method', 'conversion_rate', 'id_employee'],
        'OrderDetail' => ['id_order', 'id_order_invoice', 'id_shop', 'product_id', 'product_attribute_id', 'product_name', 'product_reference', 'product_quantity', 'product_quantity_return', 'product_quantity_refunded', 'product_quantity_reinjected', 'product_price', 'reduction_percent', 'reduction_amount', 'reduction_amount_tax_incl', 'reduction_amount_tax_excl', 'unit_price_tax_incl', 'unit_price_tax_excl', 'total_price_tax_incl', 'total_price_tax_excl', 'total_shipping_price_tax_excl', 'total_shipping_price_tax_incl', 'total_refunded_tax_excl', 'total_refunded_tax_incl', 'original_product_price'],
        'OrderSlip' => ['id_customer', 'id_order', 'conversion_rate', 'total_products_tax_excl', 'total_products_tax_incl', 'total_shipping_tax_excl', 'total_shipping_tax_incl', 'amount', 'shipping_cost', 'shipping_cost_amount', 'partial', 'order_slip_type'],
        'ProductDownload' => ['id_product', 'display_filename', 'nb_downloadable', 'date_expiration', 'nb_days_accessible', 'active', 'is_shareable'],
        'Attachment' => ['file_name', 'file_size', 'mime', 'name', 'description'],
        'OrderHistory' => ['id_order', 'id_order_state', 'id_employee'],
    ];

    public function __construct(
        private string $class,
        private string $type,
        private string $familyName,
        private string $providerName,
        private ?string $fieldPrefix = null,
    ) {
    }

    public function conflictsWith(self $other): bool
    {
        return $this->fieldPrefix !== null && $other->fieldPrefix !== null && $this->class === $other->class && $this->type === $other->type
            && (str_starts_with($this->fieldPrefix, $other->fieldPrefix) || str_starts_with($other->fieldPrefix, $this->fieldPrefix));
    }

    public function excludeOwnedFields(self $owner): void
    {
        if ($this->fieldPrefix === null && $owner->fieldPrefix !== null && $owner->class === $this->class && $owner->type === $this->type) {
            $this->excludedPrefixes[$owner->fieldPrefix] = true;
        }
    }

    public function setFieldLabels(array $labels): void
    {
        $this->labels = $labels;
    }

    public function ownsRestorationProperty(string $field, bool $native): bool
    {
        if ($this->class !== 'Product' || $this->type !== 'product') {
            return false;
        }
        return $native ? $this->providerName === 'native.product' && $this->fieldPrefix === null
            : $this->fieldPrefix !== null && preg_match('/^[a-z][a-z0-9_]*_$/D', $this->fieldPrefix) === 1 && str_starts_with($field, $this->fieldPrefix);
    }

    public function name(): string
    {
        return $this->providerName;
    }
    public function family(): string
    {
        return $this->familyName;
    }
    public function supports(Subject $subject): bool
    {
        return $subject->type === $this->type;
    }

    public function captureProductDefaults(int $product): void
    {
        $spec = $this->initialFlagSpec();
        if ($spec === null || isset($this->initialDefaults[$product])) {
            return;
        }
        [$table, $primary, $flag] = $spec;
        $rows = Db::getInstance()->executeS('SELECT s.`' . $primary . '`, s.id_shop, s.`' . $flag . '` FROM `' . _DB_PREFIX_ . $table . '_shop` s INNER JOIN `' . _DB_PREFIX_ . $table . '` b ON b.`' . $primary . '` = s.`' . $primary . '` WHERE b.id_product = ' . $product, true, false);
        if ($rows === false) {
            throw new \RuntimeException('Cannot read initial product flags.');
        }
        $defaults = [];
        foreach ($rows as $row) {
            $defaults[$row[$primary] . ':' . $row['id_shop']] = $row[$flag];
        }
        $this->initialDefaults[$product] = $defaults;
    }

    public function initialSnapshot(Subject $subject, Snapshot $snapshot): Snapshot
    {
        $spec = $this->initialFlagSpec();
        $key = $subject->id . ':' . $subject->shopId;
        $defaults = $this->initialDefaults[$subject->rootId ?? 0] ?? [];
        if ($spec === null || !array_key_exists($key, $defaults) || !isset($snapshot->fields[$spec[2]])) {
            return $snapshot;
        }
        // Legacy editors clear selection flags via SQL before the corresponding update hook.
        $fields = $snapshot->fields;
        $field = $fields[$spec[2]];
        $fields[$spec[2]] = new Field($defaults[$key], $field->label, $field->type);
        return new Snapshot($snapshot->exists, $fields);
    }

    private function initialFlagSpec(): ?array
    {
        return match ($this->providerName) {
            'native.combination' => ['product_attribute', 'id_product_attribute', 'default_on'],
            'native.image' => ['image', 'id_image', 'cover'],
            default => null,
        };
    }

    public function reset(): void
    {
        $this->initialDefaults = [];
    }

    public function snapshot(Subject $subject): Snapshot
    {
        $definition = ObjectModel::getDefinition($this->class);
        $table = $definition['table'];
        $primary = $definition['primary'];
        $db = Db::getInstance();
        $base = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . $table . '` WHERE `' . $primary . '` = ' . $subject->id, false);
        if (!$base) {
            if ((int) $db->getNumberError() !== 0) {
                throw new \RuntimeException('Cannot read audit object.');
            }
            return new Snapshot(false);
        }
        $shop = [];
        if (\Shop::isTableAssociated($table) && $subject->shopId > 0) {
            $shop = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . $table . '_shop` WHERE `' . $primary . '` = ' . $subject->id . ' AND id_shop = ' . $subject->shopId, false) ?: [];
            if ((int) $db->getNumberError() !== 0) {
                throw new \RuntimeException('Cannot read audit shop association.');
            }
        }
        if (\Shop::isTableAssociated($table) && $subject->shopId > 0 && !$shop) {
            return new Snapshot(false);
        }
        $languages = [];
        if (!empty($definition['multilang'])) {
            $languages = $db->executeS('SELECT * FROM `' . _DB_PREFIX_ . $table . '_lang` WHERE `' . $primary . '` = ' . $subject->id
                . (!empty($definition['multilang_shop']) ? ' AND id_shop = ' . $subject->shopId : '') . ' ORDER BY id_lang', true, false);
            if ($languages === false) {
                throw new \RuntimeException('Cannot read translated audit fields.');
            }
        }
        $fields = [];
        foreach ($definition['fields'] as $name => $spec) {
            if (in_array($name, ['date_add', 'date_upd', 'last_passwd_gen', 'stats_date_from', 'stats_date_to', 'indexed', 'cache_is_pack', 'cache_has_attachments', 'cache_default_attribute'], true)) {
                continue;
            }
            if ($this->fieldPrefix !== null && !str_starts_with($name, $this->fieldPrefix)) {
                continue;
            }
            if ($this->fieldPrefix === null && array_filter(array_keys($this->excludedPrefixes), static fn ($prefix) => str_starts_with($name, $prefix))) {
                continue;
            }
            if (isset(self::ALLOWED[$this->class]) && !in_array($name, self::ALLOWED[$this->class], true)) {
                continue;
            }
            $label = $this->label($name);
            $type = match ($spec['type'] ?? null) {
                ObjectModel::TYPE_INT => 'integer', ObjectModel::TYPE_BOOL => 'boolean',
                ObjectModel::TYPE_FLOAT => 'decimal', default => 'text',
            };
            if (!empty($spec['lang'])) {
                foreach ($languages as $row) {
                    if (array_key_exists($name, $row)) {
                        $fields[$name . '.lang_' . $row['id_lang']] = new Field($row[$name], $label . ' [language ' . $row['id_lang'] . ']', $type);
                    }
                }
            } else {
                $row = !empty($spec['shop']) ? $shop + $base : $base;
                if (array_key_exists($name, $row)) {
                    $fields[$name] = new Field($row[$name], $label, $type);
                }
            }
        }
        return new Snapshot(true, $fields);
    }

    public function fieldLabel(string $field): ?string
    {
        if ($this->fieldPrefix !== null && (!str_starts_with($field, $this->fieldPrefix) || !isset($this->labels[$field]))) {
            return null;
        }
        $native = \Pixel\Module\Audit\Restoration\ProductFields::definition()->fields[$field] ?? null;
        if ($this->class === 'Product' && $this->fieldPrefix === null && $native !== null) {
            return $native->label;
        }
        return $this->label($field);
    }

    private function label(string $field): string
    {
        if (isset($this->labels[$field])) {
            return (string) $this->labels[$field];
        }
        return [
            'name' => 'Name', 'reference' => 'Reference', 'price' => 'Price (tax excl.)', 'active' => 'Active',
            'wholesale_price' => 'Cost price (tax excl.)', 'firstname' => 'First name', 'lastname' => 'Name',
            'id_category_default' => 'Default category', 'quantity' => 'Quantity',
            'product_quantity' => 'Ordered quantity', 'product_quantity_refunded' => 'Refunded quantity',
            'total_refunded_tax_incl' => 'Refunded total (tax incl.)', 'unit_price_tax_excl' => 'Unit price (tax excl.)',
            'unit_price_tax_incl' => 'Unit price (tax incl.)', 'amount' => 'Amount', 'payment_method' => 'Payment method',
            'cover' => 'Cover image', 'legend' => 'Caption', 'position' => 'Position',
            'current_state' => 'Status', 'id_default_group' => 'Default group',
        ][$field] ?? str_replace('_', ' ', $field);
    }
}
