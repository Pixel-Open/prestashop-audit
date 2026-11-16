(function (win) {
  'use strict';
  var translations = win.document.getElementById('pixel-audit-translations');
  var messages = translations ? JSON.parse(translations.textContent) : {};
  function t(message) { return messages[message] || message; }
  var root = win.document.querySelector('[data-audit-journal-restoration]');
  if (!root || root.dataset.initialized) return;
  root.dataset.initialized = '1';
  var busy = false;
  root.addEventListener('change', function (event) {
    if (!event.target.matches('[data-audit-restore-field]')) return;
    event.target.closest('[data-audit-journal-event]').querySelector('[data-audit-restoration]').textContent = '';
  });
  root.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.matches('[data-audit-restore]')) return;
    event.preventDefault();
    if (busy) return;
    var section = form.closest('[data-audit-journal-event]');
    var target = section.querySelector('[data-audit-restoration]');
    var body = new win.FormData(form);
    var confirming = body.get('action') === 'confirm';
    if (!confirming && !body.getAll('fields[]').length) { target.textContent = t("Select at least one field to preview."); return; }
    var address = form.getAttribute('action'); // input[name=action] shadows form.action.
    var submit = form.querySelector('button[type="submit"]');
    if (submit) submit.disabled = true;
    busy = true;
    target.setAttribute('aria-busy', 'true');
    target.textContent = confirming ? t("Restoring...") : t("Preparing preview...");
    var restored = false;
    var uncertain = false;
    // A confirmation is never automatically retried or aborted: it may already be committed.
    win.fetch(address, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body })
      .then(function (response) {
        if (response.headers.get('X-Pixel-Audit-History') !== '1') throw new Error('Restoration unavailable');
        restored = response.headers.get('X-Pixel-Audit-Restored') === '1';
        return response.text();
      }).then(function (html) { target.innerHTML = html; })
      .catch(function () {
        uncertain = confirming;
        target.textContent = confirming ? t("Response unavailable. Reload the history to check whether the restoration completed before trying again.") : t("Preview unavailable. Please try again.");
      }).finally(function () {
        busy = false; target.removeAttribute('aria-busy');
        if (restored || uncertain) {
          section.querySelectorAll('form[data-audit-restore] button, [data-audit-restore-field]').forEach(function (element) { element.disabled = true; });
          var reload = win.document.createElement('button'); reload.type = 'button'; reload.className = 'btn btn-default'; reload.textContent = t("Reload history");
          reload.addEventListener('click', function () { win.location.reload(); }); target.appendChild(reload);
        } else if (submit && submit.isConnected) submit.disabled = false;
      });
  });
}(window));
