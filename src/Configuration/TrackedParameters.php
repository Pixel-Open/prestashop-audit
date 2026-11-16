<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Configuration;

use Pixel\Module\Audit\Api\ConfigurationParameter;

/** Deliberately curated list. SMTP credentials, technical counters and arbitrary module settings are absent. */
final class TrackedParameters
{
    public static function definitions(): array
    {
        $result = [];
        $add = static function (string $owner, array $items) use (&$result): void {
            foreach ($items as $key => $spec) {
                $result[] = new ConfigurationParameter($key, $owner, $spec[0], $spec[1], $spec[2] ?? false, $spec[3] ?? true);
            }
        };
        $add('prestashop', [
            'PS_SHOP_ENABLE' => ['Shop enabled', 'boolean'],
            'PS_SHOP_NAME' => ['Shop name', 'text'],
            'PS_SHOP_EMAIL' => ['Public shop email address', 'text'],
            'PS_MAINTENANCE_TEXT' => ['Maintenance message', 'text', true],
            'PS_CATALOG_MODE' => ['Catalog mode', 'boolean'],
            'PS_STOCK_MANAGEMENT' => ['Stock management', 'boolean'],
            'PS_ORDER_OUT_OF_STOCK' => ['Allow orders for out-of-stock products', 'boolean'],
            'PS_GUEST_CHECKOUT_ENABLED' => ['Guest checkout', 'boolean'],
            'PS_B2B_ENABLE' => ['B2B mode', 'boolean'],
            'PS_PURCHASE_MINIMUM' => ['Minimum order amount', 'decimal'],
            'PS_TAX' => ['Taxes enabled', 'boolean'],
            'PS_CURRENCY_DEFAULT' => ['Default currency', 'integer'],
            'PS_LANG_DEFAULT' => ['Default language', 'integer'],
            'PS_COUNTRY_DEFAULT' => ['Default country', 'integer'],
            'PS_CARRIER_DEFAULT' => ['Default carrier', 'integer'],
            'PS_CARRIER_DEFAULT_SORT' => ['Carrier sorting by price or position', 'integer'],
            'PS_CARRIER_DEFAULT_ORDER' => ['Carrier sort order', 'integer'],
            'PS_SHIPPING_HANDLING' => ['Handling charges', 'decimal'],
            'PS_SHIPPING_FREE_PRICE' => ['Free shipping amount threshold', 'decimal'],
            'PS_SHIPPING_FREE_WEIGHT' => ['Free shipping weight threshold', 'decimal'],
            'PS_ORDER_RETURN' => ['Merchandise returns', 'boolean'],
            'PS_ORDER_RETURN_NB_DAYS' => ['Return period', 'integer'],
            'PS_RECYCLABLE_PACK' => ['Recycled packaging', 'boolean'],
            'PS_GIFT_WRAPPING' => ['Gift wrapping', 'boolean'],
            'PS_GIFT_WRAPPING_PRICE' => ['Gift wrapping price', 'decimal'],
            'PS_CONDITIONS' => ['Require acceptance of terms and conditions', 'boolean'],
            'PS_CONDITIONS_CMS_ID' => ['Terms and conditions page', 'integer'],
            'PS_INVOICE' => ['Invoice generation', 'boolean'],
            'PS_INVOICE_PREFIX' => ['Invoice prefix', 'text', true],
            'PS_INVOICE_USE_YEAR' => ['Include year in invoice number', 'boolean'],
            'PS_MAIL_METHOD' => ['Email delivery method', 'integer'],
            'PS_MAIL_TYPE' => ['Email format', 'integer'],
        ]);
        return $result;
    }
}
