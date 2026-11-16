<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Storage;

use Db;
use Pixel\Module\Audit\Configuration\Settings;
use Pixel\Module\Audit\Presentation\Presenter;

final class Repository
{
    public function install(): bool
    {
        $db = Db::getInstance();
        return $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pixel_audit_operation` (
            id_operation BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            operation_key CHAR(32) NOT NULL,
            actor_id INT UNSIGNED NOT NULL DEFAULT 0,
            actor_name VARCHAR(255) NOT NULL,
            origin VARCHAR(16) NOT NULL,
            route VARCHAR(190) NOT NULL,
            observation VARCHAR(16) NOT NULL,
            date_add DATETIME NOT NULL,
            source_operation_id BIGINT UNSIGNED NULL,
            source_event_id BIGINT UNSIGNED NULL,
            UNIQUE KEY operation_key (operation_key), KEY actor_date (actor_id, date_add), KEY date_add (date_add)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')
        && $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pixel_audit_event` (
            id_event BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            id_operation BIGINT UNSIGNED NOT NULL,
            family VARCHAR(64) NOT NULL,
            provider VARCHAR(190) NOT NULL,
            subject_type VARCHAR(64) NOT NULL,
            subject_id INT UNSIGNED NOT NULL,
            root_type VARCHAR(64) NOT NULL,
            root_id INT UNSIGNED NOT NULL,
            id_shop INT UNSIGNED NOT NULL DEFAULT 0,
            action VARCHAR(16) NOT NULL,
            changes LONGTEXT NOT NULL,
            initial_exists TINYINT UNSIGNED NULL,
            final_exists TINYINT UNSIGNED NULL,
            is_visible TINYINT UNSIGNED NOT NULL DEFAULT 1,
            KEY operation_id (id_operation),
            KEY subject (subject_type, subject_id, id_shop), KEY root (root_type, root_id, id_shop), KEY family (family, id_operation)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')
        && $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pixel_audit_ticket` (
            operation_key CHAR(32) NOT NULL PRIMARY KEY, id_employee INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, closed TINYINT UNSIGNED NOT NULL DEFAULT 0,
            KEY expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function append(array $events, array $actor, string $observation, Settings $settings): void
    {
        if (!$events) {
            return;
        }
        $db = Db::getInstance();
        $this->assertTransactionBoundary();
        $this->execute('START TRANSACTION');
        try {
            $ids = [];
            foreach ($events as $event) {
                // An unstorable event is skipped and reported: it must not discard the rest of the batch.
                try {
                    $json = json_encode($event['changes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    if (strlen($json) > 2097152) {
                        throw new \LengthException('Audit event exceeds 2 MiB.');
                    }
                } catch (\JsonException|\LengthException $error) {
                    \Pixel\Module\Audit\Core\Diagnostics::report($error);
                    continue;
                }
                $key = $event['operation'];
                if (!isset($ids[$key]) && !empty($event['correlated'])) {
                    // An atomic UPSERT avoids SELECT-on-missing-key gap-lock races between independent saves.
                    $values = [
                        'operation_key' => $key, 'actor_id' => (int) $actor['id'],
                        'actor_name' => mb_substr($actor['name'], 0, 255), 'origin' => $actor['origin'],
                        'route' => mb_substr($actor['route'], 0, 190), 'observation' => $observation,
                        'date_add' => gmdate('Y-m-d H:i:s'),
                    ];
                    $quoted = array_map(static fn ($value) => is_int($value) ? (string) $value : '"' . pSQL($value) . '"', array_values($values));
                    $this->execute('INSERT INTO `' . _DB_PREFIX_ . 'pixel_audit_operation` (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', $quoted) . ') ON DUPLICATE KEY UPDATE id_operation = LAST_INSERT_ID(id_operation)');
                    $existing = $db->getRow('SELECT id_operation, actor_id FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` WHERE operation_key = "' . pSQL($key) . '"', false);
                    if (!$existing || (int) $existing['actor_id'] !== (int) $actor['id']) {
                        throw new \RuntimeException('Operation actor mismatch.');
                    }
                    $ids[$key] = (int) $existing['id_operation'];
                    if ($observation === 'partial') {
                        $this->execute('UPDATE `' . _DB_PREFIX_ . 'pixel_audit_operation` SET observation = "partial" WHERE id_operation = ' . $ids[$key]);
                    }
                }
                if (!isset($ids[$key])) {
                    $this->insert('pixel_audit_operation', [
                        'operation_key' => pSQL($key), 'actor_id' => (int) $actor['id'],
                        'actor_name' => pSQL(mb_substr($actor['name'], 0, 255)),
                        'origin' => pSQL($actor['origin']), 'route' => pSQL(mb_substr($actor['route'], 0, 190)),
                        'observation' => pSQL($observation), 'date_add' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $ids[$key] = (int) $db->Insert_ID();
                }
                $subject = $event['subject'];
                if (!empty($event['correlated'])) {
                    try {
                        $this->mergeEvent($ids[$key], $event);
                    } catch (\LengthException $error) {
                        // Raised before any write: the transaction is intact.
                        \Pixel\Module\Audit\Core\Diagnostics::report($error);
                    }
                    continue;
                }
                $this->insert('pixel_audit_event', [
                    'id_operation' => $ids[$key], 'family' => pSQL($event['family']), 'provider' => pSQL($event['provider']),
                    'subject_type' => pSQL($subject->type), 'subject_id' => $subject->id,
                    'root_type' => pSQL($subject->rootType ?? $subject->type), 'root_id' => $subject->rootId ?? $subject->id,
                    'id_shop' => $subject->shopId, 'action' => pSQL($event['action']), 'changes' => pSQL($json, true),
                ]);
            }
            $this->execute('COMMIT');
        } catch (\Throwable $error) {
            $db->execute('ROLLBACK');
            throw $error;
        }
    }

