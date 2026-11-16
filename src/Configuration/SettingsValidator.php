<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Configuration;

final class SettingsValidator
{
    public function validate(array $input, array $families, array $previous, array $parameters = []): array
    {
        $days = filter_var($input['days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($days === false) {
            throw new \InvalidArgumentException('Retention must be between 1 and 3650 days.');
        }
        $enabled = is_array($input['families'] ?? null) ? $input['families'] : [];
        $retention = is_array($input['retention'] ?? null) ? $input['retention'] : [];
        // Preserve settings of temporarily unavailable providers.
        $result = ['days' => $days, 'families' => $previous['families'] ?? [], 'retention' => $previous['retention'] ?? []];
        foreach ($families as $family => $label) {
            $value = $retention[$family] ?? '0';
            if ($value === '') {
                $value = '0';
            }
            $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3650]]);
            if ($value === false) {
                throw new \InvalidArgumentException('Invalid retention: ' . $label);
            }
            $result['families'][$family] = isset($enabled[$family]) && $enabled[$family] === '1';
            $result['retention'][$family] = $value;
        }
        $result['configuration_keys'] = $previous['configuration_keys'] ?? [];
        $submittedKeys = is_array($input['configuration_keys'] ?? null) ? $input['configuration_keys'] : [];
        if (($input['configuration_keys_present'] ?? null) === '1') {
            foreach ($parameters as $key => $parameter) {
                $result['configuration_keys'][$key] = isset($submittedKeys[$key]) && $submittedKeys[$key] === '1';
            }
        }
        $result['login_history'] = ($input['login_history'] ?? null) === '1';
        $result['delete_on_uninstall'] = ($input['delete_on_uninstall'] ?? null) === '1';
        return $result;
    }
}
