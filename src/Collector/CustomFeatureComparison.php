<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Collector;

use Pixel\Module\Audit\Api\Field;
use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Core\Comparator;

/** Cancel only equal custom values replaced by new IDs, keeping historical field identifiers intact. */
final class CustomFeatureComparison
{
    public static function snapshots(Snapshot $before, Snapshot $after, array $customIds): array
    {
        $old = self::values($before, $customIds);
        $new = self::values($after, $customIds);
        $removeOld = [];
        $removeNew = [];
        foreach ($old as $key => $value) {
            if (isset($new[$key])) {
                continue;
            }
            foreach ($new as $candidate => $replacement) {
                if (!isset($old[$candidate]) && !isset($removeNew[$candidate]) && $value === $replacement) {
                    $removeOld[$key] = true;
                    $removeNew[$candidate] = true;
                    break;
                }
            }
        }
        return [self::without($before, $removeOld), self::without($after, $removeNew)];
    }

    private static function values(Snapshot $snapshot, array $customIds): array
    {
        $values = [];
        $members = array_fill_keys($snapshot->fields['features']->value ?? [], true);
        foreach ((new Comparator())->sanitize($snapshot)->fields as $key => $field) {
            if (preg_match('/^feature\.(\d+)\.(\d+)\.lang_(\d+)$/D', $key, $match)
                && isset($customIds[$match[2]], $members[$match[1] . ':' . $match[2]])) {
                $id = $match[1] . ':' . $match[2];
                $values[$id]['feature'] = (int) $match[1];
                $values[$id]['languages'][(int) $match[3]] = $field->value;
            }
        }
        foreach ($values as &$value) {
            ksort($value['languages']);
        }
        unset($value);
        return $values;
    }

    private static function without(Snapshot $snapshot, array $removed): Snapshot
    {
        if (!$removed) {
            return $snapshot;
        }
        $fields = $snapshot->fields;
        foreach ($fields as $key => $field) {
            if (preg_match('/^feature\.(\d+)\.(\d+)\.lang_\d+$/D', $key, $match) && isset($removed[$match[1] . ':' . $match[2]])) {
                unset($fields[$key]);
            }
        }
        if (isset($fields['features'])) {
            $field = $fields['features'];
            $fields['features'] = new Field(array_values(array_filter($field->value, static fn ($value) => !isset($removed[$value]))), $field->label, $field->type);
        }
        return new Snapshot($snapshot->exists, $fields);
    }
}
