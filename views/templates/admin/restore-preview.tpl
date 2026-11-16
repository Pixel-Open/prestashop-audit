<div class="pixel-audit-restore-preview">
  {if $restore_plan.external}<p>{l s='Combination data #' d='Modules.Pixelaudit.Admin'}{$restore_plan.subject|intval} {l s=': global values shared across shops.' d='Modules.Pixelaudit.Admin'}</p>{/if}
  <h4 class="pixel-audit-preview-title">{l s='Undo selected fields' d='Modules.Pixelaudit.Admin'}</h4>
  <p>{l s='Source: operation #' d='Modules.Pixelaudit.Admin'}{$restore_plan.operation|intval}{l s=', event #' d='Modules.Pixelaudit.Admin'}{$restore_plan.event|intval}{l s='. Source event shop:' d='Modules.Pixelaudit.Admin'} {$restore_plan.shop|intval}.</p>
  <p>{l s='Affected shops:' d='Modules.Pixelaudit.Admin'} {foreach $restore_plan.affected_shops as $shopId}#{$shopId|intval} {/foreach}{l s='. Global fields affect all listed shops; shop fields and translations only affect the source event shop.' d='Modules.Pixelaudit.Admin'}</p>
  {include file='./diff-legend.tpl'}
  <table class="table pixel-audit-comparison pixel-audit-preview-table"><thead><tr><th>{l s='Field' d='Modules.Pixelaudit.Admin'}</th><th>{l s='Current value' d='Modules.Pixelaudit.Admin'}</th><th>{l s='Value to restore' d='Modules.Pixelaudit.Admin'}</th></tr></thead><tbody>
    {foreach $restore_plan.rows as $row}<tr><th scope="row">{$row.label|escape:'html':'UTF-8'}</th>
      <td class="pixel-audit-before" data-label="{l s='Current value' d='Modules.Pixelaudit.Admin'}">{if isset($row.diff)}{include file='./diff-value.tpl' audit_fragments=$row.diff.before audit_diff_side='before'}{else}<pre>{$row.current_display|escape:'html':'UTF-8'}</pre>{/if}{if $row.conflict}<strong class="pixel-audit-conflict">{l s='Conflict: value changed since the source event.' d='Modules.Pixelaudit.Admin'}</strong>{/if}</td>
      <td class="pixel-audit-after" data-label="{l s='Value to restore' d='Modules.Pixelaudit.Admin'}">{if isset($row.diff)}{include file='./diff-value.tpl' audit_fragments=$row.diff.after audit_diff_side='after'}{else}<pre>{$row.target_display|escape:'html':'UTF-8'}</pre>{/if}{if !empty($row.diff.limited)}<small class="pixel-audit-diff-limited">{l s='Detailed highlighting is limited for this value. Full values are shown.' d='Modules.Pixelaudit.Admin'}</small>{/if}</td></tr>{/foreach}
  </tbody></table>
  {if $restore_plan.conflict}
    <p class="alert alert-warning">{l s='Undo refused: at least one field has changed since this operation. No data was changed.' d='Modules.Pixelaudit.Admin'}</p>
  {else}
    <p>{l s='Confirmation restores these fields to their previous values and creates a new operation. This preview expires in five minutes.' d='Modules.Pixelaudit.Admin'}</p>
    <form action="{$restore_url|escape:'html':'UTF-8'}" method="post" data-audit-restore>
      <input type="hidden" name="action" value="confirm"><input type="hidden" name="product_id" value="{$restore_product|intval}">
      <input type="hidden" name="restore_csrf" value="{$restore_csrf|escape:'html':'UTF-8'}">
      <input type="hidden" name="preview_token" value="{$restore_plan.token|escape:'html':'UTF-8'}">
      <button type="submit" class="btn btn-warning">{l s='Confirm restoration' d='Modules.Pixelaudit.Admin'}</button>
    </form>
  {/if}
</div>
