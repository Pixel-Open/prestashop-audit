<?php

declare(strict_types=1);

// All writes target session-local temporary tables, never persisted shop data.
require dirname(__DIR__, 4) . '/config/config.inc.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Pixel\Module\Audit\Configuration\Settings;
use Pixel\Module\Audit\Storage\Repository;

$db = Db::getInstance();
$temporary = [];
$execute = static function (string $sql) use ($db): void {
    if (!$db->execute($sql)) {
        throw new RuntimeException('Fixture SQL failed: ' . $db->getMsgError());
    }
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$ids = static function (string $table, string $column) use ($db): string {
    $rows = $db->executeS('SELECT `' . $column . '` FROM `' . _DB_PREFIX_ . $table . '` ORDER BY `' . $column . '`', true, false);
    return implode(',', array_column($rows, $column));
};
try {
    foreach (['pixel_audit_operation', 'pixel_audit_event'] as $table) {
        $schema = $db->getLink()->query('SHOW CREATE TABLE `' . _DB_PREFIX_ . $table . '`')->fetch(PDO::FETCH_ASSOC);
        $execute(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $schema['Create Table']));
        $temporary[] = $table;
    }
    $values = (new Settings())->defaults();
    $values['days'] = 180;
    $values['retention'] = ['orders' => 365];
    Configuration::set(Settings::KEY, json_encode($values), 0, 0);

    // Operation id => age in days. 5 is an old header without event, 6 a recent one.
    foreach ([1 => 400, 2 => 200, 3 => 200, 4 => 10, 5 => 400, 6 => 0] as $id => $age) {
        $execute('INSERT INTO `' . _DB_PREFIX_ . 'pixel_audit_operation` (id_operation, operation_key, actor_name, origin, route, observation, date_add) VALUES ('
            . $id . ', "' . md5('purge-' . $id) . '", "Fixture", "bo", "fixture", "exact", DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $age . ' DAY))');
    }
    $events = [
        [1, 1, 'products', 'product', 10], [2, 1, 'orders', 'order', 20],
        [3, 2, 'products', 'product', 10], [4, 2, 'orders', 'order', 20],
        [5, 3, 'orders', 'order', 21],
        [6, 4, 'products', 'product', 10], [7, 4, 'customers', 'customer', 30],
    ];
    foreach ($events as [$id, $operation, $family, $type, $subject]) {
        $execute('INSERT INTO `' . _DB_PREFIX_ . 'pixel_audit_event` (id_event, id_operation, family, provider, subject_type, subject_id, root_type, root_id, id_shop, action, changes) VALUES ('
            . $id . ', ' . $operation . ', "' . $family . '", "fixture", "' . $type . '", ' . $subject . ', "' . $type . '", ' . $subject . ', 1, "update", "{}")');
    }

    $repository = new Repository();
    $check($repository->purge(1) === 1, 'A batch must stop at its row budget.');
    $removed = $repository->purgeAll(500, 2, false);
    $check($removed['events'] === 2 && $removed['orphans'] === 0, 'The automatic purge must remove the two remaining expired events only.');
    $check($ids('pixel_audit_event', 'id_event') === '4,5,6,7', 'Each family must expire on its own retention.');
    $check($ids('pixel_audit_operation', 'id_operation') === '2,3,4,5,6', 'A header must go with its last event and stay otherwise.');
    $check($repository->purge(500) === 0, 'A second purge must find nothing.');

    $removed = $repository->purgeAll(500, 2);
    $check($removed['orphans'] === 1 && $ids('pixel_audit_operation', 'id_operation') === '2,3,4,6', 'The command must repair old orphan headers only.');

    $repository->erasePersonalData(30, []);
    $check($ids('pixel_audit_event', 'id_event') === '4,5,6', 'Erasure must remove the customer events.');
    $check($ids('pixel_audit_operation', 'id_operation') === '2,3,4,6', 'Erasure must keep a header that still has events.');
    $execute('DELETE FROM `' . _DB_PREFIX_ . 'pixel_audit_event` WHERE id_event = 6');
    $execute('INSERT INTO `' . _DB_PREFIX_ . 'pixel_audit_event` (id_event, id_operation, family, provider, subject_type, subject_id, root_type, root_id, id_shop, action, changes) VALUES (8, 4, "customers", "fixture", "address", 31, "customer", 30, 1, "update", "{}")');
    $repository->erasePersonalData(30, [31]);
    $check($ids('pixel_audit_operation', 'id_operation') === '2,3,6', 'Erasure must remove a header emptied by it.');

    echo 'OK: PrestaShop ' . _PS_VERSION_ . ' / PHP ' . PHP_VERSION . ": per-family retention, batch budget, header cleanup, orphan repair and personal data erasure.\n";
} finally {
    foreach (array_reverse($temporary) as $table) {
        $db->execute('DROP TEMPORARY TABLE `' . _DB_PREFIX_ . $table . '`');
    }
}
