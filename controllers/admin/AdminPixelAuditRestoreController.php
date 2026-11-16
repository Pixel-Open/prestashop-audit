<?php

declare(strict_types=1);

use Pixel\Module\Audit\Core\Diagnostics;
use Pixel\Module\Audit\Restoration\ProductRestore;
use Pixel\Module\Audit\Restoration\RestoreException;

if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'pixel_audit/vendor/autoload.php';

class AdminPixelAuditRestoreController extends ModuleAdminController
{
    public function getTabSlug()
    {
        return 'TAB_ADMINPIXELAUDITRESTORE';
    }
    public function viewAccess($disable = false)
    {
        $module = Module::getInstanceByName('pixel_audit');
        return $module instanceof Pixel_audit && $module->canRestoreProducts();
    }

    public function postProcess()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        try {
            if (!$this->viewAccess()) {
                throw new RestoreException('You do not have restoration permissions.', 403);
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                header('Allow: POST');
                throw new RestoreException('Confirmation requires a POST request.', 405);
            }
            $module = Module::getInstanceByName('pixel_audit');
            $csrf = Tools::getValue('restore_csrf');
            if (!$module instanceof Pixel_audit || !is_string($csrf) || $module->adminCsrf('product_restore') === ''
                || !hash_equals($module->adminCsrf('product_restore'), $csrf)) {
                throw new RestoreException('Invalid security token.', 403);
            }
            $product = filter_var(Tools::getValue('product_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$product) {
                throw new RestoreException('Invalid product.', 400);
            }
            $service = new ProductRestore($module);
            $action = Tools::getValue('action');
            if ($action === 'preview') {
                $event = filter_var(Tools::getValue('event_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $fields = Tools::getValue('fields');
                if (!$event || !is_array($fields)) {
                    throw new RestoreException('Invalid restoration selection.', 400);
                }
                $plan = $service->preview($product, $event, $fields);
                foreach ($plan['rows'] as &$row) {
                    $row['diff'] = \Pixel\Module\Audit\Presentation\VisualDiff::compare($row['current'], $row['target'], $row['type']);
                }
                unset($row);
                $this->context->smarty->assign([
                    'restore_plan' => $plan,
                    'restore_url' => $this->context->link->getAdminLink('AdminPixelAuditRestore') . '&ajax=1',
                    'restore_csrf' => $csrf, 'restore_product' => $product,
                ]);
                $this->respond($module->display(_PS_MODULE_DIR_ . 'pixel_audit/pixel_audit.php', 'views/templates/admin/restore-preview.tpl'));
            } elseif ($action === 'confirm') {
                $token = Tools::getValue('preview_token');
                if (!is_string($token)) {
                    throw new RestoreException('Missing preview.', 400);
                }
                $result = $service->apply($token, $product);
                header('X-Pixel-Audit-Restored: 1');
                $this->context->smarty->assign(['restore_result' => $result]);
                $this->respond($module->display(_PS_MODULE_DIR_ . 'pixel_audit/pixel_audit.php', 'views/templates/admin/restore-result.tpl'));
            }
            throw new RestoreException('Unknown action.', 400);
        } catch (RestoreException $error) {
            http_response_code($error->status);
            $this->notice($error->getMessage(), 'warning');
        } catch (Throwable $error) {
            Diagnostics::report($error);
            http_response_code(500);
            $this->notice(\Pixel\Module\Audit\Presentation\I18n::trans('Restoration failed. Check the current values before trying again.'), 'danger');
        }
    }

    private function notice(string $message, string $level): never
    {
        $this->context->smarty->assign(['audit_notice' => $message, 'audit_notice_level' => $level]);
        $this->respond($this->module->display(_PS_MODULE_DIR_ . 'pixel_audit/pixel_audit.php', 'views/templates/admin/notice.tpl'));
    }

    private function respond(string $html): never
    {
        header('X-Pixel-Audit-History: 1');
        echo $html;
        exit;
    }
}
