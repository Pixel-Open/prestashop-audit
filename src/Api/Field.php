<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

/** A stable field key belongs to one provider. Sensitive values are never retained. */
final class Field
{
    /** Field, array and configuration keys matching this pattern are never collected, tracked or restored. */
    public const SENSITIVE_KEY = '/passwd|password|token|secret|private_key|secure_key|salt|api_?key|access_?key|credential|auth_key|encryption_key|passphrase|(?:^|_)(?:key|pass|pwd|dsn)(?:_|$)|database_url/i';

    public function __construct(
        public readonly mixed $value,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $sensitive = false,
    ) {
        if (!in_array($type, ['text', 'integer', 'decimal', 'boolean', 'set', 'json'], true)) {
            throw new \InvalidArgumentException('Unknown audit field type.');
        }
    }
}
