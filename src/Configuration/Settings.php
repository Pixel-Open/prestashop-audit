<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Configuration;

use Configuration;

final class Settings
{
    public const KEY = 'PIXEL_AUDIT_SETTINGS';
    public const FAMILIES = [
        'products' => 'Products', 'combinations' => 'Combinations', 'prices' => 'Specific prices',
        'stocks' => 'Stock', 'categories' => 'Categories', 'customers' => 'Customers',
        'addresses' => 'Addresses', 'orders' => 'Orders', 'employees' => 'Employees and permissions',
        'configuration' => 'Selected configuration',
    ];

    /** Last decoded value, keyed by its raw JSON: a changed configuration is always decoded again. */
    private static ?string $raw = null;
    private static array $decoded = [];

    public function get(): array
    {
        $raw = (string) Configuration::getGlobalValue(self::KEY);
        if ($raw !== self::$raw) {
            $value = json_decode($raw, true);
            self::$decoded = is_array($value) ? $value : ['days' => 180, 'families' => [], 'retention' => []];
            self::$raw = $raw;
        }
        return self::$decoded;
    }

    public function enabled(string $family): bool
    {
        // New third-party families are disabled until explicitly enabled.
        return (bool) ($this->get()['families'][$family] ?? false);
    }

    public function loginHistoryEnabled(): bool
    {
        return !empty($this->get()['login_history']);
    }

    public function tracks(\Pixel\Module\Audit\Api\ConfigurationParameter $parameter): bool
    {
        return (bool) ($this->get()['configuration_keys'][$parameter->key] ?? $parameter->defaultEnabled);
    }

    public function days(string $family): int
    {
        $data = $this->get();
        return max(1, min(3650, (int) (($data['retention'][$family] ?? 0) ?: $data['days'])));
    }

    public function deleteOnUninstall(): bool
    {
        return !empty($this->get()['delete_on_uninstall']);
    }

    public function save(array $data): bool
    {
        return Configuration::updateGlobalValue(self::KEY, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function defaults(): array
    {
        return ['days' => 180, 'login_history' => false, 'families' => array_fill_keys(array_keys(self::FAMILIES), true), 'retention' => []];
    }
}