    private function mergeEvent(int $operationId, array $event): void
    {
        $subject = $event['subject'];
        $data = [
            'id_operation' => $operationId, 'family' => pSQL($event['family']), 'provider' => pSQL($event['provider']),
            'subject_type' => pSQL($subject->type), 'subject_id' => $subject->id, 'id_shop' => $subject->shopId,
            'root_type' => pSQL($subject->rootType ?? $subject->type), 'root_id' => $subject->rootId ?? $subject->id,
        ];
        $conditions = [];
        foreach ($data as $key => $value) {
            $conditions[] = '`' . $key . '` = ' . (is_int($value) ? $value : '"' . $value . '"');
        }
        $existing = Db::getInstance()->getRow('SELECT id_event, initial_exists, final_exists, changes FROM `' . _DB_PREFIX_ . 'pixel_audit_event` WHERE ' . implode(' AND ', $conditions), false);
        $merged = (new \Pixel\Module\Audit\Correlation\EventMerger())->merge($existing ?: null, $event);
        if (strlen($merged['changes']) > 2097152) {
            throw new \LengthException('Merged audit event exceeds 2 MiB.');
        }
        $merged['changes'] = pSQL($merged['changes'], true);
        if ($existing) {
            if (!Db::getInstance()->update('pixel_audit_event', $merged, 'id_event = ' . (int) $existing['id_event'])) {
                throw new \RuntimeException('Cannot merge audit event.');
            }
        } else {
            $this->insert('pixel_audit_event', $data + $merged);
        }
    }

