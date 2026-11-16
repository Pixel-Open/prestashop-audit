<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Configuration;

use Db;
use Shop;
use Pixel\Module\Audit\Api\Subject;

final class ConfigurationBridge
{
    public function before(array $params, \Pixel_audit $module): array
    {
        if (!(new Settings())->enabled('configuration') || ($audit = $module->audit()) === null) {
            return [];
        }
        $operation = $params['operation'];
        $rows = [];
        if ($operation === 'delete_id') {
            // Metadata only: do not read an unlisted value even when deleting by database ID.
            $row = Db::getInstance()->getRow('SELECT name, id_shop, id_shop_group FROM `' . _DB_PREFIX_ . 'configuration` WHERE id_configuration = ' . (int) ($params['id'] ?? 0), false);
            if (!$row) {
                return [];
            }
            $key = $row['name'];
            $rows[] = $row;
        } else {
            $key = $params['key'] ?? null;
        }
        if (!is_string($key)) {
            return [];
        }
        $parameter = $module->registry()->configurationParameters()[$key] ?? null;
        if ($parameter === null || !(new Settings())->tracks($parameter)) {
            return [];
        }
        $lock = 'pixel_audit_cfg_' . substr(hash('sha256', (defined('_DB_NAME_') ? (string) constant('_DB_NAME_') : '') . _DB_PREFIX_ . $key), 0, 40);
        $locked = (int) Db::getInstance()->getValue('SELECT GET_LOCK("' . pSQL($lock) . '", 5)', false) === 1;
        if (!$locked) {
            \Pixel\Module\Audit\Core\Diagnostics::report(new \RuntimeException('Configuration audit lock unavailable.'));
        }
        try {
            if ($operation === 'delete_all') {
                $rows = Db::getInstance()->executeS('SELECT DISTINCT id_shop, id_shop_group FROM `' . _DB_PREFIX_ . 'configuration` WHERE name = "' . pSQL($key) . '"', true, false);
                if ($rows === false) {
                    throw new \RuntimeException('Cannot read deleted parameter scopes.');
                }
            } elseif ($operation !== 'delete_id') {
                $shop = $params['shop'] ?? null;
                $group = $params['group'] ?? null;
                if ($operation === 'update') {
                    if ($shop === null || !Shop::isFeatureActive()) {
                        $shop = Shop::getContextShopID(true);
                    }
                    if ($group === null || !Shop::isFeatureActive()) {
                        $group = Shop::getContextShopGroupID(true);
                    }
                } elseif ($operation === 'delete_context') {
                    if (Shop::getContext() === Shop::CONTEXT_ALL) {
                        if ($locked) {
                            $this->release($lock);
                        } return [];
                    }
                    $group ??= Shop::getContextShopGroupID(true);
                    if ($shop === null && Shop::getContext() === Shop::CONTEXT_SHOP) {
                        $shop = Shop::getContextShopID(true);
                    }
                }
                $rows[] = ['id_shop' => (int) $shop, 'id_shop_group' => (int) $group];
            }
            $provider = $module->registry()->providers()['configuration.selected'];
            if (!$provider instanceof ConfigurationProvider) {
                throw new \LogicException('Configuration provider unavailable.');
            }
            $state = [];
            foreach ($rows as $row) {
                foreach ($this->subjects((int) $row['id_shop'], (int) $row['id_shop_group']) as $subject) {
                    $before = $provider->snapshotParameter($subject, $parameter);
                    if (!empty($params['reserve_initial'])) {
                        $audit->recordFieldSnapshots($subject, $provider->name(), $before, $before);
                    }
                    $state[$subject->key()] = [$subject, $parameter, $before];
                }
            }
            if (!$state) {
                if ($locked) {
                    $this->release($lock);
                } return [];
            }
            return ['entries' => array_values($state), 'lock' => $locked ? $lock : null];
        } catch (\Throwable $error) {
            if ($locked) {
                $this->release($lock);
            }
            throw $error;
        }
    }

    public function after(array $state, \Pixel_audit $module): void
    {
        try {
            $audit = $module->audit();
            $provider = $module->registry()->providers()['configuration.selected'];
            if ($audit === null || !$provider instanceof ConfigurationProvider) {
                return;
            }
            foreach ($state['entries'] as [$subject, $parameter, $before]) {
                $audit->recordFieldSnapshots($subject, $provider->name(), $before, $provider->snapshotParameter($subject, $parameter));
            }
        } finally {
            if ($state['lock'] !== null) {
                $this->release($state['lock']);
            }
        }
    }

    private function release(string $lock): void
    {
        try {
            Db::getInstance()->getValue('SELECT RELEASE_LOCK("' . pSQL($lock) . '")', false);
        } catch (\Throwable $error) {
            \Pixel\Module\Audit\Core\Diagnostics::report($error);
        }
    }

    private function subjects(int $shop, int $group): array
    {
        if ($shop > 0) {
            return [new Subject('configuration_shop', $shop, $shop, 'configuration_change', 1)];
        }
        if ($group <= 0) {
            return [new Subject('configuration', 1, 0, 'configuration_change', 1)];
        }
        $rows = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE id_shop_group = ' . $group, true, false);
        if ($rows === false) {
            throw new \RuntimeException('Cannot resolve configuration group.');
        }
        return array_map(static fn ($row) => new Subject('configuration_group', $group, (int) $row['id_shop'], 'configuration_change', 1), $rows);
    }
}
