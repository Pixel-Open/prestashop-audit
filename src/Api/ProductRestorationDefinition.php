<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

use Pixel\Module\Audit\Restoration\RestoreException;

/** Versioned field contract for Product storage or an explicitly registered transactional storage adapter. */
final class ProductRestorationDefinition
{
    /** @var array<string, ProductRestorationField> */
    public readonly array $fields;

    public function __construct(
        public readonly string $provider,
        public readonly string $family,
        public readonly string $owner,
        public readonly string $revision,
        array $fields,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.]{0,95}$/D', $provider) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $family)
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $owner) || !preg_match('/^[a-zA-Z0-9_.-]{1,40}$/D', $revision) || !$fields
            || ($owner === 'prestashop' ? $provider !== 'native.product' : !str_starts_with($provider, $owner . '.'))) {
            throw new \InvalidArgumentException('Invalid product restoration provider declaration.');
        }
        $indexed = [];
        foreach ($fields as $field) {
            if (!$field instanceof ProductRestorationField || isset($indexed[$field->field])) {
                throw new \LogicException('Duplicate or invalid restoration property.');
            }
            $indexed[$field->field] = $field;
        }
        $this->fields = $indexed;
    }

    public function field(string $key): ?ProductRestorationField
    {
        $base = explode('.lang_', $key, 2)[0];
        $field = $this->fields[$base] ?? null;
        return $field !== null && $field->describe($key) !== null ? $field : null;
    }

    public function available(array $changes): array
    {
        $keys = [];
        foreach ($changes as $key => $change) {
            if (!is_string($key) || ($field = $this->field($key)) === null || ($change['type'] ?? '') !== $field->type
                || !array_key_exists('before', $change) || !array_key_exists('after', $change)) {
                continue;
            }
            try {
                if ($field->normalize($change['before']) !== $field->normalize($change['after'])) {
                    $keys[] = $key;
                }
            } catch (RestoreException $ignored) {
            } // Obsolete constraints fail closed in the UI; submitted fields are checked again.
        }
        return $keys;
    }

    public function select(array $selected, array $changes): array
    {
        if (!$selected || count($selected) > 30 || count(array_filter($selected, 'is_string')) !== count($selected)) {
            throw new RestoreException('Invalid field selection.', 400);
        }
        $selected = array_values(array_unique($selected));
        sort($selected, SORT_STRING);
        if (array_diff($selected, $this->available($changes))) {
            throw new RestoreException('A selected field cannot be restored.', 400);
        }
        return $selected;
    }

    public function assertModel(): void
    {
        $definition = \ObjectModel::getDefinition('Product');
        foreach ($this->fields as $field) {
            $spec = $definition['fields'][$field->field] ?? null;
            $type = match ($spec['type'] ?? null) {
                \ObjectModel::TYPE_INT => 'integer', \ObjectModel::TYPE_BOOL => 'boolean', \ObjectModel::TYPE_FLOAT => 'decimal',
                \ObjectModel::TYPE_STRING, \ObjectModel::TYPE_HTML => 'text', default => '',
            };
            if ($spec === null || $type !== $field->type || !property_exists('Product', $field->field)
                || !empty($spec['lang']) !== $field->translated || (!$field->translated && !empty($spec['shop']) !== ($field->scope === 'shop'))
                || ($field->nullable && empty($spec['allow_null'])) || ($field->html && ($spec['type'] ?? null) !== \ObjectModel::TYPE_HTML)) {
                throw new RestoreException('The product field definition has changed. Update the integration.', 503);
            }
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this, JSON_THROW_ON_ERROR));
    }
}
