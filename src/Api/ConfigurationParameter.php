<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

/** An exact key, not a prefix or wildcard. No value is read during registration. */
final class ConfigurationParameter
{
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $translated = false,
        public readonly bool $defaultEnabled = false,
    ) {
        if (!preg_match('/^[A-Z][A-Z0-9_]{0,95}$/D', $key)
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $owner)
            || $label === '' || strlen($label) > 190
            || !in_array($type, ['text', 'boolean', 'integer', 'decimal', 'json', 'set'], true)) {
            throw new \InvalidArgumentException('Invalid configuration parameter declaration.');
        }
        if (str_starts_with($key, 'PIXEL_AUDIT_')
            || preg_match(Field::SENSITIVE_KEY, $key)) {
            throw new \InvalidArgumentException('Sensitive or internal configuration keys cannot be tracked.');
        }
    }

    public function field(mixed $stored, string $label): Field
    {
        $type = $this->type;
        if (in_array($type, ['json', 'set'], true) && is_string($stored)) {
            try {
                $stored = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                return new Field('[Invalid JSON value hidden]', $label, 'text');
            }
        }
        // Preserve malformed scalar values; malformed JSON is masked to avoid retaining nested secrets.
        if ($type === 'set' && !is_array($stored) && $stored !== null) {
            $type = 'text';
        }
        if ($type === 'decimal' && $stored !== null && !preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $stored)) {
            $type = 'text';
        }
        return new Field($stored, $label, $type);
    }
}
