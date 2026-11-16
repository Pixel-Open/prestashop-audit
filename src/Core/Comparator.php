<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Core;

use Pixel\Module\Audit\Api\Field;
use Pixel\Module\Audit\Api\Snapshot;

final class Comparator
{
    /** Values are normalized before comparison and before entering the buffer. */
    public function sanitize(Snapshot $snapshot): Snapshot
    {
        $fields = [];
        foreach ($snapshot->fields as $key => $field) {
            if ($field->sensitive || preg_match(Field::SENSITIVE_KEY, $key)) {
                continue;
            }
            if (strlen($key) > 190 || strlen($field->label) > 255) {
                throw new \LengthException('Audit field key or label is too long.');
            }
            $fields[$key] = new Field($this->normalize($field->value, $field->type), $field->label, $field->type);
        }
        return new Snapshot($snapshot->exists, $fields);
    }

    /** @return array<string, array{label:string, type:string, before:mixed, after:mixed}> */
    public function diff(Snapshot $before, Snapshot $after): array
    {
        $changes = [];
        $before = $this->sanitize($before);
        $after = $this->sanitize($after);
        foreach (array_unique(array_merge(array_keys($before->fields), array_keys($after->fields))) as $key) {
            $old = $before->fields[$key] ?? null;
            $new = $after->fields[$key] ?? null;
            if ($old?->value === $new?->value) {
                continue;
            }
            $field = $new ?? $old;
            $changes[$key] = ['label' => $field->label, 'type' => $field->type, 'before' => $old?->value, 'after' => $new?->value];
        }
        return $changes;
    }

    private function normalize(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($type === 'integer') {
            return (int) $value;
        }
        if ($type === 'boolean') {
            return (bool) $value;
        }
        if ($type === 'decimal') {
            // Decimal strings avoid float precision loss for prices and quantities.
            $value = (string) $value;
            if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
                throw new \InvalidArgumentException('Audit decimals must be plain decimal strings.');
            }
            $negative = str_starts_with($value, '-');
            $parts = explode('.', ltrim($value, '-'), 2);
            $integer = ltrim($parts[0], '0') ?: '0';
            $fraction = rtrim($parts[1] ?? '', '0');
            $value = $integer . ($fraction === '' ? '' : '.' . $fraction);
            return $negative && $value !== '0' ? '-' . $value : $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match(Field::SENSITIVE_KEY, $key)) {
                    unset($value[$key]);
                    continue;
                }
                $value[$key] = $this->normalize($item, 'json');
            }
            if ($type === 'set') {
                $value = array_values(array_unique($value, SORT_REGULAR));
                usort($value, static fn ($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            } elseif (!array_is_list($value)) {
                ksort($value);
            }
            return $value;
        }
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('Audit values must be JSON serializable scalars or arrays.');
        }
        if (is_string($value) && strlen($value) > 262144) {
            throw new \LengthException('Audit value exceeds 256 KiB.');
        }
        return $type === 'text' ? (string) $value : $value;
    }
}
