<?php

declare(strict_types=1);

use Pixel\Module\Audit\Correlation\ProductOperation;

if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'pixel_audit/vendor/autoload.php';

/** Product editors need no permission to read the journal to transport an audit capability. */
class AdminPixelAuditOperationController extends ModuleAdminController
{
    /** PS 9's legacy router uses this override to honor viewAccess() for the private endpoint. */
    public function getTabSlug()
    {
        return 'TAB_ADMINPRODUCTS';
    }

    public function viewAccess($disable = false)
    {
        $module = Module::getInstanceByName('pixel_audit');
        return $module instanceof Pixel_audit && $module->active && $module->canCorrelateProducts();
    }

    public function postProcess()
    {
        $module = Module::getInstanceByName('pixel_audit');
        $csrf = Tools::getValue('audit_csrf');
        if (!$module instanceof Pixel_audit || !$this->viewAccess() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !is_string($csrf) || $module->adminCsrf('product_operations') === '' || !hash_equals($module->adminCsrf('product_operations'), $csrf)) {
            http_response_code(403);
            $this->respond(['error' => 'forbidden']);
        }
        $action = Tools::getValue('action');
        if ($action === 'tokens') {
            $tokens = $module->productOperationTokens((int) Tools::getValue('product_id'));
            if (!$tokens) {
                http_response_code(403);
            }
            $this->respond(['tokens' => $tokens]);
        } elseif ($action === 'close') {
            $token = Tools::getValue('operation');
            $claims = is_string($token) ? $module->verifyProductOperation($token) : null;
            if ($claims === null) {
                http_response_code(400);
                $this->respond(['error' => 'invalid']);
            }
            $closed = (new ProductOperation($claims))->close();
            if (!$closed) {
                http_response_code(409);
            }
            $this->respond(['closed' => $closed]);
        } else {
            http_response_code(400);
            $this->respond(['error' => 'invalid_action']);
        }
    }
    private function respond(array $data): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_THROW_ON_ERROR);
        exit;
    }
}
