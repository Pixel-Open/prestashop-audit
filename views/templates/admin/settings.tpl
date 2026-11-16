<div class="panel pixel-audit-ui">
  <header class="pixel-audit-heading"><div><h3 class="pixel-audit-title">{l s='Audit log settings' d='Modules.Pixelaudit.Admin'}</h3><p class="pixel-audit-muted">{l s='Choose what to collect and how long to keep it.' d='Modules.Pixelaudit.Admin'}</p></div><span class="pixel-audit-badge"><i class="material-icons" aria-hidden="true">store</i>{l s='All shops' d='Modules.Pixelaudit.Admin'}</span></header>
  <nav class="pixel-audit-navigation" aria-label="{l s='Audit navigation' d='Modules.Pixelaudit.Admin'}"><a href="{$audit_journal_url|escape:'html':'UTF-8'}"><i class="material-icons" aria-hidden="true">history</i>{l s='View audit log' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_journal_url|escape:'html':'UTF-8'}&amp;audit_type=employee_login"><i class="material-icons" aria-hidden="true">login</i>{l s='Back-office logins' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_url|escape:'html':'UTF-8'}" aria-current="page"><i class="material-icons" aria-hidden="true">settings</i>{l s='Configuration' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_about_url|escape:'html':'UTF-8'}"><i class="material-icons" aria-hidden="true">info</i>{l s='About' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_diagnostics_url|escape:'html':'UTF-8'}"><i class="material-icons" aria-hidden="true">monitor_heart</i>{l s='Diagnostics' d='Modules.Pixelaudit.Admin'}</a></nav>
  <p>{l s='These settings apply to every shop. Disabling a family stops collection and retains its history until expiration.' d='Modules.Pixelaudit.Admin'}</p>
  <form class="pixel-audit-settings-form" action="{$audit_url|escape:'html':'UTF-8'}" method="post">
    <input type="hidden" name="audit_token" value="{$audit_token|escape:'html':'UTF-8'}">
    <fieldset class="pixel-audit-form-body" {if !$audit_can_edit}disabled{/if}>
      <section class="pixel-audit-section"><h4>{l s='Collection and retention' d='Modules.Pixelaudit.Admin'}</h4>
      <div class="pixel-audit-control pixel-audit-retention"><label for="audit-days">{l s='Global retention (days)' d='Modules.Pixelaudit.Admin'}</label>
        <input id="audit-days" class="form-control" type="number" min="1" max="3650" name="days" value="{$audit_settings.days|intval}" required>
      </div>
      <div class="pixel-audit-table-wrap"><table class="table pixel-audit-settings-table pixel-audit-families">
        <thead><tr><th>{l s='Collect' d='Modules.Pixelaudit.Admin'}</th><th>{l s='Family' d='Modules.Pixelaudit.Admin'}</th><th>{l s='Family retention in days (0 = global)' d='Modules.Pixelaudit.Admin'}</th></tr></thead>
        <tbody>{foreach $audit_families as $family => $label}
          <tr><td><input aria-label="{l s='Enable' d='Modules.Pixelaudit.Admin'} {l s=$label d='Modules.Pixelaudit.Admin'}" type="checkbox" name="families[{$family|escape:'html':'UTF-8'}]" value="1" {if !empty($audit_settings.families[$family])}checked{/if}></td>
          <td>{l s=$label d='Modules.Pixelaudit.Admin'}</td>
          <td data-label="{l s='Retention (days, 0 = global)' d='Modules.Pixelaudit.Admin'}"><input class="form-control" aria-label="{l s='Retention' d='Modules.Pixelaudit.Admin'} {l s=$label d='Modules.Pixelaudit.Admin'}" type="number" min="0" max="3650" name="retention[{$family|escape:'html':'UTF-8'}]" value="{$audit_settings.retention[$family]|default:0|intval}"></td></tr>
        {/foreach}</tbody>
      </table></div>
      <p>{l s='Changes to these settings are always audited. The current retention policy applies to existing events during the next purge.' d='Modules.Pixelaudit.Admin'}</p>
      </section>
      <section class="pixel-audit-section">
        <h4>{l s='Back-office logins' d='Modules.Pixelaudit.Admin'}</h4>
        <label class="pixel-audit-field"><input type="checkbox" name="login_history" value="1" {if !empty($audit_settings.login_history)}checked{/if}> {l s='Enable back-office login history' d='Modules.Pixelaudit.Admin'}</label>
        <p class="help-block">{l s='Records successful logins with the employee, UTC date and IP address, independently of employee change tracking. Disabled by default. Disabling collection keeps existing history until expiration. Employee family retention applies.' d='Modules.Pixelaudit.Admin'}</p>
      </section>
      <section class="pixel-audit-section"><h4>{l s='Tracked configuration parameters' d='Modules.Pixelaudit.Admin'}</h4>
      <details class="pixel-audit-help"><summary>{l s='About configuration tracking' d='Modules.Pixelaudit.Admin'}</summary>
      <p>{l s='This is the complete list of parameters declared by enabled modules. Collection uses the Symfony service, ObjectModel hooks or an explicit integration. Direct static calls and third-party SQL require that integration even when the key is selected. Only checked keys are collected when the Selected configuration family is enabled. Wildcards and arbitrary parameters are not accepted.' d='Modules.Pixelaudit.Admin'}</p>
      <p>{l s='Global, group and shop values and their translations are tracked separately. Removing an explicit value restores inheritance; the log does not substitute the inherited value.' d='Modules.Pixelaudit.Admin'}</p>
      </details>
      <input type="hidden" name="configuration_keys_present" value="1">
      <div class="pixel-audit-table-wrap"><table class="table pixel-audit-settings-table pixel-audit-parameters">
        <thead><tr><th>{l s='Track' d='Modules.Pixelaudit.Admin'}</th><th>{l s='Origin' d='Modules.Pixelaudit.Admin'}</th><th>{l s='Parameter' d='Modules.Pixelaudit.Admin'}</th><th>{l s='Exact key' d='Modules.Pixelaudit.Admin'}</th><th>{l s='Type' d='Modules.Pixelaudit.Admin'}</th><th>{l s='Translations' d='Modules.Pixelaudit.Admin'}</th></tr></thead>
        <tbody>{foreach $audit_configuration_parameters as $key => $parameter}
          <tr><td><input aria-label="{l s='Track' d='Modules.Pixelaudit.Admin'} {$key|escape:'html':'UTF-8'}" type="checkbox" name="configuration_keys[{$key|escape:'html':'UTF-8'}]" value="1" {if !empty($audit_configuration_selection[$key])}checked{/if}></td>
            <td data-label="{l s='Origin' d='Modules.Pixelaudit.Admin'}">{$parameter->owner|escape:'html':'UTF-8'}</td><td>{l s=$parameter->label d='Modules.Pixelaudit.Admin'}</td>
            <td data-label="{l s='Exact key' d='Modules.Pixelaudit.Admin'}"><code>{$key|escape:'html':'UTF-8'}</code></td><td data-label="{l s='Type' d='Modules.Pixelaudit.Admin'}">{$parameter->type|escape:'html':'UTF-8'}</td>
            <td data-label="{l s='Translations' d='Modules.Pixelaudit.Admin'}">{if $parameter->translated}{l s='Yes' d='Modules.Pixelaudit.Admin'}{else}{l s='No' d='Modules.Pixelaudit.Admin'}{/if}</td>
          </tr>
        {/foreach}</tbody>
      </table></div>
      <p class="pixel-audit-hint">{l s='Passwords, tokens, private keys and internal Audit parameters are excluded. Shop contact details and default carrier changes in the native wizard are also supported. This list never reveals current values. Audit settings history is always enabled.' d='Modules.Pixelaudit.Admin'}</p>
      </section>
      <section class="pixel-audit-section pixel-audit-removal"><h4>{l s='Data removal' d='Modules.Pixelaudit.Admin'}</h4>
      <div><label class="pixel-audit-field"><input type="checkbox" name="delete_on_uninstall" value="1" {if !empty($audit_settings.delete_on_uninstall)}checked{/if}> {l s='Delete audit data on uninstall' d='Modules.Pixelaudit.Admin'}</label></div>
      <p class="help-block">{l s='Unchecked, uninstalling keeps the audit log and these settings. Checked, uninstalling or resetting the module permanently deletes them.' d='Modules.Pixelaudit.Admin'}</p>
      </section>
      <footer class="pixel-audit-settings-footer"><button type="submit" class="btn btn-primary"><i class="material-icons" aria-hidden="true">save</i>{l s='Save' d='Modules.Pixelaudit.Admin'}</button></footer>
    </fieldset>
  </form>
<section class="pixel-audit-section pixel-audit-coverage">
  <h4>{l s='Scheduled purge and coverage' d='Modules.Pixelaudit.Admin'}</h4>
  <p>{l s='Expired events are purged automatically in small batches, at most once per hour, after an audited change. For large shops, also schedule the full purge.' d='Modules.Pixelaudit.Admin'}</p>
  <p>{l s='Schedule daily from the PrestaShop root:' d='Modules.Pixelaudit.Admin'} <code>php bin/console pixel:audit:purge --env=prod --no-debug</code>.</p>
  <p>{l s='Audit dates use UTC. Generic collection follows ObjectModel writes. Direct SQL writes require a provider and an API call before the change.' d='Modules.Pixelaudit.Admin'}</p>
  <p>{l s='Orders include lines, payments, shipments and credit slips. Card data and download identifiers are excluded. Profile permissions are tracked from the native back-office permissions page. Shop and module settings use the explicit list above; other parameters are not collected.' d='Modules.Pixelaudit.Admin'}</p>
</section>
</div>
