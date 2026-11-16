<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\EventSubscriber;

use Pixel\Module\Audit\Core\Diagnostics;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Display hooks may be hidden by module-view permissions; product-save correlation is independent of journal access. */
final class ProductAssetsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', -100], KernelEvents::RESPONSE => ['onResponse', -100]];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->getRequestType() !== 1 || $request->isMethodSafe() || $request->attributes->get('_legacy_controller') !== 'AdminProducts') {
            return;
        }
        try {
            $product = (int) ($request->attributes->get('productId') ?? $request->attributes->get('idProduct')
                ?? ($request->attributes->get('_route') === 'admin_product_form' ? $request->attributes->get('id') : null)
                ?? $request->query->get('id_product') ?? $request->request->get('id_product', 0));
            if ($product < 1) {
                foreach (['productImageId' => ['image', 'id_image'], 'combinationId' => ['product_attribute', 'id_product_attribute']] as $attribute => [$table, $primary]) {
                    $id = (int) $request->attributes->get($attribute, 0);
                    if ($id > 0) {
                        $product = (int) \Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . $table . '` WHERE `' . $primary . '` = ' . $id, false);
                        break;
                    }
                }
            }
            if ($product < 1) {
                return;
            }
            $module = \Module::isEnabled('pixel_audit') ? \Module::getInstanceByName('pixel_audit') : null;
            if ($module instanceof \Pixel_audit) {
                // Capture before SQL resets, relation replacement and catalog-rule regeneration.
                $module->audit()?->watch(\Pixel\Module\Audit\Api\Subject::product($product, (int) \Shop::getContextShopID()));
            }
        } catch (\Throwable $error) {
            Diagnostics::report($error);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        // Primary request has value 1 on both Symfony 4.4 (PS 8.2) and Symfony 6 (PS 9).
        if ($event->getRequestType() !== 1) {
            return;
        }
        $response = $event->getResponse();
        if ($response->getStatusCode() !== 200 || !str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }
        $html = $response->getContent();
        if (!is_string($html) || ($position = stripos($html, '</head>')) === false) {
            return;
        }
        try {
            if (!\Module::isEnabled('pixel_audit')) {
                return;
            }
            $module = \Module::getInstanceByName('pixel_audit');
            if (!$module instanceof \Pixel_audit) {
                return;
            }
            $assets = '';
            if (!str_contains($html, 'id="pixel-audit-history-config"')) {
                $request = $event->getRequest();
                // The legacy product editor does not invoke the modern form builder hook.
                $productId = $request->attributes->get('_route') === 'admin_product_form'
                    ? (int) $request->attributes->get('id') : null;
                $assets .= $module->productHistoryAssets($productId);
            }
            if (!str_contains($html, 'id="pixel-audit-product-config"')) {
                $assets .= $module->hookDisplayBackOfficeHeader();
            }
            if ($assets === '') {
                return;
            }
            $response->setContent(substr_replace($html, $assets, $position, 0));
            $response->headers->set('Cache-Control', 'private, no-store');
        } catch (\Throwable $error) {
            Diagnostics::report($error);
        }
    }
}
