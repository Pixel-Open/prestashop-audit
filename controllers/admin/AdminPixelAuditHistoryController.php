<?php

declare(strict_types=1);

use Pixel\Module\Audit\Core\Diagnostics;
use Pixel\Module\Audit\Storage\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'pixel_audit/vendor/autoload.php';

/** Read-only fragments; never dispatch inherited admin write/AJAX actions. */
class AdminPixelAuditHistoryController extends ModuleAdminController
{
    // PS 9's legacy router honors custom viewAccess for controllers declaring their permission scope.
    public function getTabSlug()
    {
        return 'TAB_ADMINPIXELAUDIT';
    }

    public function viewAccess($disable = false)
    {
        $module = Module::getInstanceByName('pixel_audit');
        return $module instanceof Pixel_audit && $module->canReadProductHistory();
    }

    public function postProcess()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if (!$this->viewAccess()) {
            $this->fail(403);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            header('Allow: GET');
            $this->fail(405);
        }
        try {
            $module = Module::getInstanceByName('pixel_audit');
            $id = max(0, (int) Tools::getValue('product_id'));
            if (!$module instanceof Pixel_audit || !($shops = $module->productHistoryShops($id))) {
                $this->fail(404);
            }
            $family = Tools::getValue('family', '');
            if (!is_string($family) || ($family !== '' && !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $family))) {
                $this->fail(400);
            }
            $operation = max(0, (int) Tools::getValue('operation_id'));
            $result = (new Repository())->productHistory($id, $shops, max(1, (int) Tools::getValue('history_page', 1)), $family, $operation);
            $url = $this->context->link->getAdminLink('AdminPixelAuditHistory') . '&ajax=1&product_id=' . $id . '&family=' . rawurlencode($family);
            if ($operation) {
                $url .= '&operation_id=' . $operation;
            }
            $this->context->smarty->assign([
                'restore_allowed' => $module->canRestoreProducts(),
                'restore_url' => $this->context->link->getAdminLink('AdminPixelAuditRestore') . '&ajax=1',
                'restore_csrf' => $module->canRestoreProducts() ? $module->adminCsrf('product_restore') : '',
                'history' => $result, 'history_product' => $id, 'history_operation' => $operation,
                'history_family' => $family, 'history_url' => $url,
                'history_previous' => $url . '&history_page=' . ($result['page'] - 1),
                'history_next' => $url . '&history_page=' . ($result['page'] + 1),
                'history_families' => \Pixel\Module\Audit\Presentation\I18n::labels($module->registry()->families()),
                'history_journal' => $this->context->link->getAdminLink('AdminPixelAudit') . '&audit_type=product&audit_object=' . $id,
            ]);
            header('X-Pixel-Audit-History: 1');
            echo $module->display(_PS_MODULE_DIR_ . 'pixel_audit/pixel_audit.php', 'views/templates/admin/product-history.tpl');
            exit;
        } catch (Throwable $error) {
            Diagnostics::report($error);
            $this->fail(500);
        }
    }

    private function fail(int $status): never
    {
        http_response_code($status);
        echo \Pixel\Module\Audit\Presentation\I18n::trans('History unavailable in this context.');
        exit;
    }
}
