<?php

declare(strict_types=1);

use Pixel\Module\Audit\Api\Registry;
use Pixel\Module\Audit\Collector\ObjectModelProvider;
use Pixel\Module\Audit\Collector\RelationsProvider;
use Pixel\Module\Audit\Collector\SubjectResolver;
use Pixel\Module\Audit\Configuration\Settings;
use Pixel\Module\Audit\Core\Audit;
use Pixel\Module\Audit\Core\Diagnostics;
use Pixel\Module\Audit\Storage\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__ . '/vendor/autoload.php';

class Pixel_audit extends Module
{
    private const LAST_PURGE_KEY = 'PIXEL_AUDIT_LAST_PURGE';
    private const AUTO_PURGE_INTERVAL = 3600;
    private const AUTO_PURGE_BATCH_SIZE = 500;
    private const AUTO_PURGE_MAX_BATCHES = 2;

    private bool $restoringProduct = false;
    /** Static: the shutdown collector may belong to another instance of the module. */
    private static bool $dataDeleted = false;
    /** @var array<int, int[]> Address IDs by customer erased during this request. */
    private array $erasedCustomers = [];
    private ?Audit $auditService = null;
    private ?Registry $providerRegistry = null;
    private ?\Pixel\Module\Audit\Presentation\FieldLabels $fieldLabels = null;
    private bool $registering = false;
    private int $productPageId = 0;
    private ?\Pixel\Module\Audit\Configuration\ConfigurationObserver $configurationObserver = null;
    private bool $observingConfiguration = false;
    private array $configurationObjects = [];
    private ?\Pixel\Module\Audit\Configuration\NativeConfigurationActions $nativeConfigurationActions = null;

