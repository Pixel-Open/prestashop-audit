<?php

declare(strict_types=1);

use Pixel\Module\Audit\Api\Field;
use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Api\Subject;
use Pixel\Module\Audit\Configuration\Settings;
use Pixel\Module\Audit\Configuration\SettingsValidator;
use Pixel\Module\Audit\Core\Buffer;
use Pixel\Module\Audit\Core\Comparator;
use Pixel\Module\Audit\Core\Diagnostics;
use Pixel\Module\Audit\Storage\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'pixel_audit/vendor/autoload.php';

class AdminPixelAuditSettingsController extends ModuleAdminController
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

    /** No inherited write actions are dispatched on audit controllers. */
    public function postProcess()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        $token = Tools::getValue('audit_token');
        if (!$this->viewAccess() || !$this->access('edit') || !is_string($token) || $this->auditModule()->adminCsrf('settings') === '' || !hash_equals($this->auditModule()->adminCsrf('settings'), $token)) {
            $this->errors[] = \Pixel\Module\Audit\Presentation\I18n::trans('Invalid authorization or security token.');
            return;
        }
        try {
            $settings = new Settings();
            $before = $settings->get();
            $data = (new SettingsValidator())->validate($_POST, $this->auditModule()->registry()->families(), $before, $this->auditModule()->registry()->configurationParameters());
            if ($settings->save($data)) {
                // Mandatory, even when configuration history or every other family is disabled.
                $oldFields = $newFields = [];
                $labels = ['login_history' => 'Enable back-office login history', 'days' => 'Global retention (days)', 'families' => 'Tracked families', 'retention' => 'Retention by family', 'configuration_keys' => 'Tracked configuration parameters', 'delete_on_uninstall' => 'Delete audit data on uninstall'];
                foreach (array_keys($labels) as $field) {
                    $oldFields[$field] = new Field($before[$field] ?? null, $labels[$field], 'json');
                    $newFields[$field] = new Field($data[$field], $labels[$field], 'json');
                }
                $buffer = new Buffer(new Comparator());
                $buffer->observe(new Subject('audit_settings', 1), 'pixel_audit.settings', 'configuration', new Snapshot(true, $oldFields), new Snapshot(true, $newFields));
                (new Repository())->append($buffer->events(), [
                    'id' => (int) $this->context->employee->id,
                    'name' => trim($this->context->employee->firstname . ' ' . $this->context->employee->lastname),
                    'origin' => 'backoffice', 'route' => 'AdminPixelAuditSettings',
                ], 'observed', $settings);
                $this->confirmations[] = \Pixel\Module\Audit\Presentation\I18n::trans('Settings saved.');
            } else {
                $this->errors[] = \Pixel\Module\Audit\Presentation\I18n::trans('The settings could not be saved.');
            }
        } catch (InvalidArgumentException $error) {
            $this->errors[] = \Pixel\Module\Audit\Presentation\I18n::trans($error->getMessage());
        } catch (Throwable $error) {
            Diagnostics::report($error);
            $this->errors[] = \Pixel\Module\Audit\Presentation\I18n::trans('An error occurred. Check the audit diagnostics.');
        }
    }

    public function initContent()
    {
        if (!$this->viewAccess()) {
            parent::initContent();
            return;
        }
        $this->display = '';
        parent::initContent();
        $parameters = $this->auditModule()->registry()->configurationParameters();
        $selection = [];
        foreach ($parameters as $key => $parameter) {
            $selection[$key] = (new Settings())->tracks($parameter);
        }
        $this->context->smarty->assign([
            'audit_settings' => (new Settings())->get(), 'audit_families' => \Pixel\Module\Audit\Presentation\I18n::labels($this->auditModule()->registry()->families()),
            'audit_token' => $this->auditModule()->adminCsrf('settings'), 'audit_can_edit' => $this->access('edit'),
            'audit_url' => $this->context->link->getAdminLink('AdminPixelAuditSettings'),
            'audit_diagnostics_url' => $this->context->link->getAdminLink('AdminPixelAudit') . '&audit_diagnostics=1',
            'audit_about_url' => $this->context->link->getAdminLink('AdminPixelAudit') . '&audit_about=1',
            'audit_journal_url' => $this->context->link->getAdminLink('AdminPixelAudit'),
            'audit_configuration_parameters' => $parameters, 'audit_configuration_selection' => $selection,
        ]);
        $this->context->smarty->assign('content', $this->module->display(_PS_MODULE_DIR_ . 'pixel_audit/pixel_audit.php', 'views/templates/admin/settings.tpl'));
    }

    private function auditModule(): Pixel_audit
    {
        $module = Module::getInstanceByName('pixel_audit');
        if (!$module instanceof Pixel_audit) {
            throw new RuntimeException('Audit module unavailable.');
        }
        return $module;
    }
}
