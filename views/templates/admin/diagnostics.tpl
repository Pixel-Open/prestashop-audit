<div class="panel pixel-audit-ui">
  <header class="pixel-audit-heading"><div><h3 class="pixel-audit-title">{l s='Diagnostics' d='Modules.Pixelaudit.Admin'}</h3></div></header>
  <nav class="pixel-audit-navigation" aria-label="{l s='Audit navigation' d='Modules.Pixelaudit.Admin'}"><a href="{$audit_journal_url|escape:'html':'UTF-8'}"><i class="material-icons" aria-hidden="true">history</i>{l s='Audit log' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_journal_url|escape:'html':'UTF-8'}&amp;audit_type=employee_login"><i class="material-icons" aria-hidden="true">login</i>{l s='Back-office logins' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_settings_url|escape:'html':'UTF-8'}"><i class="material-icons" aria-hidden="true">settings</i>{l s='Configuration' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_about_url|escape:'html':'UTF-8'}"><i class="material-icons" aria-hidden="true">info</i>{l s='About' d='Modules.Pixelaudit.Admin'}</a><a href="{$audit_diagnostics_url|escape:'html':'UTF-8'}" aria-current="page"><i class="material-icons" aria-hidden="true">monitor_heart</i>{l s='Diagnostics' d='Modules.Pixelaudit.Admin'}</a></nav>
  <section class="pixel-audit-section"><h4>{l s='Audit log' d='Modules.Pixelaudit.Admin'}</h4><p>{l s='Version' d='Modules.Pixelaudit.Admin'} : {$audit_version|escape:'html':'UTF-8'}</p></section>
  <section class="pixel-audit-section" id="audit-diagnostics">
    <h4>{l s='Collection log' d='Modules.Pixelaudit.Admin'}</h4>
    {if $audit_error}
      <p><strong>{l s='Last collection error:' d='Modules.Pixelaudit.Admin'}</strong> {$audit_error|escape:'html':'UTF-8'}</p>
      <p>{l s='This is the last recorded error and does not indicate whether a problem is still ongoing. Some changes at that time may not have been recorded. Consult the PHP logs for details.' d='Modules.Pixelaudit.Admin'}</p>
    {else}
      <p>{l s='No collection error has been recorded.' d='Modules.Pixelaudit.Admin'}</p>
    {/if}
  </section>
  <section class="pixel-audit-section">
    <h4>{l s='Database tables' d='Modules.Pixelaudit.Admin'}</h4>
    <p>{l s='Storage statistics cover all shops. Row counts are exact at query time; allocated sizes are database estimates and include indexes.' d='Modules.Pixelaudit.Admin'}</p>
    <div class="pixel-audit-table-wrap"><table class="table pixel-audit-storage">
      <thead><tr><th scope="col">{l s='Table' d='Modules.Pixelaudit.Admin'}</th><th scope="col">{l s='Rows' d='Modules.Pixelaudit.Admin'}</th><th scope="col">{l s='Data (MiB)' d='Modules.Pixelaudit.Admin'}</th><th scope="col">{l s='Indexes (MiB)' d='Modules.Pixelaudit.Admin'}</th><th scope="col">{l s='Total (MiB)' d='Modules.Pixelaudit.Admin'}</th></tr></thead>
      <tbody>{foreach $audit_tables as $table}<tr><th scope="row">{$table.name|escape:'html':'UTF-8'}</th>
        <td>{if $table.rows !== null}{$table.rows|intval}{else}{l s='Unavailable' d='Modules.Pixelaudit.Admin'}{/if}</td>
        <td>{if $table.data_bytes !== null}{($table.data_bytes/1048576)|string_format:"%.2f"}{else}{l s='Unavailable' d='Modules.Pixelaudit.Admin'}{/if}</td>
        <td>{if $table.index_bytes !== null}{($table.index_bytes/1048576)|string_format:"%.2f"}{else}{l s='Unavailable' d='Modules.Pixelaudit.Admin'}{/if}</td>
        <td>{if $table.total_bytes !== null}{($table.total_bytes/1048576)|string_format:"%.2f"}{else}{l s='Unavailable' d='Modules.Pixelaudit.Admin'}{/if}</td>
      </tr>{/foreach}</tbody>
    </table></div>
  </section>
</div>
