<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Configuration;

use Pixel\Module\Audit\Api\Field;
use Pixel\Module\Audit\Api\Snapshot;

/** Pure decoder of exact persisted overrides: does not resolve inheritance or use the Configuration cache. */
final class ConfigurationSnapshot
{
    public function build(array $parameters, array $rows): Snapshot
    {
        $fields = [];
        foreach ($parameters as $key => $parameter) {
            $fields[$key . '.defined'] = new Field(false, $parameter->label . ' - explicit value defined', 'boolean');
        }
        foreach ($rows as $row) {
            $key = $row['name'];
            $parameter = $parameters[$key] ?? null;
            if ($parameter === null) {
                continue;
            }
            $fields[$key . '.defined'] = new Field(true, $parameter->label . ' - explicit value defined', 'boolean');
            if ($parameter->translated && $row['id_lang'] !== null) {
                $fields[$key . '.lang_' . (int) $row['id_lang']] = $parameter->field($row['translated_value'], $parameter->label . ' [language ' . (int) $row['id_lang'] . ']');
            }
            if (!$parameter->translated || $row['value'] !== null) {
                $fields[$key] = $parameter->field($row['value'], $parameter->label);
            }
        }
        // The scope continues to exist when its last parameter is removed: only fields change.
        return new Snapshot(true, $fields);
    }
}
