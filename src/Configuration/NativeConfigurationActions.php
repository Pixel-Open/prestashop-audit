<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Configuration;

/** Narrow legacy action boundaries. Only declared, selected keys are read; no request-wide configuration diff. */
final class NativeConfigurationActions
{
    private array $pending = [];

    public function begin(string $action, array $keys, \Pixel_audit $module): void
    {
        if (isset($this->pending[$action]) || $module->audit() === null) {
            return;
        }
        sort($keys, SORT_STRING); // Consistent lock order across action captures and individual writes.
        $this->pending[$action] = [];
        foreach ($keys as $key) {
            $state = [];
            $params = ['operation' => 'update', 'key' => $key, 'reserve_initial' => true, 'state' => &$state];
            $module->hookActionPixelAuditConfigurationBefore($params);
            $this->pending[$action][] = $state;
            unset($params, $state);
        }
    }

    public function finish(string $action, \Pixel_audit $module): void
    {
        $states = $this->pending[$action] ?? [];
        unset($this->pending[$action]);
        foreach (array_reverse($states) as $state) {
            $module->hookActionPixelAuditConfigurationAfter(['state' => $state]);
        }
    }

    public function finishAll(\Pixel_audit $module): void
    {
        foreach (array_keys($this->pending) as $action) {
            $this->finish($action, $module);
        }
    }
}
