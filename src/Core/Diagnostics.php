<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Core;

final class Diagnostics
{
    public const KEY = 'PIXEL_AUDIT_LAST_ERROR';
    /** The audit log page warns about collection errors younger than this. */
    public const RECENT_SECONDS = 604800;
    private static bool $reporting = false;

    public static function report(\Throwable $error): void
    {
        // No exception message: it can contain SQL or personal data.
        $summary = get_class($error) . ' in ' . basename($error->getFile()) . ':' . $error->getLine();
        error_log('[pixel_audit] ' . $summary);
        // Writing the log entry fires ObjectModel hooks, which can report again.
        if (self::$reporting) {
            return;
        }
        self::$reporting = true;
        try {
            \Configuration::updateGlobalValue(self::KEY, gmdate('Y-m-d H:i:s') . ' UTC - ' . get_class($error));
            if (class_exists(\PrestaShopLogger::class)) {
                \PrestaShopLogger::addLog('Pixel Audit: changes may not have been recorded (' . $summary . ').', 3, null, 'Module');
            }
        } catch (\Throwable $ignored) {
            // Logging must not break the original business operation.
        } finally {
            self::$reporting = false;
        }
    }

    /** Last collection error, or null when there is none or it is older than RECENT_SECONDS. */
    public static function recent(?string $stored, int $now): ?string
    {
        $date = is_string($stored) ? strtotime(substr($stored, 0, 19) . ' UTC') : false;
        return $date !== false && $date >= $now - self::RECENT_SECONDS ? $stored : null;
    }
}
