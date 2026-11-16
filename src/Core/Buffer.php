<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Core;

use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Api\Subject;

/** Request-local state. No time-window merging and no cross-user shared buffer. */
final class Buffer
{
    private array $items = [];
    private array $operations = [];

    public function __construct(private Comparator $comparator)
    {
    }

    public function observe(Subject $subject, string $provider, string $family, Snapshot $before, Snapshot $after, ?string $operation = null): void
    {
        $key = ($operation ?? 'auto') . ':' . $subject->key() . ':' . $provider;
        if (!isset($this->items[$key])) {
            $root = $operation ?? ('auto:' . $subject->rootKey());
            $this->operations[$root] ??= bin2hex(random_bytes(16));
            $this->items[$key] = [
                'subject' => $subject, 'provider' => $provider, 'family' => $family,
                'operation' => $this->operations[$root],
                'before' => $this->comparator->sanitize($before),
            ];
        }
        $this->items[$key]['after'] = $this->comparator->sanitize($after);
    }

    public function events(): array
    {
        $events = [];
        foreach ($this->items as $item) {
            $before = $item['before'];
            $after = $item['after'];
            $changes = $this->comparator->diff($before, $after);
            if ((!$before->exists && !$after->exists) || ($before->exists === $after->exists && !$changes)) {
                continue;
            }
            $item['action'] = !$before->exists ? 'create' : (!$after->exists ? 'delete' : 'update');
            $item['changes'] = $changes;
            unset($item['before'], $item['after']);
            $events[] = $item;
        }
        return $events;
    }

    public function clear(): void
    {
        $this->items = [];
        $this->operations = [];
    }
}
