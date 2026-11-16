<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Core;

final class ShopScope
{
    /** A group/all-shop context alone does not enforce employee shop assignments. */
    public static function allowed(array $contextShops, array $employeeShops, bool $superAdmin): array
    {
        $contextShops = array_filter(array_map('intval', $contextShops), static fn ($id) => $id > 0);
        return array_values(array_unique($superAdmin ? $contextShops : array_intersect($contextShops, array_map('intval', $employeeShops))));
    }
}
