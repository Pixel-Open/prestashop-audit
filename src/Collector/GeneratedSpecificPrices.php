<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Collector;

use Pixel\Module\Audit\Api\Field;
use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Api\Subject;
use Pixel\Module\Audit\Core\Comparator;

/** Compare generated rows by business scope; database identifiers change on every rule application. */
final class GeneratedSpecificPrices
{
    private array $initial = [];
    private array $handled = [];
    private \Closure $reader;

    public function __construct(?\Closure $reader = null)
    {
        $this->reader = $reader ?? fn (int $product): array => $this->read($product);
    }

    public function capture(int $product, ?string $operation = null): void
    {
        if (!isset($this->initial[$product])) {
            $this->initial[$product] = [($this->reader)($product), $operation];
        }
    }

    /** Returns snapshot pairs for the existing native provider and immutable event format. */
    public function changes(): array
    {
        $pairs = [];
        $handled = [];
        foreach ($this->initial as $product => [$before, $operation]) {
            $after = ($this->reader)($product);
            $old = $this->group($before);
            $new = $this->group($after);
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $scope) {
                $previous = $old[$scope] ?? [];
                $current = $new[$scope] ?? [];
                // Cancel identical rows first, including duplicate scopes, before pairing changed rows.
                foreach ($previous as $i => $entry) {
                    foreach ($current as $j => $candidate) {
                        if (!(new Comparator())->diff($entry['snapshot'], $candidate['snapshot'])) {
                            unset($previous[$i], $current[$j]);
                            break;
                        }
                    }
                }
                $previous = array_values($previous);
                $current = array_values($current);
                for ($i = 0, $count = max(count($previous), count($current)); $i < $count; ++$i) {
                    $pairs[] = [
                        ($current[$i] ?? $previous[$i])['subject'],
                        $previous[$i]['snapshot'] ?? new Snapshot(false),
                        $current[$i]['snapshot'] ?? new Snapshot(false),
                        $operation,
                    ];
                }
            }
            foreach (array_merge($before, $after) as $entry) {
                $handled[$entry['subject']->key()] = true;
            }
        }
        $this->handled = $handled;
        return $pairs;
    }

    public function handles(Subject $subject): bool
    {
        return isset($this->handled[$subject->key()]);
    }

    public function reset(): void
    {
        $this->initial = [];
        $this->handled = [];
    }

    private function group(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $scope = [$entry['subject']->rootId, $entry['subject']->shopId];
            foreach (['id_specific_price_rule', 'id_product_attribute', 'id_shop', 'id_shop_group', 'id_currency', 'id_country', 'id_group', 'id_customer', 'id_cart', 'from_quantity'] as $field) {
                $scope[] = (int) ($entry['snapshot']->fields[$field]->value ?? 0);
            }
            $groups[json_encode($scope, JSON_THROW_ON_ERROR)][] = $entry;
        }
        return $groups;
    }

    private function read(int $product): array
    {
        $db = \Db::getInstance();
        $rows = $db->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_product = ' . $product . ' AND id_specific_price_rule > 0 AND id_cart = 0 ORDER BY id_specific_price', true, false);
        $shops = $db->executeS('SELECT ps.id_shop, s.id_shop_group FROM `' . _DB_PREFIX_ . 'product_shop` ps INNER JOIN `' . _DB_PREFIX_ . 'shop` s ON s.id_shop = ps.id_shop WHERE ps.id_product = ' . $product, true, false);
        if ($rows === false || $shops === false) {
            throw new \RuntimeException('Cannot read generated specific prices.');
        }
        $definition = \ObjectModel::getDefinition('SpecificPrice');
        $provider = new ObjectModelProvider('SpecificPrice', 'specific_price', 'prices', 'native.specific_price');
        $entries = [];
        foreach ($rows as $row) {
            $fields = [];
            foreach ($definition['fields'] as $name => $spec) {
                if (!array_key_exists($name, $row) || in_array($name, ['date_add', 'date_upd'], true)) {
                    continue;
                }
                $type = match ($spec['type'] ?? null) {
                    \ObjectModel::TYPE_INT => 'integer', \ObjectModel::TYPE_BOOL => 'boolean',
                    \ObjectModel::TYPE_FLOAT => 'decimal', default => 'text',
                };
                $fields[$name] = new Field($row[$name], $provider->fieldLabel($name) ?? $name, $type);
            }
            $snapshot = (new Comparator())->sanitize(new Snapshot(true, $fields));
            $affected = (int) $row['id_shop'] > 0 ? [(int) $row['id_shop']] : array_map(
                static fn (array $shop): int => (int) $shop['id_shop'],
                array_filter($shops, static fn (array $shop): bool => !(int) $row['id_shop_group'] || (int) $shop['id_shop_group'] === (int) $row['id_shop_group'])
            );
            foreach ($affected as $shop) {
                $entries[] = ['subject' => new Subject('specific_price', (int) $row['id_specific_price'], $shop, 'product', $product), 'snapshot' => $snapshot];
            }
        }
        return $entries;
    }
}
