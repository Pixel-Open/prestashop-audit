<?php

declare(strict_types=1);

use Pixel\Module\Audit\Storage\Repository;
use Pixel\Module\Audit\Configuration\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'pixel_audit/vendor/autoload.php';

class AdminPixelAuditController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        if ($this->module instanceof Pixel_audit) {
            $this->addCSS($this->module->presentationStylesheet());
        }
    }

    public function postProcess()
    {
    } // Read only: no inherited add/edit/delete or AJAX dispatch.

    private function pageSize(): int
    {
        $cookie = $this->context->cookie;
        $saved = $cookie ? (int) $cookie->pixel_audit_page_size : 20;
        $default = in_array($saved, [20, 50, 100, 200], true) ? $saved : 20;
        $value = Tools::getValue('audit_page_size', $default);
        $size = is_scalar($value) ? (int) $value : $default;
        if (!in_array($size, [20, 50, 100, 200], true)) {
            return $default;
        }
        if ($cookie && $size !== $saved) {
            $cookie->pixel_audit_page_size = $size;
            $cookie->write();
        }
        return $size;
    }

    private function objectUrl(array $event): string
    {
        $targets = [
            'product' => ['Product', 'AdminProducts', 'id_product', 'updateproduct'],
            'category' => ['Category', 'AdminCategories', 'id_category', 'updatecategory'],
            'customer' => ['Customer', 'AdminCustomers', 'id_customer', 'viewcustomer'],
            'address' => ['Address', 'AdminAddresses', 'id_address', 'updateaddress'],
            'order' => ['Order', 'AdminOrders', 'id_order', 'vieworder'],
            'employee' => ['Employee', 'AdminEmployees', 'id_employee', 'updateemployee'],
            'profile' => ['Profile', 'AdminProfiles', 'id_profile', 'updateprofile'],
        ];
        $type = (string) $event['root_type'];
        $id = (int) $event['root_id'];
        if ($id < 1 || !isset($targets[$type])) {
            return '';
        }
        [$model, $controller, $parameter, $action] = $targets[$type];
        $employee = $this->context->employee;
        if (!$employee || (!$employee->isSuperAdmin() && !$employee->can('view', $controller))) {
            return '';
        }
        $object = new $model($id);
        if (!Validate::isLoadedObject($object) || !empty($object->deleted)) {
            return '';
        }
        $shop = (int) $event['id_shop'];
        $definition = ObjectModel::getDefinition($model);
        if (Shop::isTableAssociated($definition['table']) && ($shop < 1 || !$object->isAssociatedToShop($shop))) {
            return '';
        }
        if (!empty($object->id_shop) && (int) $object->id_shop !== $shop) {
            return '';
        }
        return $this->context->link->getAdminLink($controller, true, [], [$parameter => $id, $action => 1]);
    }

    public function initContent()
    {
        if (!$this->viewAccess()) {
            parent::initContent();
            return;
        }
        $pageSize = $this->pageSize();
        $this->display = '';
        parent::initContent();
        $diagnostics = (bool) Tools::getValue('audit_diagnostics');
        if (Tools::getValue('audit_about') || $diagnostics) {
            $this->context->smarty->assign([
                'audit_journal_url' => $this->context->link->getAdminLink('AdminPixelAudit'),
                'audit_settings_url' => $this->context->link->getAdminLink('AdminPixelAuditSettings'),
                'audit_about_url' => $this->context->link->getAdminLink('AdminPixelAudit') . '&audit_about=1',
                'audit_error' => Configuration::getGlobalValue(\Pixel\Module\Audit\Core\Diagnostics::KEY),
                'audit_diagnostics_url' => $this->context->link->getAdminLink('AdminPixelAudit') . '&audit_diagnostics=1',
                'audit_tables' => $diagnostics ? (new Repository())->storageDiagnostics() : [],
                'audit_version' => $this->module->version,
                'audit_author' => $this->module->author,
            ]);
            $this->context->smarty->assign('content', $this->module->display(_PS_MODULE_DIR_ . 'pixel_audit/pixel_audit.php', $diagnostics ? 'views/templates/admin/diagnostics.tpl' : 'views/templates/admin/about.tpl'));
            return;
        }
        $repository = new Repository();
        $shops = \Pixel\Module\Audit\Core\ShopScope::allowed(
            Shop::getContextListShopID(),
            $this->context->employee->getAssociatedShops(),
            $this->context->employee->isSuperAdmin()
        );
        $operationId = max(0, (int) Tools::getValue('operation_id', 0));
        $page = max(1, (int) Tools::getValue('audit_page', 1));
        $filters = [];
        foreach (['family', 'type', 'action', 'object', 'employee', 'from', 'to'] as $key) {
            $value = Tools::getValue('audit_' . $key, '');
            $filters[$key] = is_scalar($value) ? mb_substr((string) $value, 0, 64) : '';
        }
        $module = Module::getInstanceByName('pixel_audit');
        if (!$module instanceof Pixel_audit) {
            throw new RuntimeException('Audit module unavailable.');
        }
        $details = $operationId ? $repository->details($operationId, $shops) : [];
        $canRestore = $module->canRestoreProducts();
        $productShops = [];
        foreach ($details as &$event) {
            $event['object_url'] = $this->objectUrl($event);
            $root = (int) $event['root_id'];
            $event['restore_allowed'] = $canRestore && $event['restorable_fields']
                && in_array((int) $event['id_shop'], $productShops[$root] ??= $module->productHistoryShops($root), true);
        }
        unset($event);
        $url = $this->context->link->getAdminLink('AdminPixelAudit');
        $result = $repository->search($filters, $shops, $page, $pageSize);
        $query = ['audit_page_size' => $pageSize];
        foreach ($filters as $key => $value) {
            $query['audit_' . $key] = $value;
        }
        $queryUrl = $url . '&' . http_build_query($query);
        $this->context->smarty->assign([
            'audit_about_url' => $this->context->link->getAdminLink('AdminPixelAudit') . '&audit_about=1',
            'audit_diagnostics_url' => $this->context->link->getAdminLink('AdminPixelAudit') . '&audit_diagnostics=1',
            'audit_page_size' => $pageSize,
            'audit_result' => $result, 'audit_filters' => $filters, 'audit_page' => $page,
            'audit_details' => $details,
            'restore_url' => $this->context->link->getAdminLink('AdminPixelAuditRestore') . '&ajax=1',
            'restore_csrf' => $canRestore ? $module->adminCsrf('product_restore') : '',
            'audit_restore_script' => $canRestore ? $module->getPathUri() . 'views/js/journal-restoration.js?v=' . $module->version : '',
            'audit_types' => \Pixel\Module\Audit\Presentation\Presenter::TYPES,
            'audit_actions' => \Pixel\Module\Audit\Presentation\Presenter::ACTIONS,
            'audit_back_url' => $queryUrl,
            'audit_operation_id' => $operationId, 'audit_viewing' => $operationId > 0, 'audit_url' => $url, 'audit_token' => $this->token,
            'audit_previous' => $queryUrl . '&audit_page=' . ($page - 1),
            'audit_next' => $queryUrl . '&audit_page=' . ($page + 1),
            'audit_settings_url' => $this->context->link->getAdminLink('AdminPixelAuditSettings'),
            'audit_translations_json' => \Pixel\Module\Audit\Presentation\I18n::javascript(), 'audit_families' => \Pixel\Module\Audit\Presentation\I18n::labels(Settings::FAMILIES + $module->registry()->families()),
        ]);
        $this->context->smarty->assign('content', $this->module->display(_PS_MODULE_DIR_ . 'pixel_audit/pixel_audit.php', 'views/templates/admin/journal.tpl'));
    }
}
