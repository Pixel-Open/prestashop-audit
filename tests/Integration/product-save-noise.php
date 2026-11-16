<?php

declare(strict_types=1);

// SQL fixtures shadow shop tables for this connection; persisted data and history stay untouched.
require dirname(__DIR__, 4) . '/config/config.inc.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Pixel\Module\Audit\Api\Registry;
use Pixel\Module\Audit\Api\Subject;
use Pixel\Module\Audit\Collector\ObjectModelProvider;
use Pixel\Module\Audit\Collector\RelationsProvider;
use Pixel\Module\Audit\Configuration\Settings;
use Pixel\Module\Audit\Core\Audit;
use Pixel\Module\Audit\Storage\Repository;

$db = Db::getInstance();
$pdo = $db->getLink();
$temporary = [];
$module = null;
$previousAudit = null;
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
$insert = static function (string $table, array $values) use ($pdo): void {
    foreach ($pdo->query('SHOW COLUMNS FROM `' . _DB_PREFIX_ . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if ($column['Null'] === 'YES' || $column['Default'] !== null || str_contains($column['Extra'], 'auto_increment') || array_key_exists($column['Field'], $values)) {
            continue;
        }
        $type = $column['Type'];
        $values[$column['Field']] = str_starts_with($type, 'enum(') ? explode("'", $type)[1]
            : (preg_match('/int|decimal|float|double/', $type) ? 0 : (preg_match('/date|timestamp/', $type) ? '2026-01-01' : ''));
    }
    $sql = 'INSERT INTO `' . _DB_PREFIX_ . $table . '` (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($values));
};
try {
    foreach (['product', 'product_shop', 'product_lang', 'product_attribute', 'product_attribute_shop', 'product_attribute_lang', 'product_attribute_combination', 'product_attribute_image', 'image', 'image_shop', 'image_lang', 'feature_product', 'feature_value', 'feature_value_lang', 'category_product', 'product_carrier', 'product_attachment', 'accessory', 'product_tag', 'tag', 'pack', 'product_supplier', 'stock_available', 'product_download', 'pixel_audit_operation', 'pixel_audit_event'] as $table) {
        $schema = $pdo->query('SHOW CREATE TABLE `' . _DB_PREFIX_ . $table . '`')->fetch(PDO::FETCH_ASSOC);
        $execute(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $schema['Create Table']));
        $temporary[] = $table;
    }
    $insert('product', ['id_product' => 3402, 'date_add' => '2026-01-01', 'date_upd' => '2026-01-01']);
    foreach ([1, 2] as $shop) {
        $insert('product_shop', ['id_product' => 3402, 'id_shop' => $shop, 'price' => '12', 'date_add' => '2026-01-01', 'date_upd' => '2026-01-01']);
        $insert('product_lang', ['id_product' => 3402, 'id_shop' => $shop, 'id_lang' => 1, 'name' => 'Fixture', 'meta_title' => 'Original title', 'link_rewrite' => 'fixture']);
    }
    $insert('product_attribute', ['id_product_attribute' => 49162, 'id_product' => 3402, 'default_on' => 1]);
    foreach ([1, 2] as $shop) {
        $insert('product_attribute_shop', ['id_product_attribute' => 49162, 'id_product' => 3402, 'id_shop' => $shop, 'default_on' => 1, 'price' => '0']);
    }
    $insert('product_attribute_combination', ['id_product_attribute' => 49162, 'id_attribute' => 40]);
    foreach ([701, 702] as $image) {
        $insert('image', ['id_image' => $image, 'id_product' => 3402, 'position' => $image - 700, 'cover' => $image === 701 ? 1 : null]);
        foreach ([1, 2] as $shop) {
            $insert('image_shop', ['id_image' => $image, 'id_product' => 3402, 'id_shop' => $shop, 'cover' => $image === 701 ? 1 : null]);
        }
        $insert('image_lang', ['id_image' => $image, 'id_lang' => 1, 'legend' => 'Original caption']);
        $insert('product_attribute_image', ['id_product_attribute' => 49162, 'id_image' => $image]);
    }
    foreach ([10, 20, 30] as $value) {
        $insert('feature_value', ['id_feature_value' => $value, 'id_feature' => 3, 'custom' => 1]);
        foreach ([1 => 'Cotton', 2 => 'Coton'] as $lang => $text) {
            $insert('feature_value_lang', ['id_feature_value' => $value, 'id_lang' => $lang, 'value' => $value === 30 && $lang === 2 ? 'Lin' : $text]);
        }
    }
    $insert('feature_product', ['id_product' => 3402, 'id_feature' => 3, 'id_feature_value' => 10]);
    $insert('tag', ['id_tag' => 5, 'id_lang' => 1, 'name' => 'Fixture tag']);
    $insert('product_tag', ['id_product' => 3402, 'id_tag' => 5, 'id_lang' => 1]);
    foreach ([2, 3] as $category) {
        $insert('category_product', ['id_product' => 3402, 'id_category' => $category, 'position' => 0]);
    }
    $insert('product_supplier', ['id_product_supplier' => 1, 'id_product' => 3402, 'id_product_attribute' => 0, 'id_supplier' => 1, 'product_supplier_price_te' => '12', 'id_currency' => 1]);
    $insert('stock_available', ['id_stock_available' => 80, 'id_product' => 3402, 'id_product_attribute' => 49162, 'id_shop' => 1, 'quantity' => 10]);
    $insert('product_download', ['id_product_download' => 90, 'id_product' => 3402, 'display_filename' => 'Manual.pdf', 'filename' => 'old-storage-key', 'date_add' => '2026-01-01']);
    $settings = new Settings();
    $values = $settings->defaults();
    $values['families'] = ['products' => true, 'combinations' => true, 'stocks' => true];
    Configuration::set(Settings::KEY, json_encode($values), 0, 0);
    $registry = new Registry([]);
    foreach (['Product' => ['product', 'products'], 'Combination' => ['combination', 'combinations'], 'Image' => ['image', 'products'], 'StockAvailable' => ['stock', 'stocks'], 'ProductDownload' => ['product_download', 'products']] as $class => [$type, $family]) {
        $registry->add(new ObjectModelProvider($class, $type, $family, 'native.' . $type), $family);
    }
    $registry->add(new RelationsProvider('product', 'products'), 'Products');
    $registry->add(new RelationsProvider('combination', 'combinations'), 'Combinations');
    $audit = new Audit($registry, $settings, new Repository(), ['id' => 1, 'name' => 'Fixture', 'origin' => 'cli', 'route' => 'fixture']);
    $product = Subject::product(3402, 1);
    $combination = new Subject('combination', 49162, 1, 'product', 3402);
    $image = new Subject('image', 701, 1, 'product', 3402);
    $download = new Subject('product_download', 90, 1, 'product', 3402);
    $stock = new Subject('stock', 80, 1, 'product', 3402);
    $module = Module::getInstanceByName('pixel_audit');
    $property = new ReflectionProperty($module, 'auditService');
    $property->setAccessible(true);
    $previousAudit = $property->getValue($module);
    $property->setValue($module, $audit);
    // Exercise the early legacy hook rather than relying on a later ObjectModel hook.
    $controller = new class extends AdminController {
        public function __construct() { $this->controller_name = 'AdminProducts'; }
    };
    $post = $_POST;
    $method = $_SERVER['REQUEST_METHOD'] ?? null;
    try {
        $_POST['id_product'] = 3402;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $module->hookActionAdminControllerInitAfter(['controller' => $controller]);
    } finally {
        $_POST = $post;
        if ($method === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $method; }
    }
    // No-op save: temporary flags/relations, custom value IDs and storage metadata all change.
    $execute('UPDATE `' . _DB_PREFIX_ . 'image_shop` SET cover = NULL WHERE id_shop = 1');
    $audit->watch($image);
    $execute('UPDATE `' . _DB_PREFIX_ . 'image_shop` SET cover = 1 WHERE id_image = 701 AND id_shop = 1');
    $execute('DELETE FROM `' . _DB_PREFIX_ . 'product_attribute_image`');
    $execute('DELETE FROM `' . _DB_PREFIX_ . 'product_attribute_combination`');
    $audit->watch($combination);
    $insert('product_attribute_combination', ['id_product_attribute' => 49162, 'id_attribute' => 40]);
    foreach ([702, 701] as $id) {
        $insert('product_attribute_image', ['id_product_attribute' => 49162, 'id_image' => $id]);
    }
    $execute('UPDATE `' . _DB_PREFIX_ . 'feature_product` SET id_feature_value = 20');
    $execute('DELETE FROM `' . _DB_PREFIX_ . 'category_product`');
    foreach ([3, 2] as $category) {
        $insert('category_product', ['id_product' => 3402, 'id_category' => $category, 'position' => 0]);
    }
    $audit->watch($stock);
    $audit->watch($download);
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_download` SET filename = "new-storage-key", date_add = "2026-02-01"');
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_shop` SET indexed = 1, cache_default_attribute = 49162, date_upd = "2026-02-01"');
    $audit->flush();
    $check((int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'pixel_audit_event`', false) === 0, 'Technical-only save must be silent.');
    // A Symfony image request identifies the parent through its image ID, before resetting its cover.
    $request = new \Symfony\Component\HttpFoundation\Request([], [], ['_legacy_controller' => 'AdminProducts', 'productImageId' => 701], [], [], ['REQUEST_METHOD' => 'PATCH']);
    $kernel = new \Symfony\Component\HttpKernel\HttpKernel(new \Symfony\Component\EventDispatcher\EventDispatcher(), new \Symfony\Component\HttpKernel\Controller\ControllerResolver());
    $event = new \Symfony\Component\HttpKernel\Event\ControllerEvent($kernel, static function (): void {}, $request, 1);
    (new \Pixel\Module\Audit\EventSubscriber\ProductAssetsSubscriber())->onController($event);
    // Real changes must survive the same technical resets.
    $execute('UPDATE `' . _DB_PREFIX_ . 'image_shop` SET cover = NULL WHERE id_shop = 1');
    $audit->watch($image);
    $audit->watch(new Subject('image', 702, 1, 'product', 3402));
    $execute('UPDATE `' . _DB_PREFIX_ . 'image_shop` SET cover = 1 WHERE id_image = 702 AND id_shop = 1');
    $execute('UPDATE `' . _DB_PREFIX_ . 'image_lang` SET legend = "New caption" WHERE id_image = 701');
    $execute('DELETE FROM `' . _DB_PREFIX_ . 'product_attribute_image`');
    $audit->watch($combination);
    $insert('product_attribute_image', ['id_product_attribute' => 49162, 'id_image' => 702]);
    $execute('UPDATE `' . _DB_PREFIX_ . 'feature_product` SET id_feature_value = 30');
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_lang` SET meta_title = "New title" WHERE id_shop = 1');
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_supplier` SET product_supplier_price_te = 15');
    $audit->watch($stock);
    $execute('UPDATE `' . _DB_PREFIX_ . 'stock_available` SET quantity = 11');
    $audit->watch($download);
    $execute('UPDATE `' . _DB_PREFIX_ . 'product_download` SET display_filename = "Updated manual.pdf"');
    $audit->flush();
    $rows = $db->executeS('SELECT provider, subject_id, id_shop, changes FROM `' . _DB_PREFIX_ . 'pixel_audit_event`', true, false);
    $byProvider = [];
    foreach ($rows as $row) {
        $byProvider[$row['provider']][$row['subject_id']] = json_decode($row['changes'], true, 512, JSON_THROW_ON_ERROR);
        $check((int) $row['id_shop'] === 1, 'Unobserved shops must not gain events.');
    }
    $check(isset($byProvider['native.product'][3402]['meta_title.lang_1']), 'Real meta title edit must be collected.');
    $check(isset($byProvider['native.product.relations'][3402]['supplier.1.0.price']), 'Real supplier price must be collected.');
    $check(isset($byProvider['native.product.relations'][3402]['features']), 'Changed custom translation must be collected.');
    $images = $byProvider['native.combination.relations'][49162]['images'];
    $check($images['before'] === [701, 702] && $images['after'] === [702], 'Combination image removal must retain its original before set.');
    $check($byProvider['native.image'][701]['cover']['before'] === true && $byProvider['native.image'][701]['cover']['after'] === null, 'Real old cover removal must be collected.');
    $check($byProvider['native.image'][702]['cover']['after'] === true, 'Real new cover must be collected.');
    $check(isset($byProvider['native.image'][701]['legend.lang_1']), 'Real image caption must be collected.');
    $check($byProvider['native.stock'][80]['quantity']['before'] === 10 && $byProvider['native.stock'][80]['quantity']['after'] === 11, 'Real stock quantity change must be collected.');
    $check(isset($byProvider['native.product_download'][90]['display_filename']), 'Real download display name must be collected.');
    echo 'OK: PrestaShop ' . _PS_VERSION_ . ' / PHP ' . PHP_VERSION . ': technical-only save silent; real metadata, custom features, images, supplier, stock and download edits preserved.' . PHP_EOL;
} finally {
    if ($module !== null) {
        $property->setValue($module, $previousAudit);
    }
    foreach (array_reverse($temporary) as $table) {
        $db->execute('DROP TEMPORARY TABLE `' . _DB_PREFIX_ . $table . '`');
    }
}
