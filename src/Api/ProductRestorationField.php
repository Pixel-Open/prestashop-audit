<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

use Pixel\Module\Audit\Core\Comparator;
use Pixel\Module\Audit\Restoration\RestoreException;

/** Immutable allowlist entry for an existing Product ObjectModel property. Never built from HTTP input. */
final class ProductRestorationField
{
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $translated = false,
        public readonly string $scope = 'global',
        public readonly bool $nullable = false,
        public readonly ?array $choices = null,
        public readonly ?int $minimum = null,
        public readonly ?int $maximum = null,
        public readonly ?int $maxLength = null,
        public readonly bool $html = false,
        public readonly bool $required = false,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly ?int $maxBytes = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field)
            || preg_match(Field::SENSITIVE_KEY, $field)
            || $label === '' || strlen($label) > 200 || !in_array($type, ['text', 'integer', 'boolean', 'decimal'], true)
            || !in_array($scope, ['global', 'shop'], true) || ($translated && $scope !== 'shop')
            || ($minimum !== null && $maximum !== null && $minimum > $maximum)
            || ($maxBytes !== null && ($type !== 'text' || $maxBytes < 1 || $maxBytes > 262144))
            || ($maxLength !== null && $maxLength < 1) || ($html && $type !== 'text')
            || (($precision === null) !== ($scale === null))
            || ($precision !== null && ($type !== 'decimal' || $precision < 1 || $precision > 30 || $scale < 0 || $scale > $precision))) {
            throw new \InvalidArgumentException('Invalid product restoration field declaration.');
        }
        if ($choices !== null) {
            foreach ($choices as $choice) {
                $this->normalize($choice, false);
            }
        }
    }

    public function describe(string $key): ?array
    {
        if (!$this->translated) {
            return $key === $this->field ? $this->description(0) : null;
        }
        if (!preg_match('/^' . preg_quote($this->field, '/') . '\.lang_([1-9][0-9]*)$/D', $key, $match)) {
            return null;
        }
        $language = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        return $language ? $this->description($language) : null;
    }

    public function normalize(mixed $value, bool $checkChoices = true): mixed
    {
        if ($value === null) {
            if (!$this->nullable) {
                throw new RestoreException('A null value cannot be restored for this field.', 400);
            }
            return null;
        }
        if ($this->type === 'text') {
            if (!is_string($value) || strlen($value) > ($this->maxBytes ?? 262144) || ($this->required && trim($value) === '')
                || ($this->maxLength !== null && mb_strlen($value, 'UTF-8') > $this->maxLength)) {
                throw new RestoreException('Invalid or oversized historical text.', 400);
            }
        } elseif ($this->type === 'integer') {
            if ((!is_int($value) && (!is_string($value) || !preg_match('/^-?\d+$/D', $value)))
                || ($integer = filter_var($value, FILTER_VALIDATE_INT)) === false) {
                throw new RestoreException('Invalid historical integer.', 400);
            }
            $value = $integer;
        } elseif ($this->type === 'boolean') {
            if (!in_array($value, [false, true, 0, 1, '0', '1'], true)) {
                throw new RestoreException('Invalid historical boolean.', 400);
            }
            $value = (bool) $value;
        } else {
            if (!is_string($value) && !is_int($value)) {
                throw new RestoreException('Decimals must be exact strings.', 400);
            }
            $value = (string) $value;
            if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
                throw new RestoreException('Invalid historical decimal.', 400);
            }
            $normalized = (new Comparator())->sanitize(new Snapshot(true, ['value' => new Field($value, 'Value', 'decimal')]));
            $value = $normalized->fields['value']->value;
            if ($this->precision !== null) {
                $parts = explode('.', ltrim($value, '-'), 2);
                if (strlen(ltrim($parts[0], '0')) > $this->precision - $this->scale || strlen($parts[1] ?? '') > $this->scale) {
                    throw new RestoreException('The decimal exceeds the allowed precision.', 400);
                }
            }
        }
        if ($this->type !== 'text' && (($this->minimum !== null && $value < $this->minimum) || ($this->maximum !== null && $value > $this->maximum))) {
            throw new RestoreException('The historical value is outside the allowed range.', 400);
        }
        if ($checkChoices && $this->choices !== null) {
            $allowed = array_map(fn ($choice) => $this->normalize($choice, false), $this->choices);
            if (!in_array($value, $allowed, true)) {
                throw new RestoreException('The historical value is no longer an allowed choice.', 400);
            }
        }
        return $value;
    }

    private function description(int $language): array
    {
        return ['field' => $this->field, 'language' => $language, 'label' => $this->label . ($language ? ' [language ' . $language . ']' : ''),
            'scope' => $this->scope, 'type' => $this->type];
    }
}
