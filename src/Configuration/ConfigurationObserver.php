<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Configuration;

use Db;
use Pixel\Module\Audit\Api\Subject;
use Pixel\Module\Audit\Core\Audit;

final class ConfigurationObserver
{
    private ?int $cycle = null;

    /** Explicit whole-scope snapshot for integrations performing their own SQL writes. */
    public function capture(Audit $audit, array $shops): void
    {
        if ($this->cycle === $audit->collectionCycle() || !(new Settings())->enabled('configuration')) {
            return;
        }
        $this->cycle = $audit->collectionCycle();
        $audit->watch(new Subject('configuration', 1, 0, 'configuration_change', 1));
        $shops = array_unique(array_filter(array_map('intval', $shops), static fn ($id) => $id > 0));
        if (!$shops) {
            return;
        }
        $rows = Db::getInstance()->executeS('SELECT id_shop, id_shop_group FROM `' . _DB_PREFIX_ . 'shop` WHERE id_shop IN (' . implode(',', $shops) . ')', true, false);
        if ($rows === false) {
            throw new \RuntimeException('Cannot resolve configuration shop scopes.');
        }
        foreach ($rows as $row) {
            $shopId = (int) $row['id_shop'];
            $audit->watch(new Subject('configuration_shop', $shopId, $shopId, 'configuration_change', 1));
            // A group change is repeated per authorized shop so read permissions remain exact.
            $audit->watch(new Subject('configuration_group', (int) $row['id_shop_group'], $shopId, 'configuration_change', 1));
        }
    }
}
