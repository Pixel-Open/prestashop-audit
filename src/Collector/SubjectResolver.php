<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Collector;

use Db;
use ObjectModel;
use Shop;
use Pixel\Module\Audit\Api\Subject;

final class SubjectResolver
{
    public const MODELS = [
        'Product' => ['product', 'products'], 'Combination' => ['combination', 'combinations'],
        'SpecificPrice' => ['specific_price', 'prices'], 'StockAvailable' => ['stock', 'stocks'],
        'Customer' => ['customer', 'customers'], 'Address' => ['address', 'addresses'],
        'Order' => ['order', 'orders'], 'OrderHistory' => ['order_history', 'orders'],
        'Category' => ['category', 'categories'], 'Employee' => ['employee', 'employees'],
        'Profile' => ['profile', 'employees'],
        'Image' => ['image', 'products'], 'Attachment' => ['attachment', 'products'],
        'ProductDownload' => ['product_download', 'products'],
        'OrderDetail' => ['order_detail', 'orders'], 'OrderPayment' => ['order_payment', 'orders'],
        'OrderSlip' => ['order_slip', 'orders'], 'OrderCarrier' => ['order_carrier', 'orders'],
    ];

    /** @return Subject[] */
    public function resolve(ObjectModel $object): array
    {
        if (!(int) $object->id) {
            return [];
        }
        $class = null;
        foreach (self::MODELS as $candidate => $meta) {
            if ($object instanceof $candidate) {
                $class = $candidate;
                break;
            }
        }
        if ($class === null) {
            return [];
        }
        // Cart-specific prices are ephemeral calculations, even during BO requests.
        if ($class === 'SpecificPrice' && !empty($object->id_cart)) {
            return [];
        }
        [$type] = self::MODELS[$class];
        if ($type === 'order_payment') {
            return $this->paymentSubjects($object);
        }
        if ($type === 'attachment') {
            return $this->attachmentSubjects($object);
        }
        $rootType = null;
        $rootId = null;
        if (in_array($type, ['combination', 'specific_price', 'stock', 'image', 'product_download'], true)) {
            $rootType = 'product';
            $rootId = (int) ($object->id_product ?? 0);
        } elseif (in_array($type, ['order_history', 'order_detail', 'order_slip', 'order_carrier'], true)) {
            $rootType = 'order';
            $rootId = (int) ($object->id_order ?? 0);
        }
        if ($rootType !== null && !$rootId) {
            return [];
        }
        $definition = ObjectModel::getDefinition($class);
        $public = get_object_vars($object);
        if (Shop::isTableAssociated($definition['table']) || !empty($definition['multilang_shop'])) {
            // Snapshot every shop potentially affected; a read on an unaffected shop is a no-op.
            $shops = array_unique(array_merge(array_map('intval', $object->id_shop_list ?: []), array_map('intval', Shop::getContextListShopID())));
        } elseif (isset($public['id_shop']) && (int) $public['id_shop'] > 0) {
            $shops = [(int) $public['id_shop']];
        } elseif ($type === 'address' && !empty($public['id_customer'])) {
            $shops = [(int) Db::getInstance()->getValue('SELECT id_shop FROM `' . _DB_PREFIX_ . 'customer` WHERE id_customer = ' . (int) $public['id_customer'], false)];
        } elseif ($type === 'stock' && !empty($public['id_shop_group'])) {
            $rows = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE id_shop_group = ' . (int) $public['id_shop_group'], true, false) ?: [];
            $shops = array_map('intval', array_column($rows, 'id_shop'));
        } elseif (in_array($type, ['order_history', 'order_detail', 'order_slip', 'order_carrier'], true)) {
            $shops = [(int) Db::getInstance()->getValue('SELECT id_shop FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ' . $rootId, false)];
        } elseif ($rootType === 'product') {
            $rows = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . $rootId, true, false) ?: [];
            $shops = array_map('intval', array_column($rows, 'id_shop'));
        } else {
            $shops = [0]; // A shared entity, not an invented association to the current shop.
        }
        if (in_array($type, ['address', 'order_history', 'order_detail', 'order_slip', 'order_carrier'], true) && $shops === [0]) {
            return [];
        }
        return array_map(static fn ($shop) => new Subject($type, (int) $object->id, (int) $shop, $rootType, $rootId), $shops);
    }

    /** One payment may fund several split orders, even within the same shop. */
    private function paymentSubjects(ObjectModel $payment): array
    {
        $references = [(string) ($payment->order_reference ?? '')];
        $stored = Db::getInstance()->getValue('SELECT order_reference FROM `' . _DB_PREFIX_ . 'order_payment` WHERE id_order_payment = ' . (int) $payment->id, false);
        if (is_string($stored)) {
            $references[] = $stored;
        }
        $references = array_unique(array_filter($references, static fn ($value) => $value !== ''));
        if (!$references) {
            return [];
        }
        $quoted = array_map(static fn ($reference) => "'" . pSQL($reference) . "'", $references);
        $rows = Db::getInstance()->executeS('SELECT id_order, id_shop FROM `' . _DB_PREFIX_ . 'orders` WHERE reference IN (' . implode(',', $quoted) . ')', true, false) ?: [];
        return array_map(static fn ($row) => new Subject('order_payment', (int) $payment->id, (int) $row['id_shop'], 'order', (int) $row['id_order']), $rows);
    }

    private function attachmentSubjects(ObjectModel $attachment): array
    {
        $rows = Db::getInstance()->executeS('SELECT pa.id_product, ps.id_shop FROM `' . _DB_PREFIX_ . 'product_attachment` pa INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = pa.id_product WHERE pa.id_attachment = ' . (int) $attachment->id, true, false) ?: [];
        return array_map(static fn ($row) => new Subject('attachment', (int) $attachment->id, (int) $row['id_shop'], 'product', (int) $row['id_product']), $rows);
    }
}
