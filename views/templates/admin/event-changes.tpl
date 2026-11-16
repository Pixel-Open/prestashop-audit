{if $audit_allow_restore && $event.restorable_fields}
<form action="{$restore_url|escape:'html':'UTF-8'}" method="post" data-audit-restore>
  <input type="hidden" name="action" value="preview"><input type="hidden" name="product_id" value="{$audit_product|intval}">
  <input type="hidden" name="event_id" value="{$event.id_event|intval}"><input type="hidden" name="restore_csrf" value="{$restore_csrf|escape:'html':'UTF-8'}">
  <p class="pixel-audit-hint">{l s='Select the fields to undo, then preview their values before confirming.' d='Modules.Pixelaudit.Admin'}</p>
{/if}
{include file='./diff-legend.tpl'}
<table class="table pixel-audit-comparison"><thead><tr><th scope="col">{l s='Field' d='Modules.Pixelaudit.Admin'}</th><th scope="col">{l s='Before' d='Modules.Pixelaudit.Admin'}</th><th scope="col">{l s='After' d='Modules.Pixelaudit.Admin'}</th></tr></thead><tbody>
  {foreach $event.changes as $key => $change}
  <tr>
    <th scope="row">{if $audit_allow_restore && in_array($key, $event.restorable_fields)}<label class="pixel-audit-field"><input type="checkbox" name="fields[]" value="{$key|escape:'html':'UTF-8'}" aria-label="{l s='Restore' d='Modules.Pixelaudit.Admin'} {$change.label|escape:'html':'UTF-8'}" data-audit-restore-field><span>{$change.label|escape:'html':'UTF-8'}</span></label>{else}{$change.label|escape:'html':'UTF-8'}{/if}</th>
    <td class="pixel-audit-before" data-label="{l s='Before' d='Modules.Pixelaudit.Admin'}">{if isset($change.diff)}{include file='./diff-value.tpl' audit_fragments=$change.diff.before audit_diff_side='before'}{else}<pre>{$change.before_display|escape:'html':'UTF-8'}</pre>{/if}</td>
    <td class="pixel-audit-after" data-label="{l s='After' d='Modules.Pixelaudit.Admin'}">{if isset($change.diff)}{include file='./diff-value.tpl' audit_fragments=$change.diff.after audit_diff_side='after'}{else}<pre>{$change.after_display|escape:'html':'UTF-8'}</pre>{/if}{if !empty($change.diff.limited)}<small class="pixel-audit-diff-limited">{l s='Detailed highlighting is limited for this value. Full values are shown.' d='Modules.Pixelaudit.Admin'}</small>{/if}</td>
  </tr>
  {/foreach}
</tbody></table>
{if $audit_allow_restore && $event.restorable_fields}<button type="submit" class="btn btn-default"><i class="material-icons" aria-hidden="true">restore</i>{l s='Preview undo' d='Modules.Pixelaudit.Admin'}</button></form>{/if}
<div data-audit-restoration aria-live="polite"></div>
