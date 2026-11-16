<?php

declare(strict_types=1);

// All writes target session-local temporary tables, never persisted shop data.
require dirname(__DIR__, 4) . '/config/config.inc.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Pixel\Module\Audit\Api\Registry;
use Pixel\Module\Audit\Api\Subject;
use Pixel\Module\Audit\Collector\ObjectModelProvider;
use Pixel\Module\Audit\Configuration\Settings;
use Pixel\Module\Audit\Core\Audit;
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
try {
    $tables = ['product_attribute', 'product_attribute_shop', 'pixel_audit_operation', 'pixel_audit_event'];
    if (!empty(ObjectModel::getDefinition('Combination')['multilang'])) {
        $tables[] = 'product_attribute_lang';
    }
    foreach ($tables as $table) {
        $schema = $db->getLink()->query('SHOW CREATE TABLE `' . _DB_PREFIX_ . $table . '`')->fetch(PDO::FETCH_ASSOC);
        $execute(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $schema['Create Table']));
        $temporary[] = $table;
    }
    $execute('INSERT INTO `' . _DB_PREFIX_ . 'product_attribute` (id_product_attribute, id_product, default_on) VALUES (49162, 3402, 1), (49163, 3402, NULL)');
    $execute('INSERT INTO `' . _DB_PREFIX_ . 'product_attribute_shop` (id_product_attribute, id_product, id_shop, default_on) VALUES (49162, 3402, 1, 1), (49163, 3402, 1, NULL), (49162, 3402, 2, 1), (49163, 3402, 2, NULL)');
    $settings = new Settings();
    $values = $settings->defaults();
    $values['families'] = ['combinations' => true];
    Configuration::set(Settings::KEY, json_encode($values), 0, 0);
    $registry = new Registry([]);
    $registry->add(new ObjectModelProvider('Combination', 'combination', 'combinations', 'native.combination'), 'Combinations');
    $audit = new Audit($registry, $settings, new Repository(), ['id' => 1, 'name' => 'Fixture', 'origin' => 'cli', 'route' => 'fixture']);
    $first = new Subject('combination', 49162, 1, 'product', 3402);
    $second = new Subject('combination', 49163, 1, 'product', 3402);
    $otherShop = new Subject('combination', 49162, 2, 'product', 3402);
    // Same default: product capture precedes SQL reset, then the native combination update hook fires.
    $audit->watch(Subject::product(3402, 1));
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_attribute_shop` SET default_on = NULL WHERE id_shop = 1');
    $audit->watch($first);
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_attribute_shop` SET default_on = 1 WHERE id_product_attribute = 49162 AND id_shop = 1');
    $audit->flush();
    $check((int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'pixel_audit_event`', false) === 0, 'Reselecting the same default must not produce an event.');
    // A real default change must still be visible, independently of another shop's default.
    $audit->watch(Subject::product(3402, 1));
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_attribute_shop` SET default_on = NULL WHERE id_shop = 1');
    $audit->watch($first);
    $audit->watch($second);
    $audit->watch($otherShop);
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_attribute_shop` SET default_on = 1 WHERE id_product_attribute = 49163 AND id_shop = 1');
    $audit->flush();
    $rows = $db->executeS('SELECT subject_id, id_shop, changes FROM `' . _DB_PREFIX_ . 'pixel_audit_event` ORDER BY subject_id', true, false);
    $check(count($rows) === 2, 'A real switch must preserve both observed default flag changes.');
    foreach ($rows as $row) {
        $check((int) $row['id_shop'] === 1, 'Another shop must not be affected.');
        $changes = json_decode($row['changes'], true, 512, JSON_THROW_ON_ERROR);
        $check(array_keys($changes) === ['default_on'], 'Only the actual default flag must change.');
        $check($changes['default_on']['before'] === ((int) $row['subject_id'] === 49162 ? true : null), 'Initial default must come from before the reset.');
    }
    // The next collection cycle must use the new baseline and preserve unrelated real price changes.
    $audit->watch(Subject::product(3402, 1));
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_attribute_shop` SET default_on = NULL WHERE id_shop = 1');
    $audit->watch($second);
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_attribute_shop` SET default_on = 1, price = 12 WHERE id_product_attribute = 49163 AND id_shop = 1');
    $audit->flush();
    $last = $db->getRow('SELECT changes FROM `' . _DB_PREFIX_ . 'pixel_audit_event` ORDER BY id_event DESC', false);
    $changes = json_decode($last['changes'], true, 512, JSON_THROW_ON_ERROR);
    $check(array_keys($changes) === ['price'], 'The real price change must survive without a false default flag change.');
    echo 'OK: PrestaShop ' . _PS_VERSION_ . ' / PHP ' . PHP_VERSION . ': same default, real switch, shop scope, fresh baseline and unrelated price changes.' . PHP_EOL;
} finally {
    foreach (array_reverse($temporary) as $table) {
        $db->execute('DROP TEMPORARY TABLE `' . _DB_PREFIX_ . $table . '`');
    }
}
