<div class="panel pixel-audit-ui">
  <header class="pixel-audit-heading"><div><h3 class="pixel-audit-title">{l s='About' d='Modules.Pixelaudit.Admin'}</h3></div></header>
  <nav class="pixel-audit-navigation" aria-label="{l s='Audit navigation' d='Modules.Pixelaudit.Admin'}"><a href="{$audit_journal_url|escape:'html':'UTF-8'}"><i class="material-icons" aria-hidden="true">history</i>{l s='Audit log' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_journal_url|escape:'html':'UTF-8'}&amp;audit_type=employee_login"><i class="material-icons" aria-hidden="true">login</i>{l s='Back-office logins' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_settings_url|escape:'html':'UTF-8'}"><i class="material-icons" aria-hidden="true">settings</i>{l s='Configuration' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_about_url|escape:'html':'UTF-8'}" aria-current="page"><i class="material-icons" aria-hidden="true">info</i>{l s='About' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_diagnostics_url|escape:'html':'UTF-8'}"><i class="material-icons" aria-hidden="true">monitor_heart</i>{l s='Diagnostics' d='Modules.Pixelaudit.Admin'}</a></nav>
  <section class="pixel-audit-section">
    <h4>{l s='Audit log' d='Modules.Pixelaudit.Admin'}</h4>
    <p>{l s='Audit records back-office changes with the author, date, shop and field values before and after each change.' d='Modules.Pixelaudit.Admin'}</p>
    <dl>
      <dt>{l s='Version' d='Modules.Pixelaudit.Admin'}</dt><dd>{$audit_version|escape:'html':'UTF-8'}</dd>
      <dt>{l s='Author' d='Modules.Pixelaudit.Admin'}</dt><dd>{$audit_author|escape:'html':'UTF-8'}</dd>
      <dt>{l s='License' d='Modules.Pixelaudit.Admin'}</dt><dd>MIT</dd>
    <dt>GitHub</dt><dd><a href="https://github.com/Pixel-Open/prestashop-audit" target="_blank" rel="noopener noreferrer">Pixel-Open/prestashop-audit</a></dd>
    </dl>
  </section>
  <section class="pixel-audit-section">
    <h4>{l s='How the audit log works' d='Modules.Pixelaudit.Admin'}</h4>
    <p>{l s='Filter the audit log to inspect operations, or open History from a product to review its changes.' d='Modules.Pixelaudit.Admin'}</p>
    <p>{l s='Retention is configurable globally and by family. Disabling collection does not delete existing history.' d='Modules.Pixelaudit.Admin'}</p>
  </section>
  <section class="pixel-audit-section">
    <h4>{l s='Product restoration' d='Modules.Pixelaudit.Admin'}</h4>
    <p>{l s='Restoring selected fields requires dedicated permissions, a preview and confirmation. Conflicts with current data prevent restoration.' d='Modules.Pixelaudit.Admin'}</p>
    <p>{l s='The audit log is not a complete backup and cannot recreate deleted objects.' d='Modules.Pixelaudit.Admin'}</p>
  </section>
  <section class="pixel-audit-section">
    <h4>{l s='Documentation' d='Modules.Pixelaudit.Admin'}</h4>
    <ul>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/README.md" target="_blank" rel="noopener noreferrer">{l s='Installation and overview' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/configuration.md" target="_blank" rel="noopener noreferrer">{l s='Configuration' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/retention.md" target="_blank" rel="noopener noreferrer">{l s='Retention' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/permissions-and-multistore.md" target="_blank" rel="noopener noreferrer">{l s='Permissions and multistore' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/visual-diff.md" target="_blank" rel="noopener noreferrer">{l s='Visual diff' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/translations.md" target="_blank" rel="noopener noreferrer">{l s='Translations' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/integrations.md" target="_blank" rel="noopener noreferrer">{l s='Integrations' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/api/providers.md" target="_blank" rel="noopener noreferrer">{l s='Provider API' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/api/configuration-tracking.md" target="_blank" rel="noopener noreferrer">{l s='Configuration tracking API' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/api/field-labels.md" target="_blank" rel="noopener noreferrer">{l s='Field labels API' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/api/restoration.md" target="_blank" rel="noopener noreferrer">{l s='Restoration API' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/examples/custom-entity.md" target="_blank" rel="noopener noreferrer">{l s='Custom entity example' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/examples/custom-product-fields.md" target="_blank" rel="noopener noreferrer">{l s='Custom product fields example' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/examples/custom-storage-restoration.md" target="_blank" rel="noopener noreferrer">{l s='Custom storage restoration example' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/docs/testing.md" target="_blank" rel="noopener noreferrer">{l s='Testing and compatibility' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/CONTRIBUTING.md" target="_blank" rel="noopener noreferrer">{l s='Contributing' d='Modules.Pixelaudit.Admin'}</a></li>
      <li><a href="https://github.com/Pixel-Open/prestashop-audit/blob/main/CHANGELOG.md" target="_blank" rel="noopener noreferrer">{l s='Changelog' d='Modules.Pixelaudit.Admin'}</a></li>
    </ul>
  </section>
</div>