    public function __construct()
    {
        $this->name = 'pixel_audit';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Pixel Open';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.2.0', 'max' => '9.99.99'];
        parent::__construct();
        $this->displayName = $this->trans('Audit log', [], 'Modules.Pixelaudit.Admin');
        $this->description = $this->trans('Back-office audit log, retention and provider API.', [], 'Modules.Pixelaudit.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        if (PHP_VERSION_ID < 80100) {
            $this->_errors[] = \Pixel\Module\Audit\Presentation\I18n::trans('PHP 8.1 or later is required.');
            return false;
        }
        if (!(Db::getInstance()->getLink() instanceof PDO)) {
            $this->_errors[] = \Pixel\Module\Audit\Presentation\I18n::trans('The PDO MySQL driver is required to isolate audit transactions.');
            return false;
        }
        if (!parent::install()) {
            return false;
        }
        try {
            $installed = (new Repository())->install()
                && (Configuration::hasKey(Settings::KEY, null, 0, 0) || (new Settings())->save((new Settings())->defaults()))
                && $this->installTabs();
            // No hook registration here: parent::install() calls enable(), which registers them. A second
            // pass in the same request fails on a new shop, where the core hook id cache misses the hooks just created.
            if ($installed) {
                return true;
            }
        } catch (Throwable $error) {
            Diagnostics::report($error);
            $this->_errors[] = \Pixel\Module\Audit\Presentation\I18n::trans('Audit installation failed. Check the PHP logs.');
        }
        $this->uninstall();
        return false;
    }

    /** Shared by installation and upgrades; hooks exist on the PS 8.2/9 form handler. */
    public function registerAuditHooks(): bool
    {
        return $this->registerHook([
            'actionObjectUpdateBefore', 'actionObjectDeleteBefore', 'actionObjectAddAfter',
            'actionPixelAuditRegisterProviders', 'actionBeforeUpdateProductFormHandler',
            'actionBeforeUpdateCombinationFormFormHandler', 'actionBeforeUpdateCombinationFormHandler',
            'actionBeforeUpdateCustomerFormHandler', 'actionBeforeUpdateAddressFormHandler',
            'actionBeforeUpdateCategoryFormHandler', 'actionAdminUpdateAccessBefore', 'actionAdminUpdateModuleAccessBefore',
            'actionProductFormBuilderModifier', 'displayBackOfficeHeader',
            'actionAdminControllerInitAfter',
            'actionPixelAuditConfigurationBefore', 'actionPixelAuditConfigurationAfter',
            'actionObjectAddBefore', 'actionObjectUpdateAfter', 'actionObjectDeleteAfter',
            'actionAdminUpdate_optionsBefore', 'actionAdminUpdate_optionsAfter',
            'actionAdminFinish_stepBefore', 'actionAdminFinish_stepAfter', 'actionCarrierUpdate',
            'actionDeleteGDPRCustomer', 'actionExportGDPRData', 'actionAdminLoginControllerLoginAfter',
        ]);
    }

    public function hookActionAdminLoginControllerLoginAfter(array $params): void
    {
        $employee = $params['employee'] ?? null;
        if ($employee instanceof Employee && Validate::isLoadedObject($employee)) {
            // Proxy-aware, like Request::getClientIp() in the PrestaShop 9 subscriber.
            $this->recordEmployeeLogin((int) $employee->id, (string) Tools::getRemoteAddr());
        }
    }

    public function recordEmployeeLogin(int $employeeId, string $ip): void
    {
        try {
            $settings = new Settings();
            if (!$this->active || self::$dataDeleted || !$settings->loginHistoryEnabled()) {
                return;
            }
            $employee = new Employee($employeeId);
            if (!Validate::isLoadedObject($employee) || !$employee->active) {
                return;
            }
            $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
            (new Repository())->append([[
                'operation' => bin2hex(random_bytes(16)),
                'subject' => new \Pixel\Module\Audit\Api\Subject('employee_login', $employeeId),
                'family' => 'employees', 'provider' => 'native.employee_login', 'action' => 'login',
                'changes' => ['ip_address' => ['label' => 'IP address', 'type' => 'text', 'before' => null, 'after' => $ip]],
            ]], [
                'id' => $employeeId, 'name' => trim($employee->firstname . ' ' . $employee->lastname),
                'origin' => 'backoffice', 'route' => 'employee_login',
            ], 'complete', $settings);
            $this->purgeIfDue();
        } catch (Throwable $error) {
            Diagnostics::report($error);
        }
    }

    public function hookActionBeforeUpdateProductFormHandler(array $params): void
    {
        $this->observeForm('Product', $params);
    }

    public function hookActionAdminControllerInitAfter(array $params): void
    {
        $controller = $params['controller'] ?? null;
        if (!$controller instanceof AdminController || $controller->controller_name !== 'AdminProducts') {
            return;
        }
        $action = Tools::getValue('action', '');
        $action = is_string($action) ? strtolower(str_replace('_', '', $action)) : '';
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
            && !in_array($action, ['updatecover', 'editproductattribute', 'defaultproductattribute'], true)) {
            return;
        }
        try {
            $product = (int) Tools::getValue('id_product');
            if ($product < 1) {
                foreach (['id_image' => ['image', 'id_image'], 'id_product_attribute' => ['product_attribute', 'id_product_attribute']] as $parameter => [$table, $primary]) {
                    $id = (int) Tools::getValue($parameter);
                    if ($id > 0) {
                        $product = (int) Db::getInstance()->getValue('SELECT id_product FROM `' . _DB_PREFIX_ . $table . '` WHERE `' . $primary . '` = ' . $id, false);
                        break;
                    }
                }
            }
            if ($product > 0) {
                $this->audit()?->watch(\Pixel\Module\Audit\Api\Subject::product($product, (int) Shop::getContextShopID()));
            }
        } catch (Throwable $error) {
            Diagnostics::report($error);
        }
    }
    public function hookActionBeforeUpdateCombinationFormFormHandler(array $params): void
    {
        $this->observeForm('Combination', $params);
    }
    public function hookActionBeforeUpdateCombinationFormHandler(array $params): void
    {
        $this->observeForm('Combination', $params);
    }
    public function hookActionBeforeUpdateCustomerFormHandler(array $params): void
    {
        $this->observeForm('Customer', $params);
    }
    public function hookActionBeforeUpdateAddressFormHandler(array $params): void
    {
        $this->observeForm('Address', $params);
    }
    public function hookActionBeforeUpdateCategoryFormHandler(array $params): void
    {
        $this->observeForm('Category', $params);
    }

    public function hookActionAdminUpdateAccessBefore(array $params): void
    {
        $this->observePermissions($params);
    }
    public function hookActionAdminUpdateModuleAccessBefore(array $params): void
    {
        $this->observePermissions($params);
    }

    private function observePermissions(array $params): void
    {
        if (($params['controller'] ?? null) instanceof AdminAccessController) {
            $this->observeForm('Profile', ['id' => Tools::getValue('id_profile')]);
        }
    }

    private function observeForm(string $class, array $params): void
    {
        try {
            if ($this->audit() === null || empty($params['id'])) {
                return;
            }
            $object = new $class((int) $params['id']);
            if (Validate::isLoadedObject($object)) {
                $this->observe(['object' => $object], false);
            }
        } catch (Throwable $error) {
            Diagnostics::report($error);
        }
    }

    /** Called by the psgdpr module, with the customer cast to an array or with an email alone. */
    public function hookActionDeleteGDPRCustomer(array $customer): void
    {
        try {
            $id = (int) ($customer['id'] ?? 0);
            if ($id < 1) {
                return; // An email without an account has no audited subject.
            }
            $this->erasedCustomers[$id] = $this->customerAddressIds($id);
            (new Repository())->erasePersonalData($id, $this->erasedCustomers[$id]);
        } catch (Throwable $error) {
            Diagnostics::report($error);
        }
    }

    public function hookActionExportGDPRData(array $customer): string
    {
        try {
            $id = (int) ($customer['id'] ?? 0);
            $events = $id > 0 ? (new Repository())->personalData($id, $this->customerAddressIds($id)) : [];
            return json_encode(\Pixel\Module\Audit\Privacy\CustomerExport::rows($events), JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            Diagnostics::report($error);
            return '[]';
        }
    }

    private function customerAddressIds(int $customerId): array
    {
        $rows = Db::getInstance()->executeS('SELECT id_address FROM `' . _DB_PREFIX_ . 'address` WHERE id_customer = ' . $customerId, true, false);
        if ($rows === false) {
            throw new RuntimeException('Cannot read customer addresses.');
        }
        return array_map('intval', array_column($rows, 'id_address'));
    }

    /** The deletion of the customer and addresses is itself observed: erase what the flush just wrote. */
    private function eraseObservedDeletions(): void
    {
        try {
            foreach ($this->erasedCustomers as $id => $addresses) {
                (new Repository())->erasePersonalData($id, $addresses);
            }
        } catch (Throwable $error) {
            Diagnostics::report($error);
        }
    }

    public function uninstall(): bool
    {
        // Tables and configuration are kept unless the administrator opted in: reinstalling must not erase evidence.
        $delete = (new Settings())->deleteOnUninstall();
        $ok = true;
        foreach (['AdminPixelAuditRestore', 'AdminPixelAuditHistory', 'AdminPixelAuditOperation', 'AdminPixelAuditSettings', 'AdminPixelAudit'] as $class) {
            $id = (int) Tab::getIdFromClassName($class);
            if ($id) {
                $ok = (new Tab($id))->delete() && $ok;
            }
        }
        return parent::uninstall() && $ok && (!$delete || $this->deleteData());
    }

    private function deleteData(): bool
    {
        // Nothing may be journaled or purged at shutdown once the tables are gone.
        self::$dataDeleted = true;
        $this->auditService = null;
        $ok = (new Repository())->uninstall();
        foreach ([Settings::KEY, Diagnostics::KEY, self::LAST_PURGE_KEY] as $key) {
            $ok = Configuration::deleteByName($key) && $ok;
        }
        return $ok;
    }

    public function installTabs(): bool
    {
        foreach (['AdminPixelAudit' => 'Audit log', 'AdminPixelAuditSettings' => 'Audit settings', 'AdminPixelAuditOperation' => 'Product audit operation', 'AdminPixelAuditHistory' => 'Product history', 'AdminPixelAuditRestore' => 'Product field restoration'] as $class => $label) {
            if (Tab::getIdFromClassName($class)) {
                $tab = new Tab((int) Tab::getIdFromClassName($class));
                $changed = false;
                foreach (Language::getLanguages(false) as $lang) {
                    $translated = \Pixel\Module\Audit\Presentation\I18n::trans($label, [], $lang['locale']);
                    if (($tab->name[(int) $lang['id_lang']] ?? null) !== $translated) {
                        $tab->name[(int) $lang['id_lang']] = $translated;
                        $changed = true;
                    }
                }
                if ($changed && !$tab->update()) {
                    return false;
                }
                continue;
            }
            $tab = new Tab();
            $tab->class_name = $class;
            $tab->module = $this->name;
            $tab->id_parent = $class === 'AdminPixelAudit' ? ((int) Tab::getIdFromClassName('AdminAdvancedParameters') ?: 0) : -1;
            $tab->active = true;
            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[(int) $lang['id_lang']] = \Pixel\Module\Audit\Presentation\I18n::trans($label, [], $lang['locale']);
            }
            if (!$tab->add()) {
                return false;
            }
            // Tab::initAccess also grants the installing employee's profile; restoration starts SuperAdmin-only.
            if ($class === 'AdminPixelAuditRestore' && !Db::getInstance()->execute('DELETE a FROM `' . _DB_PREFIX_ . 'access` a INNER JOIN `' . _DB_PREFIX_ . 'authorization_role` r ON r.id_authorization_role = a.id_authorization_role WHERE a.id_profile <> ' . (int) _PS_ADMIN_PROFILE_ . ' AND r.slug IN ("ROLE_MOD_TAB_ADMINPIXELAUDITRESTORE_CREATE", "ROLE_MOD_TAB_ADMINPIXELAUDITRESTORE_READ", "ROLE_MOD_TAB_ADMINPIXELAUDITRESTORE_UPDATE", "ROLE_MOD_TAB_ADMINPIXELAUDITRESTORE_DELETE")')) {
                return false;
            }
        }
        return $this->configureRestorationPermissionTab();
    }

    /** Native profile permissions need a parented tab; keep this POST-only endpoint out of the menu. */
    private function configureRestorationPermissionTab(): bool
    {
        $id = (int) Tab::getIdFromClassName('AdminPixelAuditRestore');
        if (!$id) {
            return true;
        }
        $tab = new Tab($id);
        $parent = (int) Tab::getIdFromClassName('AdminAdvancedParameters');
        if ((int) $tab->id_parent === $parent && !$tab->active) {
            return true;
        }
        $tab->id_parent = $parent;
        $tab->active = false;
        return (bool) $tab->update(); // Legacy ObjectModel may return the integer result of bitwise updates.
    }

    public function enable($forceAll = false): bool
    {
        return parent::enable($forceAll) && $this->configureRestorationPermissionTab() && $this->registerAuditHooks();
    }

    public function getContent(): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminPixelAuditSettings'));
    }

    public function registry(): Registry
    {
        if ($this->providerRegistry !== null) {
            return $this->providerRegistry;
        }
        $registry = new Registry(Settings::FAMILIES);
        $this->providerRegistry = $registry;
        foreach (SubjectResolver::MODELS as $class => [$type, $family]) {
            $registry->add(new ObjectModelProvider($class, $type, $family, 'native.' . $type), Settings::FAMILIES[$family]);
        }
        $registry->addProductRestoration(\Pixel\Module\Audit\Restoration\ProductFields::definition());
        foreach (['product' => 'products', 'combination' => 'combinations', 'customer' => 'customers', 'profile' => 'employees'] as $type => $family) {
            $registry->add(new RelationsProvider($type, $family), Settings::FAMILIES[$family]);
        }
        foreach (\Pixel\Module\Audit\Configuration\TrackedParameters::definitions() as $parameter) {
            if ($parameter->owner === 'prestashop' || Module::isEnabled($parameter->owner)) {
                $registry->addConfigurationParameter($parameter);
            }
        }
        $registry->add(new \Pixel\Module\Audit\Configuration\ConfigurationProvider($registry, new Settings()), Settings::FAMILIES['configuration']);
        $this->registering = true;
        try {
            Hook::exec('actionPixelAuditRegisterProviders', ['registry' => $registry]);
        } catch (Throwable $error) {
            Diagnostics::report($error);
        } finally {
            $this->registering = false;
        }
        return $registry;
    }

    /** Null outside an authenticated employee context; CLI callers must identify themselves explicitly. */
    public function audit(?string $processName = null): ?Audit
    {
        if (!$this->active || self::$dataDeleted || $this->registering || $this->restoringProduct) {
            return null;
        }
        if ($this->auditService !== null) {
            return $this->auditService;
        }
        $employee = $this->context->employee;
        $isEmployee = $employee && Validate::isLoadedObject($employee);
        if (!$isEmployee && !(PHP_SAPI === 'cli' && $processName !== null && trim($processName) !== '')) {
            return null;
        }
        $controller = Tools::getValue('controller', '');
        $route = is_string($controller) && preg_match('/^[a-z0-9_]+$/iD', $controller) ? $controller : '';
        $correlation = null;
        $token = $_SERVER['HTTP_X_PIXEL_AUDIT_OPERATION'] ?? Tools::getValue('_pixel_audit_operation', '');
        if ($isEmployee && is_string($token) && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $claims = $this->verifyProductOperation($token);
            if ($claims !== null) {
                $correlation = new \Pixel\Module\Audit\Correlation\ProductOperation($claims);
            }
        }
        $this->auditService = new Audit($this->registry(), new Settings(), new Repository(), [
            'id' => $isEmployee ? (int) $employee->id : 0,
            'name' => $isEmployee ? trim($employee->firstname . ' ' . $employee->lastname) : $processName,
            'origin' => PHP_SAPI === 'cli' ? 'cli' : 'backoffice', 'route' => $route,
        ], $correlation);
        register_shutdown_function(function (): void {
            $this->finishNativeConfigurationActions();
            $this->finishConfigurationObjects();
            $this->auditService?->flush();
            $this->eraseObservedDeletions();
            $this->purgeIfDue();
        });
        return $this->auditService;
    }

    /** Shops without a scheduled purge command still expire data: one small purge per hour, after an audited request. */
    private function purgeIfDue(): void
    {
        if (self::$dataDeleted) {
            return;
        }
        try {
            $link = Db::getInstance()->getLink();
            if (time() - (int) Configuration::getGlobalValue(self::LAST_PURGE_KEY) < self::AUTO_PURGE_INTERVAL || !$link instanceof PDO || $link->inTransaction()) {
                return;
            }
            Configuration::updateGlobalValue(self::LAST_PURGE_KEY, time());
            (new Repository())->purgeAll(self::AUTO_PURGE_BATCH_SIZE, self::AUTO_PURGE_MAX_BATCHES);
        } catch (Throwable $error) {
            Diagnostics::report($error);
        }
    }

    public function hookActionProductFormBuilderModifier(array $params): void
    {
        $this->productPageId = (int) ($params['id'] ?? 0);
        try {
            if (!$this->productHistoryShops($this->productPageId)) {
                return;
            }
            $builder = $params['form_builder'] ?? null;
            if (!$builder instanceof \Symfony\Component\Form\FormBuilderInterface) {
                return;
            }
            $button = [
                'type' => \PrestaShopBundle\Form\Admin\Type\IconButtonType::class,
                'options' => [
                    'type' => 'button', 'icon' => 'history', 'label' => \Pixel\Module\Audit\Presentation\I18n::trans('History'),
                    'attr' => ['title' => \Pixel\Module\Audit\Presentation\I18n::trans('Product history'), 'class' => 'toolbar-button pixel-audit-history-button',
                        'data-toggle' => 'sidebar', 'data-target' => '#pixel-audit-history-sidebar',
                        'aria-label' => \Pixel\Module\Audit\Presentation\I18n::trans('Product history'), 'aria-controls' => 'pixel-audit-history-sidebar', 'aria-expanded' => 'false'],
                ],
            ];
            // Rebuild the collection so its grouping view also knows about the added button.
            foreach ([[$builder, '_toolbar_buttons'], [$builder->has('header') ? $builder->get('header') : null, 'mobile_toolbar']] as [$parent, $name]) {
                if ($parent === null || !$parent->has($name)) {
                    continue;
                }
                $options = $parent->get($name)->getOptions();
                $options['buttons']['pixel_audit_history'] = $button;
                $parent->add($name, \PrestaShopBundle\Form\Admin\Type\ButtonCollectionType::class, $options);
            }
        } catch (Throwable $error) {
            Diagnostics::report($error);
        }
    }

    public function correlationSession(): string
    {
        $cookie = $this->context->cookie;
        return $cookie && (int) $cookie->session_id > 0 && (string) $cookie->session_token !== ''
            ? $cookie->session_id . ':' . $cookie->session_token : '';
    }

    /** Stable per-session proof, independent of Symfony's randomized/masked token representation. */
    public function adminCsrf(string $purpose): string
    {
        $employee = $this->context->employee;
        $session = $this->correlationSession();
        if (!$employee || !Validate::isLoadedObject($employee) || $session === '') {
            return '';
        }
        return hash_hmac('sha256', 'pixel_audit_csrf|' . $purpose . '|' . (int) $employee->id . '|' . $session, _COOKIE_KEY_);
    }

    private function correlationShops(): array
    {
        $employee = $this->context->employee;
        if (!$employee || !Validate::isLoadedObject($employee)) {
            return [];
        }
        return \Pixel\Module\Audit\Core\ShopScope::allowed(Shop::getContextListShopID(), $employee->getAssociatedShops(), $employee->isSuperAdmin());
    }

    /** Product-history consultation is independent of product update and module-view permissions. */
    public function canReadProductHistory(): bool
    {
        $employee = $this->context->employee;
        return $this->active && $employee && Validate::isLoadedObject($employee)
            && ($employee->isSuperAdmin() || (Access::isGranted('ROLE_MOD_TAB_ADMINPRODUCTS_READ', (int) $employee->id_profile)
                && Access::isGranted('ROLE_MOD_TAB_ADMINPIXELAUDIT_READ', (int) $employee->id_profile)));
    }

    public function canRestoreProducts(): bool
    {
        $employee = $this->context->employee;
        return $this->canReadProductHistory() && $this->hasEnabledProductRestorations() && $employee
            && ($employee->isSuperAdmin() || (Access::isGranted('ROLE_MOD_TAB_ADMINPRODUCTS_UPDATE', (int) $employee->id_profile)
                && Access::isGranted('ROLE_MOD_TAB_ADMINPIXELAUDITRESTORE_UPDATE', (int) $employee->id_profile)));
    }

    public function auditFieldLabel(string $provider, string $key, string $historical): string
    {
        if ($this->fieldLabels === null) {
            $languages = [];
            foreach (Language::getLanguages(false) as $language) {
                $languages[(int) $language['id_lang']] = $language['name'];
            }
            $this->fieldLabels = new \Pixel\Module\Audit\Presentation\FieldLabels($this->registry(), $languages);
        }
        return $this->fieldLabels->label($provider, $key, $historical);
    }

    public function productRestorationDefinition(string $provider): ?\Pixel\Module\Audit\Api\ProductRestorationDefinition
    {
        $definition = $this->registry()->productRestorations()[$provider] ?? null;
        return $definition !== null && (new Settings())->enabled($definition->family)
            && ($definition->owner === 'prestashop' || Module::isEnabled($definition->owner)) ? $definition : null;
    }

    private function hasEnabledProductRestorations(): bool
    {
        foreach ($this->registry()->productRestorations() as $provider => $definition) {
            if ($this->productRestorationDefinition($provider) !== null) {
                return true;
            }
        }
        return false;
    }

    /** The restoration writes its own mandatory journal in the same transaction; no duplicate shutdown collector. */
    public function runProductRestoration(callable $write): mixed
    {
        if ($this->restoringProduct) {
            throw new RuntimeException('Nested product restoration.');
        }
        $this->restoringProduct = true;
        try {
            return $write();
        } finally {
            $this->restoringProduct = false;
        }
    }

    public function productHistoryShops(int $productId): array
    {
        if ($productId < 1 || !$this->canReadProductHistory() || !($shops = $this->correlationShops())) {
            return [];
        }
        $rows = Db::getInstance()->executeS('SELECT ps.id_shop FROM `' . _DB_PREFIX_ . 'product_shop` ps INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_product = ps.id_product WHERE p.id_product = ' . $productId . ' AND ps.id_shop IN (' . implode(',', array_map('intval', $shops)) . ')', true, false);
        if ($rows === false) {
            throw new RuntimeException('Cannot resolve product history shops.');
        }
        return array_map('intval', array_column($rows, 'id_shop'));
    }

    public function presentationStylesheet(): string
    {
        return $this->_path . 'views/css/audit.css?v=' . $this->version . '.' . filemtime(__DIR__ . '/views/css/audit.css');
    }

    public function productHistoryAssets(?int $productId = null): string
    {
        if ($productId !== null) {
            $this->productPageId = $productId;
        }
        if (!$this->productHistoryShops($this->productPageId)) {
            return '';
        }
        $config = json_encode(['productId' => $this->productPageId,
            'panelMarkup' => $this->display(__FILE__, 'views/templates/admin/sidebar.tpl'),
            'endpoint' => $this->context->link->getAdminLink('AdminPixelAuditHistory') . '&ajax=1&product_id=' . $this->productPageId,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        return '<script id="pixel-audit-translations" type="application/json">' . \Pixel\Module\Audit\Presentation\I18n::javascript() . '</script><script id="pixel-audit-history-config" type="application/json">' . $config . '</script>'
            . '<link rel="stylesheet" href="' . htmlspecialchars($this->presentationStylesheet(), ENT_QUOTES, 'UTF-8') . '">'
            . '<link rel="stylesheet" href="' . htmlspecialchars($this->_path . 'views/css/product-history.css?v=' . $this->version . '.' . filemtime(__DIR__ . '/views/css/product-history.css'), ENT_QUOTES, 'UTF-8') . '">'
            . '<script defer src="' . htmlspecialchars($this->_path . 'views/js/product-history.js?v=' . $this->version . '.' . filemtime(__DIR__ . '/views/js/product-history.js'), ENT_QUOTES, 'UTF-8') . '"></script>';
    }

    public function canCorrelateProducts(): bool
    {
        $employee = $this->context->employee;
        return $employee && Validate::isLoadedObject($employee) && $this->correlationSession() !== ''
            && ($employee->isSuperAdmin() || Access::isGranted('ROLE_MOD_TAB_ADMINPRODUCTS_UPDATE', (int) $employee->id_profile));
    }

    public function productOperationTokens(int $productId): array
    {
        if (!$this->canCorrelateProducts() || $productId < 1) {
            return [];
        }
        $rows = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'product_shop` WHERE id_product = ' . $productId, true, false) ?: [];
        $shops = array_values(array_intersect($this->correlationShops(), array_map('intval', array_column($rows, 'id_shop'))));
        if (!$shops) {
            return [];
        }
        $signer = new \Pixel\Module\Audit\Correlation\SignedOperation(_COOKIE_KEY_);
        $tokens = [];
        for ($i = 0; $i < 4; ++$i) {
            $tokens[] = $signer->issue((int) $this->context->employee->id, $this->correlationSession(), $productId, $shops, time());
        }
        return $tokens;
    }

    public function verifyProductOperation(string $token): ?array
    {
        if (!$this->canCorrelateProducts()) {
            return null;
        }
        return (new \Pixel\Module\Audit\Correlation\SignedOperation(_COOKIE_KEY_))->verify(
            $token,
            (int) $this->context->employee->id,
            $this->correlationSession(),
            $this->correlationShops(),
            time()
        );
    }

    public function hookDisplayBackOfficeHeader(): string
    {
        try {
            $productId = $this->productPageId;
            if (!$productId && Tools::getValue('controller') === 'AdminProducts') {
                $productId = (int) Tools::getValue('id_product');
            }
            $tokens = $this->productOperationTokens($productId);
            if (!$tokens) {
                return '';
            }
            $config = json_encode([
                'productId' => $productId, 'tokens' => $tokens,
                'panelMarkup' => $this->display(__FILE__, 'views/templates/admin/sidebar.tpl'),
            'endpoint' => $this->context->link->getAdminLink('AdminPixelAuditOperation') . '&ajax=1',
                'csrf' => $this->adminCsrf('product_operations'),
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
            return '<script id="pixel-audit-product-config" type="application/json">' . $config . '</script>'
                . '<script defer src="' . htmlspecialchars($this->_path . 'views/js/product-operation.js?v=' . $this->version . '.' . filemtime(__DIR__ . '/views/js/product-operation.js'), ENT_QUOTES, 'UTF-8') . '"></script>';
        } catch (Throwable $error) {
            Diagnostics::report($error);
            return '';
        }
    }

    /** Explicit whole-scope API for integrations bypassing the native Configuration write methods. */
    public function watchConfiguration(): void
    {
        if ($this->observingConfiguration || $this->registering) {
            return;
        }
        $this->observingConfiguration = true;
        try {
            if (!(new Settings())->enabled('configuration') || ($audit = $this->audit()) === null) {
                return;
            }
            $employee = $this->context->employee;
            $shops = $employee && Validate::isLoadedObject($employee) && !$employee->isSuperAdmin()
                ? $employee->getAssociatedShops() : Shop::getShops(false, null, true);
            $this->configurationObserver ??= new \Pixel\Module\Audit\Configuration\ConfigurationObserver();
            $this->configurationObserver->capture($audit, $shops);
        } catch (Throwable $error) {
            Diagnostics::report($error);
        } finally {
            $this->observingConfiguration = false;
        }
    }

    /** Observe exactly the persisted key/scopes touched by the callback, without intercepting static core methods. */
    public function observeConfigurationChange(array $scope, callable $write): mixed
    {
        $state = [];
        $scope['state'] = &$state;
        $this->hookActionPixelAuditConfigurationBefore($scope);
        try {
            return $write();
        } finally {
            $this->hookActionPixelAuditConfigurationAfter(['state' => $state]);
        }
    }

    public function hookActionAdminUpdate_optionsBefore(array $params): void
    {
        if (($params['controller'] ?? null) instanceof AdminStoresController) {
            $this->nativeConfigurationActions ??= new \Pixel\Module\Audit\Configuration\NativeConfigurationActions();
            $this->nativeConfigurationActions->begin('store_contact', ['PS_SHOP_NAME', 'PS_SHOP_EMAIL'], $this);
        }
    }

    public function hookActionAdminUpdate_optionsAfter(array $params): void
    {
        if (($params['controller'] ?? null) instanceof AdminStoresController) {
            $this->nativeConfigurationActions?->finish('store_contact', $this);
        }
    }

    public function hookActionAdminFinish_stepBefore(array $params): void
    {
        if (($params['controller'] ?? null) instanceof AdminCarrierWizardController) {
            $this->nativeConfigurationActions ??= new \Pixel\Module\Audit\Configuration\NativeConfigurationActions();
            $this->nativeConfigurationActions->begin('carrier_wizard', ['PS_CARRIER_DEFAULT'], $this);
        }
    }

    public function hookActionAdminFinish_stepAfter(array $params): void
    {
        if (($params['controller'] ?? null) instanceof AdminCarrierWizardController) {
            $this->nativeConfigurationActions?->finish('carrier_wizard', $this);
        }
    }

    public function hookActionCarrierUpdate(array $params): void
    {
        // The wizard emits this after changing the default ID, before its AJAX exit.
        $this->nativeConfigurationActions?->finish('carrier_wizard', $this);
    }

    /** Before audit flush: legacy controllers may redirect or die before their After hook. */
    public function finishNativeConfigurationActions(): void
    {
        $this->nativeConfigurationActions?->finishAll($this);
    }

    public function hookActionPixelAuditConfigurationBefore(array $params): void
    {
        if (str_starts_with((string) ($params['key'] ?? ''), 'PIXEL_AUDIT_') || $this->registering || $this->observingConfiguration) {
            return;
        }
        $this->observingConfiguration = true;
        try {
            if (isset($params['owner'], $params['key']) && (new Settings())->enabled('configuration')) {
                $registry = $this->registry();
                if (!isset($registry->configurationParameters()[$params['key']])) {
                    foreach (\Pixel\Module\Audit\Configuration\TrackedParameters::definitions() as $parameter) {
                        if ($parameter->owner === $params['owner'] && $parameter->key === $params['key']) {
                            $registry->addConfigurationParameter($parameter);
                            break;
                        }
                    }
                }
            }
            $params['state'] = (new \Pixel\Module\Audit\Configuration\ConfigurationBridge())->before($params, $this);
        } catch (Throwable $error) {
            Diagnostics::report($error);
        } finally {
            $this->observingConfiguration = false;
        }
    }

    public function hookActionPixelAuditConfigurationAfter(array $params): void
    {
        if (empty($params['state'])) {
            return;
        }
        try {
            (new \Pixel\Module\Audit\Configuration\ConfigurationBridge())->after($params['state'], $this);
        } catch (Throwable $error) {
            Diagnostics::report($error);
        }
    }

    public function hookActionObjectAddBefore(array $params): void
    {
        $object = $params['object'] ?? null;
        if ($object instanceof Configuration) {
            try {
                $this->configurationObjectBefore($object);
            } catch (Throwable $error) {
                Diagnostics::report($error);
            }
        }
    }

    private function configurationObjectBefore(Configuration $object): void
    {
        $id = spl_object_id($object);
        $targets = [];
        if ((int) $object->id > 0) {
            // Metadata only; capture the old key/scope even when the object is being renamed or moved.
            $old = Db::getInstance()->getRow('SELECT name, id_shop, id_shop_group FROM `' . _DB_PREFIX_ . 'configuration` WHERE id_configuration = ' . (int) $object->id, false);
            if (!$old && (int) Db::getInstance()->getNumberError() !== 0) {
                throw new RuntimeException('Cannot read configuration identity.');
            }
            if ($old) {
                $targets[] = ['operation' => 'scope', 'key' => $old['name'], 'shop' => (int) $old['id_shop'], 'group' => (int) $old['id_shop_group']];
            }
        }
        $nextName = $object->name;
        if ($nextName !== '') {
            $targets[] = ['operation' => 'scope', 'key' => $nextName, 'shop' => (int) $object->id_shop, 'group' => (int) $object->id_shop_group];
        }
        // A stable key order also covers A→B and B→A concurrent renames without reversed advisory locks.
        usort($targets, static fn ($a, $b) => strcmp($a['key'], $b['key']));
        $states = [];
        $seen = [];
        foreach ($targets as $target) {
            $key = json_encode($target, JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $state = [];
            $target['reserve_initial'] = true;
            $target['state'] = &$state;
            $this->hookActionPixelAuditConfigurationBefore($target);
            $states[] = $state;
            unset($target, $state);
        }
        $this->configurationObjects[$id][] = $states;
    }

    public function finishConfigurationObjects(): void
    {
        $pending = $this->configurationObjects;
        $this->configurationObjects = [];
        foreach ($pending as $frames) {
            foreach (array_reverse($frames) as $states) {
                foreach (array_reverse($states) as $state) {
                    $this->hookActionPixelAuditConfigurationAfter(['state' => $state]);
                }
            }
        }
    }

    public function hookActionObjectUpdateAfter(array $params): void
    {
        $this->configurationObjectAfter($params);
    }
    public function hookActionObjectDeleteAfter(array $params): void
    {
        $this->configurationObjectAfter($params);
    }

    private function configurationObjectAfter(array $params): void
    {
        $object = $params['object'] ?? null;
        if (!$object instanceof Configuration) {
            return;
        }
        $id = spl_object_id($object);
        if (empty($this->configurationObjects[$id])) {
            return;
        }
        $states = array_pop($this->configurationObjects[$id]);
        if (!$this->configurationObjects[$id]) {
            unset($this->configurationObjects[$id]);
        }
        foreach (array_reverse($states) as $state) {
            $this->hookActionPixelAuditConfigurationAfter(['state' => $state]);
        }
    }

    public function hookActionPixelAuditRegisterProviders(array $params): void
    {
    }
    public function hookActionObjectUpdateBefore(array $params): void
    {
        $this->observe($params, false);
    }
    public function hookActionObjectDeleteBefore(array $params): void
    {
        $this->observe($params, false);
    }
    public function hookActionObjectAddAfter(array $params): void
    {
        $this->observe($params, true);
    }

    private function observe(array $params, bool $created): void
    {
        try {
            $object = $params['object'] ?? null;
            if ($object instanceof Configuration) {
                if ($created) {
                    $this->configurationObjectAfter($params);
                } else {
                    $this->configurationObjectBefore($object);
                }
                return;
            }
            if (!$object instanceof ObjectModel) {
                return;
            }
            $audit = $this->audit();
            if ($audit === null) {
                return;
            }
            foreach ((new SubjectResolver())->resolve($object) as $subject) {
                $audit->watch($subject, $created);
            }
        } catch (Throwable $error) {
            Diagnostics::report($error);
        }
    }
}
