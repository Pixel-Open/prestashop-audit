<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Restoration;

/** Explicit writable surface. Historical field names never become arbitrary model properties. */
final class ProductFields
{
    private const LABELS = ['reference' => 'Reference', 'name' => 'Name', 'description' => 'Description',
        'description_short' => 'Short description', 'meta_title' => 'SEO title', 'meta_description' => 'SEO description'];

    public static function definition(): \Pixel\Module\Audit\Api\ProductRestorationDefinition
    {
        $fields = [];
        foreach (self::LABELS as $name => $label) {
            $fields[] = new \Pixel\Module\Audit\Api\ProductRestorationField(
                $name,
                $label,
                'text',
                $name !== 'reference',
                $name === 'reference' ? 'global' : 'shop',
                html: in_array($name, ['description', 'description_short'], true),
                required: $name === 'name'
            );
        }
        return new \Pixel\Module\Audit\Api\ProductRestorationDefinition('native.product', 'products', 'prestashop', '1', $fields);
    }

    /** Compatibility helpers; the declarative definition is the single writable surface. */
    public static function describe(string $key): ?array
    {
        $description = self::definition()->field($key)?->describe($key);
        return $description === null ? null : array_intersect_key($description, array_flip(['field', 'language', 'label']));
    }

    public static function available(array $changes): array
    {
        return self::definition()->available($changes);
    }
    public static function select(array $selected, array $changes): array
    {
        return self::definition()->select($selected, $changes);
    }
}
