<?php

declare(strict_types=1);

// Session-local temporary tables shadow the shop tables; no persisted shop data is written.
require dirname(__DIR__, 4) . '/config/config.inc.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Pixel\Module\Audit\Collector\GeneratedSpecificPrices;
use Pixel\Module\Audit\Core\Buffer;
use Pixel\Module\Audit\Core\Comparator;
use Pixel\Module\Audit\Api\Subject;

$db = Db::getInstance();
$temporary = [];
$execute = static function (string $sql) use ($db): void {
    if (!$db->execute($sql)) {
        throw new RuntimeException('Integration fixture SQL failed: ' . $db->getMsgError());
    }
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$events = static function (GeneratedSpecificPrices $collector): array {
    $buffer = new Buffer(new Comparator());
    foreach ($collector->changes() as [$subject, $before, $after, $operation]) {
        $buffer->observe($subject, 'native.specific_price', 'prices', $before, $after, $operation);
    }
    return $buffer->events();
};
try {
    foreach (['specific_price', 'pixel_audit_operation', 'pixel_audit_event'] as $table) {
        $schema = $db->getLink()->query('SHOW CREATE TABLE `' . _DB_PREFIX_ . $table . '`')->fetch(PDO::FETCH_ASSOC);
        $execute(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $schema['Create Table']));
        $temporary[] = $table;
    }
    $execute('CREATE TEMPORARY TABLE `' . _DB_PREFIX_ . 'product_shop` (id_product INT, id_shop INT)');
    $temporary[] = 'product_shop';
    $execute('CREATE TEMPORARY TABLE `' . _DB_PREFIX_ . 'shop` (id_shop INT, id_shop_group INT)');
    $temporary[] = 'shop';
    $execute('INSERT INTO `' . _DB_PREFIX_ . 'shop` VALUES (1, 1), (2, 2)');
    $execute('INSERT INTO `' . _DB_PREFIX_ . 'product_shop` VALUES (3402, 1), (3402, 2)');
    $insert = static function (int $id, int $rule = 62) use ($execute): void {
        $execute('INSERT INTO `' . _DB_PREFIX_ . 'specific_price` (id_specific_price, id_specific_price_rule, id_product, id_shop, id_product_attribute, price, from_quantity, reduction, reduction_tax, reduction_type, `from`, `to`) VALUES (' . $id . ', ' . $rule . ', 3402, 1, 0, -1, 1, 0.3, 1, "percentage", "2026-06-24 00:00:00", "2026-07-01 00:00:00")');
    };
    $insert(1);
    $insert(500, 0);
    $collector = new GeneratedSpecificPrices();
    $collector->capture(3402);
    for ($id = 2; $id <= 6; ++$id) {
        $execute('DELETE FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_specific_price_rule = 62');
        $insert($id);
        $collector->capture(3402);
    }
    $check($events($collector) === [], 'Identical regenerations must be silent.');
    $check($collector->handles(new Subject('specific_price', 6, 1, 'product', 3402)), 'Final generated row must replace native per-ID collection.');
    $check(!$collector->handles(new Subject('specific_price', 500, 1, 'product', 3402)), 'Manual prices must retain native collection.');
    $execute('UPDATE `' . _DB_PREFIX_ . 'specific_price` SET reduction = 0.4 WHERE id_specific_price_rule = 62');
    $changes = $events($collector);
    $check(count($changes) === 1 && $changes[0]['action'] === 'update', 'Real discount change must be an update.');
    $check($changes[0]['changes']['reduction']['before'] === '0.3' && $changes[0]['changes']['reduction']['after'] === '0.4', 'Initial and final discounts must be preserved.');
    $execute('UPDATE `' . _DB_PREFIX_ . 'specific_price` SET reduction = 0.3 WHERE id_specific_price_rule = 62');
    $check($events($collector) === [], 'A reverted change must be silent.');
    $execute('DELETE FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_specific_price_rule = 62');
    $check($events($collector)[0]['action'] === 'delete', 'SQL removal must remain visible.');
    $collector->reset();
    $collector->capture(3402);
    $insert(7);
    $check($events($collector)[0]['action'] === 'create', 'A newly applicable rule must remain visible.');
    $collector->reset();
    $execute('UPDATE `' . _DB_PREFIX_ . 'specific_price` SET id_shop = 0 WHERE id_specific_price_rule = 62');
    $collector->capture(3402);
    $execute('UPDATE `' . _DB_PREFIX_ . 'specific_price` SET reduction = 0.5 WHERE id_specific_price_rule = 62');
    $check(count($events($collector)) === 2, 'Global generated prices must cover both product shops.');
    $collector->reset();
    $execute('UPDATE `' . _DB_PREFIX_ . 'specific_price` SET id_shop_group = 1 WHERE id_specific_price_rule = 62');
    $collector->capture(3402);
    $execute('UPDATE `' . _DB_PREFIX_ . 'specific_price` SET reduction = 0.6 WHERE id_specific_price_rule = 62');
    $check(count($events($collector)) === 1, 'Shop-group prices must stay in their group.');
    // Exercise the real Audit flush as well: reconciliation must replace, not duplicate, native add hooks.
    $settings = new \Pixel\Module\Audit\Configuration\Settings();
    Configuration::set($settings::KEY, json_encode($settings->defaults()), 0, 0);
    $registry = new \Pixel\Module\Audit\Api\Registry([]);
    $registry->add(new \Pixel\Module\Audit\Collector\ObjectModelProvider('SpecificPrice', 'specific_price', 'prices', 'native.specific_price'), 'Specific prices');
    $audit = new \Pixel\Module\Audit\Core\Audit($registry, $settings, new \Pixel\Module\Audit\Storage\Repository(), ['id' => 1, 'name' => 'Integration fixture', 'origin' => 'cli', 'route' => 'fixture']);
    $execute('UPDATE `' . _DB_PREFIX_ . 'specific_price` SET id_shop = 1, id_shop_group = 0, reduction = 0.3 WHERE id_specific_price_rule = 62');
    $audit->watch(Subject::product(3402, 1));
    $execute('DELETE FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_specific_price_rule = 62');
    $insert(1001);
    $audit->watch(new Subject('specific_price', 1001, 1, 'product', 3402), true);
    $audit->flush();
    $check((int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'pixel_audit_event`', false) === 0, 'Native add hooks must not emit identical regenerations.');
    $audit->watch(Subject::product(3402, 1));
    $execute('DELETE FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_specific_price_rule = 62');
    $insert(1002);
    $execute('UPDATE `' . _DB_PREFIX_ . 'specific_price` SET reduction = 0.7 WHERE id_specific_price_rule = 62');
    $audit->watch(new Subject('specific_price', 1002, 1, 'product', 3402), true);
    $audit->flush();
    $stored = $db->executeS('SELECT action, changes FROM `' . _DB_PREFIX_ . 'pixel_audit_event`', true, false);
    $check(count($stored) === 1 && $stored[0]['action'] === 'update', 'A real change must produce exactly one persisted update.');
    $values = json_decode($stored[0]['changes'], true, 512, JSON_THROW_ON_ERROR);
    $check($values['reduction']['before'] === '0.3' && $values['reduction']['after'] === '0.7', 'Persisted values must retain the original discount.');
    $execute('DELETE FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_specific_price = 500');
    $insert(1003, 0);
    $audit->watch(new Subject('specific_price', 1003, 1, 'product', 3402), true);
    $audit->flush();
    $check((int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'pixel_audit_event` WHERE action = "create"', false) === 1, 'Manual price creations must still be collected.');
    echo 'OK: PrestaShop ' . _PS_VERSION_ . ' / PHP ' . PHP_VERSION . ': regeneration, real changes, reversion, SQL deletion, creation, manual prices and multistore scopes.' . PHP_EOL;
} finally {
    foreach (array_reverse($temporary) as $table) {
        $db->execute('DROP TEMPORARY TABLE `' . _DB_PREFIX_ . $table . '`');
    }
}
