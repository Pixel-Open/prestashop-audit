{if !$history_operation}
  <div class="pixel-audit-history-list">
    <div class="pixel-audit-history-tools"><a class="btn btn-outline-secondary" href="{$history_journal|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='Open full audit log' d='Modules.Pixelaudit.Admin'}<i class="material-icons" aria-hidden="true">open_in_new</i></a>
    <label class="pixel-audit-control">{l s='Family' d='Modules.Pixelaudit.Admin'}
      <select class="form-control" data-audit-family>
        <option value="">{l s='All families' d='Modules.Pixelaudit.Admin'}</option>
        {foreach $history_families as $key => $label}<option value="{$key|escape:'html':'UTF-8'}" {if $history_family == $key}selected{/if}>{l s=$label d='Modules.Pixelaudit.Admin'}</option>{/foreach}
      </select>
    </label></div>
    <p>{$history.count|intval} {l s='operation(s) · Dates in UTC' d='Modules.Pixelaudit.Admin'}</p>
    {foreach $history.rows as $row}
      <details class="pixel-audit-operation">
        <summary><div class="pixel-audit-operation-summary"><strong>{$row.date_add|escape:'html':'UTF-8'} UTC - {$row.actor_name|escape:'html':'UTF-8'} (#{$row.actor_id|intval})</strong>
          <small>{l s='Operation #' d='Modules.Pixelaudit.Admin'}{$row.id_operation|intval} · {$row.events|intval} {l s='event(s)' d='Modules.Pixelaudit.Admin'}</small>
          {if !empty($row.source_operation_id)}<small>{l s='Restoration of operation #' d='Modules.Pixelaudit.Admin'}{$row.source_operation_id|intval}</small>{/if}
          {if $row.observation == 'partial'}<small>{l s='Interrupted request' d='Modules.Pixelaudit.Admin'}</small>{/if}
        </div><i class="material-icons pixel-audit-chevron" aria-hidden="true">expand_more</i></summary>
        <div data-audit-details="{$history_url|escape:'html':'UTF-8'}&amp;operation_id={$row.id_operation|intval}" aria-live="polite"></div>
      </details>
    {foreachelse}<p>{l s='No changes recorded.' d='Modules.Pixelaudit.Admin'}</p>{/foreach}
  </div>
{else}
  {foreach $history.rows as $event}
    <section class="pixel-audit-event">
      <h3 class="pixel-audit-event-title">{$event.subject_display|escape:'html':'UTF-8'} - {$event.action_display|escape:'html':'UTF-8'}</h3>
      <p>{$history_families[$event.family]|default:$event.family|escape:'html':'UTF-8'} {l s='· shop' d='Modules.Pixelaudit.Admin'} {$event.id_shop|intval}</p>
      {if $event.observation == 'partial'}<p>{l s='Changes observed during an interrupted request.' d='Modules.Pixelaudit.Admin'}</p>{/if}
      {if !empty($event.source_operation_id)}<p>{l s='Restoration of operation #' d='Modules.Pixelaudit.Admin'}{$event.source_operation_id|intval} {l s='(event #' d='Modules.Pixelaudit.Admin'}{$event.source_event_id|intval}).</p>{/if}
      {include file='./event-changes.tpl' audit_allow_restore=$restore_allowed audit_product=$history_product}
    </section>
  {foreachelse}<p>{l s='Operation not found, expired or unavailable in this context.' d='Modules.Pixelaudit.Admin'}</p>{/foreach}
{/if}
{if $history.page > 1 || $history.more}
  <nav class="pixel-audit-pagination" aria-label="{l s='History pagination' d='Modules.Pixelaudit.Admin'}">
    {if $history.page > 1}<a class="btn btn-outline-secondary" data-audit-page href="{$history_previous|escape:'html':'UTF-8'}">{l s='Previous' d='Modules.Pixelaudit.Admin'}</a>{/if}
    <span>{l s='Page' d='Modules.Pixelaudit.Admin'} {$history.page|intval}</span>
    {if $history.more}<a class="btn btn-outline-secondary" data-audit-page href="{$history_next|escape:'html':'UTF-8'}">{l s='Next' d='Modules.Pixelaudit.Admin'}</a>{/if}
  </nav>
{/if}