    /** A bounded batch; operation headers are removed only after their last event expires. */
    public function purge(int $batchSize = 500): int
    {
        $batchSize = max(1, min(5000, $batchSize));
        $db = Db::getInstance();
        $this->assertTransactionBoundary();
        $this->execute('START TRANSACTION');
        try {
            $settings = (new Settings())->get();
            $days = max(1, min(3650, (int) $settings['days']));
            $families = $db->executeS('SELECT DISTINCT family FROM `' . _DB_PREFIX_ . 'pixel_audit_event`', true, false);
            if ($families === false) {
                throw new \RuntimeException('Cannot list audit families.');
            }
            // One indexed range per family, bounded by its own retention: events that a longer retention keeps are never read.
            $rows = [];
            $bounds = [];
            foreach (array_column($families, 'family') as $family) {
                if (count($rows) >= $batchSize) {
                    break;
                }
                $override = (int) ($settings['retention'][$family] ?? 0);
                $retention = $override > 0 ? max(1, min(3650, $override)) : $days;
                $expired = 'o.date_add < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $retention . ' DAY)';
                // Operation ids grow with their date: the newest expired operation bounds the range of the family index.
                $bounds[$retention] ??= (int) $db->getValue('SELECT MAX(o.id_operation) FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` o WHERE ' . $expired, false);
                if ($bounds[$retention] === 0) {
                    continue;
                }
                $expiredRows = $db->executeS('SELECT e.id_event, e.id_operation FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e INNER JOIN `' . _DB_PREFIX_ . 'pixel_audit_operation` o ON o.id_operation = e.id_operation WHERE e.family = "' . pSQL($family) . '" AND e.id_operation <= ' . $bounds[$retention] . ' AND ' . $expired . ' ORDER BY e.id_operation, e.id_event LIMIT ' . ($batchSize - count($rows)) . ' FOR UPDATE', true, false);
                if ($expiredRows === false) {
                    throw new \RuntimeException('Cannot select expired audit events.');
                }
                $rows = array_merge($rows, $expiredRows);
            }
            if ($rows) {
                $ids = implode(',', array_map('intval', array_column($rows, 'id_event')));
                $operations = implode(',', array_unique(array_map('intval', array_column($rows, 'id_operation'))));
                $this->execute('DELETE FROM `' . _DB_PREFIX_ . 'pixel_audit_event` WHERE id_event IN (' . $ids . ')');
                $this->execute('DELETE o FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` o WHERE o.id_operation IN (' . $operations . ') AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e WHERE e.id_operation = o.id_operation)');
            }
            $this->execute('COMMIT');
            return count($rows);
        } catch (\Throwable $error) {
            $db->execute('ROLLBACK');
            throw $error;
        }
    }

    /**
     * Every purge category, each within its own budget of bounded transactions.
     * The orphan repair reads every operation header: the automatic purge of a web request skips it.
     *
     * @return array{events:int, hidden:int, tickets:int, orphans:int}
     */
    public function purgeAll(int $batchSize, int $maxBatches, bool $orphans = true): array
    {
        $removed = ['events' => 0, 'hidden' => 0, 'tickets' => 0, 'orphans' => 0];
        for ($i = 0; $i < $maxBatches; ++$i) {
            $count = $this->purge($batchSize);
            $removed['events'] += $count;
            if ($count < $batchSize) {
                break;
            }
        }
        for ($i = 0; $i < $maxBatches; ++$i) {
            $result = $this->purgeCapabilitiesBatch($batchSize);
            $removed['tickets'] += $result['tickets'];
            $removed['hidden'] += $result['events'];
            if ($result['tickets'] + $result['events'] === 0) {
                break;
            }
        }
        for ($i = 0; $orphans && $i < $maxBatches; ++$i) {
            $count = $this->purgeOrphanOperations($batchSize);
            $removed['orphans'] += $count;
            if ($count < $batchSize) {
                break;
            }
        }
        return $removed;
    }

    /** Both tickets and hidden states have their own per-transaction row budget. Active saves are skipped. */
    public function purgeCapabilitiesBatch(int $batchSize = 500): array
    {
        $batchSize = max(1, min(5000, $batchSize));
        $db = Db::getInstance();
        $this->assertTransactionBoundary();
        $locks = [];
        $removed = ['tickets' => 0, 'events' => 0];
        $this->execute('START TRANSACTION');
        try {
            $tickets = $db->executeS('SELECT operation_key FROM `' . _DB_PREFIX_ . 'pixel_audit_ticket` WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) ORDER BY expires_at LIMIT ' . $batchSize, true, false);
            if ($tickets === false) {
                throw new \RuntimeException('Cannot read expired operation tickets.');
            }
            foreach ($tickets as $ticket) {
                $key = pSQL($ticket['operation_key']);
                $lock = 'pixel_audit_' . $key;
                if ((int) $db->getValue('SELECT GET_LOCK("' . $lock . '", 0)', false) !== 1) {
                    continue;
                }
                $locks[] = $lock;
                $eligible = $db->executeS('SELECT operation_key FROM `' . _DB_PREFIX_ . 'pixel_audit_ticket` WHERE operation_key = "' . $key . '" AND expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) LIMIT 1 FOR UPDATE', true, false);
                if ($eligible === false) {
                    throw new \RuntimeException('Cannot lock expired operation ticket.');
                }
                if (!$eligible) {
                    continue;
                }
                $join = ' FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e INNER JOIN `' . _DB_PREFIX_ . 'pixel_audit_operation` o ON o.id_operation = e.id_operation WHERE o.operation_key = "' . $key . '" AND e.is_visible = 0';
                $budget = $batchSize - $removed['events'];
                if ($budget > 0) {
                    $states = $db->executeS('SELECT e.id_event' . $join . ' ORDER BY e.id_event LIMIT ' . $budget . ' FOR UPDATE', true, false);
                    if ($states === false) {
                        throw new \RuntimeException('Cannot read hidden audit states.');
                    }
                    if ($states) {
                        $this->execute('DELETE FROM `' . _DB_PREFIX_ . 'pixel_audit_event` WHERE id_event IN (' . implode(',', array_map('intval', array_column($states, 'id_event'))) . ')');
                        $removed['events'] += count($states);
                    }
                }
                $remaining = $db->getValue('SELECT e.id_event' . $join, false);
                if ((int) $db->getNumberError() !== 0) {
                    throw new \RuntimeException('Cannot check remaining hidden audit states.');
                }
                if ($remaining) {
                    continue;
                } // Keep the ticket so later batches can reach its remaining states.
                $this->execute('DELETE o FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` o WHERE o.operation_key = "' . $key . '" AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e WHERE e.id_operation = o.id_operation)');
                $this->execute('DELETE FROM `' . _DB_PREFIX_ . 'pixel_audit_ticket` WHERE operation_key = "' . $key . '"');
                ++$removed['tickets'];
            }
            $this->execute('COMMIT');
            return $removed;
        } catch (\Throwable $error) {
            $db->execute('ROLLBACK');
            throw $error;
        } finally {
            foreach (array_reverse($locks) as $lock) {
                try {
                    $db->getValue('SELECT RELEASE_LOCK("' . $lock . '")', false);
                } catch (\Throwable $error) {
                    \Pixel\Module\Audit\Core\Diagnostics::report($error);
                }
            }
        }
    }

    /** Repair leftover metadata without touching any operation that still has an event. */
    public function purgeOrphanOperations(int $batchSize = 500): int
    {
        $batchSize = max(1, min(5000, $batchSize));
        $db = Db::getInstance();
        $this->assertTransactionBoundary();
        $this->execute('START TRANSACTION');
        try {
            $rows = $db->executeS('SELECT o.id_operation FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` o WHERE o.date_add < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e WHERE e.id_operation = o.id_operation) ORDER BY o.id_operation LIMIT ' . $batchSize . ' FOR UPDATE', true, false);
            if ($rows === false) {
                throw new \RuntimeException('Cannot read orphan audit operations.');
            }
            if ($rows) {
                $this->execute('DELETE o FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` o WHERE o.id_operation IN (' . implode(',', array_map('intval', array_column($rows, 'id_operation'))) . ') AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e WHERE e.id_operation = o.id_operation)');
            }
            $this->execute('COMMIT');
            return count($rows);
        } catch (\Throwable $error) {
            $db->execute('ROLLBACK');
            throw $error;
        }
    }

    /** Events of a customer and of its addresses, in every shop. Orders are kept, as PrestaShop keeps them. */
    private function personalScope(int $customerId, array $addressIds): string
    {
        $where = '(e.subject_type = "customer" AND e.subject_id = ' . $customerId . ') OR (e.root_type = "customer" AND e.root_id = ' . $customerId . ')';
        if ($addressIds) {
            $where .= ' OR (e.subject_type = "address" AND e.subject_id IN (' . implode(',', array_map('intval', $addressIds)) . '))';
        }
        return $where;
    }

    /** Emptied operation headers go with their events; the orphan purge of the command repairs an interrupted erasure. */
    public function erasePersonalData(int $customerId, array $addressIds): void
    {
        $scope = $this->personalScope($customerId, $addressIds);
        $operations = Db::getInstance()->executeS('SELECT DISTINCT e.id_operation FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e WHERE ' . $scope, true, false);
        if ($operations === false) {
            throw new \RuntimeException('Cannot read personal audit operations.');
        }
        $this->execute('DELETE e FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e WHERE ' . $scope);
        foreach (array_chunk(array_map('intval', array_column($operations, 'id_operation')), 1000) as $ids) {
            $this->execute('DELETE o FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` o WHERE o.id_operation IN (' . implode(',', $ids) . ') AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e WHERE e.id_operation = o.id_operation)');
        }
    }

    public function personalData(int $customerId, array $addressIds): array
    {
        $rows = Db::getInstance()->executeS('SELECT o.date_add, e.subject_type, e.subject_id, e.action, e.changes FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e INNER JOIN `' . _DB_PREFIX_ . 'pixel_audit_operation` o ON o.id_operation = e.id_operation WHERE e.is_visible = 1 AND (' . $this->personalScope($customerId, $addressIds) . ') ORDER BY e.id_event', true, false);
        if ($rows === false) {
            throw new \RuntimeException('Cannot read personal audit data.');
        }
        return $rows;
    }

    public function uninstall(): bool
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'pixel_audit_event`, `' . _DB_PREFIX_ . 'pixel_audit_operation`, `' . _DB_PREFIX_ . 'pixel_audit_ticket`');
    }

    /** Global events (shop 0) and events of the employee's current authorized shop context. */
    private function scope(array $shops): string
    {
        $shops = array_filter(array_map('intval', $shops), static fn ($id) => $id > 0);
        if (!$shops) {
            return '1 = 0';
        }
        return 'e.is_visible = 1 AND e.id_shop IN (' . implode(',', array_unique(array_merge([0], $shops))) . ')';
    }

    /** Global storage metadata, read only and limited to this module's own tables. */
    public function storageDiagnostics(): array
    {
        $db = Db::getInstance();
        $tables = [];
        foreach (['pixel_audit_operation', 'pixel_audit_event', 'pixel_audit_ticket'] as $suffix) {
            $name = _DB_PREFIX_ . $suffix;
            $table = ['name' => $name, 'rows' => null, 'data_bytes' => null, 'index_bytes' => null, 'total_bytes' => null];
            try {
                $count = $db->getValue('SELECT COUNT(*) FROM `' . bqSQL($name) . '`', false);
                if ($count !== false) {
                    $table['rows'] = (int) $count;
                }
                $size = $db->getRow("SELECT DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . pSQL($name) . "'", false);
                if (is_array($size) && $size['data_bytes'] !== null && $size['index_bytes'] !== null) {
                    $table['data_bytes'] = (int) $size['data_bytes'];
                    $table['index_bytes'] = (int) $size['index_bytes'];
                    $table['total_bytes'] = $table['data_bytes'] + $table['index_bytes'];
                }
            } catch (\Throwable $error) {
                // A missing table or unavailable metadata must not prevent reading diagnostics.
            }
            $tables[] = $table;
        }
        return $tables;
    }

    public function search(array $filters, array $shops, int $page = 1, int $pageSize = 50): array
    {
        $pageSize = in_array($pageSize, [20, 50, 100, 200], true) ? $pageSize : 50;
        $where = $this->scope($shops);
        $operationWhere = '1 = 1';
        foreach (['family' => 'e.family', 'action' => 'e.action'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where .= " AND $column = '" . pSQL($filters[$key]) . "'";
            }
        }
        if (!empty($filters['employee'])) {
            $operationWhere .= ' AND o.actor_id = ' . (int) $filters['employee'];
        }
        $type = (string) ($filters['type'] ?? '');
        $objectId = (int) ($filters['object'] ?? 0);
        if ($type !== '') {
            $quotedType = "'" . pSQL($type) . "'";
            $where .= ' AND ((e.root_type = ' . $quotedType . ($objectId > 0 ? ' AND e.root_id = ' . $objectId : '')
                . ') OR (e.subject_type = ' . $quotedType . ($objectId > 0 ? ' AND e.subject_id = ' . $objectId : '') . '))';
        } elseif ($objectId > 0) {
            $where .= ' AND (e.root_id = ' . $objectId . ' OR e.subject_id = ' . $objectId . ')';
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (!empty($filters[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $filters[$key])) {
                $operationWhere .= " AND o.date_add $operator '" . pSQL($filters[$key]) . ($key === 'from' ? " 00:00:00'" : " 23:59:59'");
            }
        }
        $db = Db::getInstance();
        // Page the operations first (primary key order, early stop), then load the subjects of that page only.
        $from = ' FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` o WHERE ' . $operationWhere
            . ' AND EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e WHERE e.id_operation = o.id_operation AND ' . $where . ')';
        $count = (int) $db->getValue('SELECT COUNT(*)' . $from, false);
        $rows = $db->executeS('SELECT o.*' . $from . ' ORDER BY o.id_operation DESC LIMIT ' . ((max(1, min(1000000, $page)) - 1) * $pageSize) . ', ' . $pageSize, true, false) ?: [];
        $subjects = [];
        if ($rows) {
            $roots = $db->executeS('SELECT e.id_operation, e.root_type, e.root_id, COUNT(*) AS events FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e WHERE e.id_operation IN ('
                . implode(',', array_map('intval', array_column($rows, 'id_operation'))) . ') AND ' . $where
                . ' GROUP BY e.id_operation, e.root_type, e.root_id ORDER BY e.root_type, e.root_id', true, false) ?: [];
            foreach ($roots as $root) {
                $subjects[(int) $root['id_operation']]['subjects'][] = $root['root_type'] . ' #' . $root['root_id'];
                $subjects[(int) $root['id_operation']]['events'] = ($subjects[(int) $root['id_operation']]['events'] ?? 0) + (int) $root['events'];
            }
        }
        foreach ($rows as $index => $row) {
            $operation = $subjects[(int) $row['id_operation']] ?? ['subjects' => [], 'events' => 0];
            $rows[$index]['subjects'] = implode(', ', $operation['subjects']);
            $rows[$index]['events'] = $operation['events'];
            $rows[$index]['subjects_display'] = Presenter::subjects($rows[$index]['subjects']);
        }
        return ['count' => $count, 'rows' => $rows];
    }

    /** Exact product-root scope on both list and details; never expose siblings of a multi-root operation. */
    public function productHistory(int $productId, array $shops, int $page = 1, string $family = '', int $operationId = 0): array
    {
        $where = $this->scope($shops) . ' AND e.root_type = "product" AND e.root_id = ' . max(0, $productId);
        if ($family !== '') {
            $where .= ' AND e.family = "' . pSQL($family) . '"';
        }
        if ($operationId > 0) {
            $where .= ' AND o.id_operation = ' . $operationId;
        }
        $join = ' FROM `' . _DB_PREFIX_ . 'pixel_audit_operation` o INNER JOIN `' . _DB_PREFIX_ . 'pixel_audit_event` e ON e.id_operation = o.id_operation WHERE ' . $where;
        $db = Db::getInstance();
        $count = $db->getValue('SELECT COUNT(' . ($operationId > 0 ? 'e.id_event' : 'DISTINCT o.id_operation') . ')' . $join, false);
        if ($count === false) {
            throw new \RuntimeException('Cannot count product history.');
        }
        $count = (int) $count;
        $page = max(1, min($page, max(1, (int) ceil($count / 20))));
        $limit = ' LIMIT ' . (($page - 1) * 20) . ', 20';
        $query = $operationId > 0
            ? 'SELECT e.*, o.actor_name, o.actor_id, o.date_add, o.origin, o.observation, o.source_operation_id, o.source_event_id' . $join . ' ORDER BY e.id_event' . $limit
            : 'SELECT o.id_operation, o.actor_name, o.actor_id, o.date_add, o.observation, o.source_operation_id, o.source_event_id, COUNT(e.id_event) AS events' . $join . ' GROUP BY o.id_operation ORDER BY o.id_operation DESC' . $limit;
        $rows = $db->executeS($query, true, false);
        if ($rows === false) {
            throw new \RuntimeException('Cannot read product history.');
        }
        return ['count' => $count, 'page' => $page, 'more' => $page * 20 < $count, 'rows' => $operationId > 0 ? $this->presentEvents($rows) : $rows];
    }

    public function details(int $operationId, array $shops): array
    {
        $rows = Db::getInstance()->executeS('SELECT e.*, o.actor_name, o.actor_id, o.date_add, o.origin, o.route, o.observation, o.source_operation_id, o.source_event_id FROM `' . _DB_PREFIX_ . 'pixel_audit_event` e INNER JOIN `' . _DB_PREFIX_ . 'pixel_audit_operation` o ON o.id_operation = e.id_operation WHERE e.id_operation = ' . $operationId . ' AND ' . $this->scope($shops) . ' ORDER BY e.id_event', true, false) ?: [];
        return $this->presentEvents($rows);
    }

    private function presentEvents(array $rows): array
    {
        $module = \Module::getInstanceByName('pixel_audit');
        $settings = new Settings();
        foreach ($rows as &$row) {
            $row['root_display'] = Presenter::subject($row['root_type'], (int) $row['root_id']);
            $row['subject_display'] = Presenter::subject($row['subject_type'], (int) $row['subject_id']);
            $row['action_display'] = \Pixel\Module\Audit\Presentation\I18n::trans(Presenter::ACTIONS[$row['action']] ?? $row['action']);
            $row['changes'] = json_decode($row['changes'], true, 512, JSON_THROW_ON_ERROR);
            $definition = $module instanceof \Pixel_audit ? $module->productRestorationDefinition($row['provider']) : null;
            $row['restorable_fields'] = $definition !== null && $definition->family === $row['family'] && $row['action'] === 'update'
                && $row['root_type'] === 'product' && (($row['subject_type'] === 'product' && (int) $row['subject_id'] === (int) $row['root_id']) || ($row['subject_type'] === 'combination' && $module->registry()->restorationStorage($row['provider']) !== null)) && (int) $row['id_shop'] > 0
                && strtotime($row['date_add'] . ' UTC') >= time() - $settings->days($definition->family) * 86400
                ? $definition->available($row['changes']) : [];
            foreach ($row['changes'] as $key => &$change) {
                if ($module instanceof \Pixel_audit) {
                    $change['label'] = $module->auditFieldLabel($row['provider'], (string) $key, $change['label']);
                }
                $change['diff'] = \Pixel\Module\Audit\Presentation\VisualDiff::compare($change['before'], $change['after'], $change['type']);
                foreach (['before', 'after'] as $side) {
                    $change[$side . '_display'] = Presenter::value($change[$side], $change['type']);
                    $change[$side] = $change[$side] === null ? '∅' : json_encode($change[$side], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            }
            unset($change);
        }
        unset($row);
        return $rows;
    }

    private function assertTransactionBoundary(): void
    {
        $link = Db::getInstance()->getLink();
        // No START TRANSACTION unless we can prove it will not implicitly commit business work.
        if (!$link instanceof \PDO) {
            throw new \RuntimeException('Audit persistence requires the PDO MySQL driver.');
        }
        if ($link->inTransaction()) {
            throw new \RuntimeException('Audit requires its own transaction after business COMMIT or ROLLBACK.');
        }
    }

    private function execute(string $sql): void
    {
        if (!Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Audit SQL operation failed.');
        }
    }

    private function insert(string $table, array $data): void
    {
        if (!Db::getInstance()->insert($table, $data)) {
            throw new \RuntimeException('Audit insert failed.');
        }
    }
}
