<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Presentation;

/** Plain text only. HTML escaping remains the responsibility of the templates. */
final class Presenter
{
    public const TYPES = [
        'product' => 'Product', 'combination' => 'Combination', 'specific_price' => 'Specific price',
        'stock' => 'Stock', 'category' => 'Category', 'customer' => 'Customer', 'address' => 'Address',
        'order' => 'Order', 'order_history' => 'Order status', 'order_detail' => 'Order line',
        'order_carrier' => 'Shipment', 'order_payment' => 'Payment', 'order_slip' => 'Credit slip', 'employee' => 'Employee', 'profile' => 'Profile',
        'image' => 'Image', 'attachment' => 'Document', 'product_download' => 'Downloadable file',
        'configuration_change' => 'Shop and module settings', 'configuration' => 'Global values',
        'configuration_shop' => 'Shop configuration', 'configuration_group' => 'Shop group configuration',
        'audit_settings' => 'Audit settings', 'employee_login' => 'Back-office login',
    ];
    public const ACTIONS = ['create' => 'Creation', 'update' => 'Update', 'delete' => 'Deletion', 'login' => 'Login'];

    public static function subject(string $type, int $id): string
    {
        if ($type === 'configuration_change') {
            return I18n::trans('Shop and module settings');
        }
        if ($type === 'configuration') {
            return 'Global values';
        }
        return I18n::trans(self::TYPES[$type] ?? $type) . ' #' . $id;
    }

    public static function subjects(string $subjects): string
    {
        return implode(', ', array_map(static function (string $subject): string {
            return preg_match('/^([a-z][a-z0-9_]*) #(\d+)$/D', $subject, $matches)
                ? self::subject($matches[1], (int) $matches[2]) : $subject;
        }, explode(', ', $subjects)));
    }

    public static function value(mixed $value, string $type): string
    {
        if ($value === null) {
            return '∅';
        }
        if ($type === 'boolean') {
            return I18n::trans($value ? 'Yes' : 'No');
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }
        return (string) $value;
    }
}
