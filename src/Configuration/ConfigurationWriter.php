<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Configuration;

/** Optional per-write API. The callback's return value and exceptions are preserved. */
final class ConfigurationWriter
{
    public static function observe(array $scope, callable $write): mixed
    {
        $module = null;
        try {
            if (\Module::isEnabled('pixel_audit')) {
                $candidate = \Module::getInstanceByName('pixel_audit');
                if ($candidate instanceof \Pixel_audit) {
                    $module = $candidate;
                }
            }
        } catch (\Throwable $error) {
            \Pixel\Module\Audit\Core\Diagnostics::report($error);
        }
        if ($module === null) {
            return $write();
        }
        return $module->observeConfigurationChange($scope, $write);
    }
}
