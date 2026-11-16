<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Configuration;

use PrestaShop\PrestaShop\Adapter\Configuration;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use Shop;

/** Symfony service decoration, not a legacy class override. Keep the concrete adapter type for existing consumers. */
final class ConfigurationDecorator extends Configuration
{
    private ?Shop $restrictedShop = null;

    public function __construct(private Configuration $inner)
    {
    }

    public function get($key, $default = null, ?ShopConstraint $shopConstraint = null): mixed
    {
        return $this->inner->get($key, $default, $shopConstraint);
    }

    public function has($key, ?ShopConstraint $shopConstraint = null): bool
    {
        return $this->inner->has($key, $shopConstraint);
    }

    public function set($key, $value, ?ShopConstraint $shopConstraint = null, array $options = [])
    {
        if ($shopConstraint === null && $this->restrictedShop !== null) {
            $shop = (int) $this->restrictedShop->id;
            $group = (int) $this->restrictedShop->id_shop_group;
        } else {
            $shopConstraint ??= $this->contextConstraint();
            $shop = $shopConstraint->getShopId()?->getValue() ?? 0;
            $group = $shopConstraint->getShopGroupId()?->getValue()
                ?? ($shop ? (int) Shop::getGroupFromShop($shop) : 0);
        }
        ConfigurationWriter::observe(
            ['operation' => 'update', 'key' => $key, 'shop' => $shop, 'group' => $group],
            fn () => $this->inner->set($key, $value, $shopConstraint, $options)
        );
        return $this;
    }

    public function remove($key)
    {
        ConfigurationWriter::observe(['operation' => 'delete_all', 'key' => $key], fn () => $this->inner->remove($key));
        return $this;
    }

    public function delete($key)
    {
        $this->remove($key);
    }

    public function deleteFromContext(string $key, ShopConstraint $shopConstraint): void
    {
        ConfigurationWriter::observe([
            'operation' => 'delete_given', 'key' => $key,
            'shop' => $shopConstraint->getShopId()?->getValue(),
            'group' => $shopConstraint->getShopGroupId()?->getValue(),
        ], fn () => $this->inner->deleteFromContext($key, $shopConstraint));
    }

    public function restrictUpdatesTo(Shop $shop)
    {
        $this->inner->restrictUpdatesTo($shop);
        $this->restrictedShop = $shop;
    }

    // Reads and auxiliary methods preserve the next decorator's behavior.
    public function all(?string $key = null): array
    {
        return $this->inner->all($key);
    }
    public function keys(): array
    {
        return $this->inner->keys();
    }
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator(iterator_to_array($this->inner->getIterator()));
    }
    public function count(): int
    {
        return $this->inner->count();
    }
    public function featureIsActive()
    {
        return $this->inner->featureIsActive();
    }
    public function combinationIsActive()
    {
        return $this->inner->combinationIsActive();
    }
    public function setTemporary(string $key, $value): void
    {
        // PS 8.2 exposes only the equivalent static in-memory setter.
        if (in_array('setTemporary', get_class_methods($this->inner), true)) {
            $this->inner->setTemporary($key, $value);
        } else {
            \Configuration::set($key, $value);
        }
    }

    private function contextConstraint(): ShopConstraint
    {
        return match (Shop::getContext()) {
            Shop::CONTEXT_SHOP => ShopConstraint::shop((int) Shop::getContextShopID()),
            Shop::CONTEXT_GROUP => ShopConstraint::shopGroup((int) Shop::getContextShopGroupID()),
            default => ShopConstraint::allShops(),
        };
    }
}
