<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Correlation;

use Db;
use Pixel\Module\Audit\Api\Subject;

/** MySQL advisory lock serializes correlated writes, not just the final journal INSERT. */
final class ProductOperation
{
    private bool $attempted = false;
    private bool $locked = false;

    public function __construct(private array $claims)
    {
    }

    public function prime(): bool
    {
        return $this->start(new Subject('product', $this->claims['product'], $this->claims['shops'][0]));
    }

    public function accepts(Subject $subject): bool
    {
        return ($subject->rootType ?? $subject->type) === 'product'
            && ($subject->rootId ?? $subject->id) === $this->claims['product']
            && ($subject->shopId === 0 || in_array($subject->shopId, $this->claims['shops'], true));
    }

    public function start(Subject $subject): bool
    {
        if ($this->claims['expires'] <= time() || !$this->accepts($subject)) {
            return false;
        }
        if ($this->attempted) {
            return $this->locked;
        }
        $this->attempted = true;
        if (!$this->acquire()) {
            \Pixel\Module\Audit\Core\Diagnostics::report(new \RuntimeException('Product operation lock unavailable.'));
            return false;
        }
        try {
            $row = Db::getInstance()->getRow('SELECT closed, expires_at FROM `' . _DB_PREFIX_ . 'pixel_audit_ticket` WHERE operation_key = "' . pSQL($this->key()) . '"', false);
            if ($row && ((int) $row['closed'] || strtotime($row['expires_at'] . ' UTC') <= time())) {
                $this->release();
                return false;
            }
            $this->store(false);
            return true;
        } catch (\Throwable $error) {
            $this->release();
            throw $error;
        }
    }

    public function key(): string
    {
        return $this->claims['nonce'];
    }
    public function active(): bool
    {
        return $this->locked;
    }

    /** Closing an unused token also prevents late requests from opening it. */
    public function close(): bool
    {
        try {
            if (!$this->locked && !$this->acquire()) {
                return false;
            }
            $this->store(true);
            $db = Db::getInstance();
            $states = $db->executeS('SELECT e.id_event FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e INNER JOIN `' . _DB_PREFIX_ . 'pixel_audit_operation` o ON o.id_operation = e.id_operation WHERE o.operation_key = "' . pSQL($this->key()) . '" AND e.is_visible = 0 LIMIT 500', true, false);
            if ($states === false) {
                throw new \RuntimeException('Cannot read cancelled audit states.');
            }
            if ($states && !$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'pixel_audit_event` WHERE id_event IN (' . implode(',', array_map('intval', array_column($states, 'id_event'))) . ')')) {
                throw new \RuntimeException('Cannot remove cancelled audit states.');
            }
            if (!$db->execute('DELETE o FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` o WHERE o.operation_key = "' . pSQL($this->key()) . '" AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e WHERE e.id_operation = o.id_operation)')) {
                throw new \RuntimeException('Cannot remove empty audit operation.');
            }
            return true;
        } catch (\Throwable $error) {
            \Pixel\Module\Audit\Core\Diagnostics::report($error);
            return false;
        } finally {
            $this->release();
        }
    }

    public function release(): void
    {
        $wasLocked = $this->locked;
        $this->locked = false;
        $this->attempted = false;
        if ($wasLocked) {
            try {
                Db::getInstance()->getValue('SELECT RELEASE_LOCK("pixel_audit_' . pSQL($this->key()) . '")', false);
            } catch (\Throwable $error) {
                \Pixel\Module\Audit\Core\Diagnostics::report($error);
            }
        }
    }

    private function acquire(): bool
    {
        $this->locked = (int) Db::getInstance()->getValue('SELECT GET_LOCK("pixel_audit_' . pSQL($this->key()) . '", 5)', false) === 1;
        return $this->locked;
    }

    private function store(bool $closed): void
    {
        if (!Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'pixel_audit_ticket` (operation_key, id_employee, product_id, expires_at, closed) VALUES ("'
            . pSQL($this->key()) . '", ' . (int) $this->claims['employee'] . ', ' . (int) $this->claims['product'] . ', "'
            . gmdate('Y-m-d H:i:s', $this->claims['expires']) . '", ' . (int) $closed . ') ON DUPLICATE KEY UPDATE closed = GREATEST(closed, VALUES(closed))')) {
            $this->release();
            throw new \RuntimeException('Cannot store product operation capability.');
        }
    }
}
