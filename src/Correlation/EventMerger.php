<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Correlation;

/** Merge only serialized requests carrying the same authenticated capability. */
final class EventMerger
{
    public function merge(?array $existing, array $event): array
    {
        $initial = $existing === null ? $event['action'] !== 'create' : (bool) $existing['initial_exists'];
        $final = $event['action'] !== 'delete';
        $changes = $existing === null ? [] : json_decode($existing['changes'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($event['changes'] as $key => $change) {
            if (array_key_exists($key, $changes)) {
                $change['before'] = $changes[$key]['before'];
            } elseif (!$initial) {
                $change['before'] = null;
            }
            if ($change['before'] === $change['after']) {
                unset($changes[$key]);
            } else {
                $changes[$key] = $change;
            }
        }
        $visible = ($initial || $final) && ($initial !== $final || $changes !== []);
        return [
            'initial_exists' => (int) $initial, 'final_exists' => (int) $final,
            'is_visible' => (int) $visible,
            'action' => !$initial ? 'create' : (!$final ? 'delete' : 'update'),
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }
}
