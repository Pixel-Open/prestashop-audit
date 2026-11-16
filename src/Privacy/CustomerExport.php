<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Privacy;

/** Flat rows for the GDPR export: one per changed field, without the employee who made the change. */
final class CustomerExport
{
    /**
     * @param array<int, array{date_add:string, subject_type:string, subject_id:int|string, action:string, changes:string}> $events
     *
     * @return array<int, array<string, string>>
     */
    public static function rows(array $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            $changes = json_decode($event['changes'], true);
            foreach (is_array($changes) ? $changes : [] as $key => $change) {
                $rows[] = [
                    'date_utc' => $event['date_add'],
                    'object' => $event['subject_type'] . ' #' . (int) $event['subject_id'],
                    'action' => $event['action'],
                    'field' => (string) ($change['label'] ?? $key),
                    'before' => self::value($change['before'] ?? null),
                    'after' => self::value($change['after'] ?? null),
                ];
            }
        }
        return $rows;
    }

    private static function value(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
