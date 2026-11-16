<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

final class Snapshot
{
    /** @param array<mixed> $fields Validated at the API boundary. */
    public function __construct(public readonly bool $exists, public readonly array $fields = [])
    {
        foreach ($fields as $key => $field) {
            if (!is_string($key) || !$field instanceof Field) {
                throw new \InvalidArgumentException('Snapshots require named Field values.');
            }
        }
    }
}
