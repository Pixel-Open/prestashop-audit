(function (win) {
  'use strict';
  var translations = win.document.getElementById('pixel-audit-translations');
  var messages = translations ? JSON.parse(translations.textContent) : {};
  function t(message) { return messages[message] || message; }
  var configNode = win.document.getElementById('pixel-audit-history-config');
  if (!configNode || win.document.getElementById('pixel-audit-history-sidebar')) return;
  var config;
  try { config = JSON.parse(configNode.textContent); } catch (error) { return; }
  // PrestaShop 8's legacy editor has a static toolbar instead of form button collections.
  var legacyToolbar = win.document.querySelector('form#form.product-page .product-header .toolbar');
  if (legacyToolbar && !win.document.querySelector('.pixel-audit-history-button')) {
    var historyButton = win.document.createElement('button');
    historyButton.type = 'button';
    historyButton.className = 'toolbar-button pixel-audit-history-button';
    historyButton.title = t('Product history');
    historyButton.setAttribute('aria-label', t('Product history'));
    historyButton.setAttribute('aria-controls', 'pixel-audit-history-sidebar');
    historyButton.setAttribute('aria-expanded', 'false');
    var historyIcon = win.document.createElement('i');
    historyIcon.className = 'material-icons';
    historyIcon.setAttribute('aria-hidden', 'true');
    historyIcon.textContent = 'history';
    var historyLabel = win.document.createElement('span');
    historyLabel.className = 'title';
    historyLabel.textContent = t('History');
    historyButton.appendChild(historyIcon);
    historyButton.appendChild(historyLabel);
    legacyToolbar.appendChild(historyButton);
  }
  var panel = win.document.createElement('aside');
  panel.id = 'pixel-audit-history-sidebar';
  panel.className = 'sidebar sidebar-right sidebar-animate pixel-audit-history-sidebar pixel-audit-ui';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-labelledby', 'pixel-audit-history-title');
  panel.setAttribute('aria-hidden', 'true');
  // Static markup only. All historical data is escaped by the server's fragment template.
  panel.innerHTML = config.panelMarkup;
  panel.querySelector('h2').textContent = t("Product history #") + config.productId;
  win.document.body.appendChild(panel);
  var content = panel.querySelector('[data-audit-content]');
  var trigger = null;
  var productDirty = false;
  var confirmationPending = false;
  var restorationApplied = false;
  var restorationBusy = false;
  var restoreStatus = panel.querySelector('[data-audit-restore-status]');
  var generation = 0;
  var desiredOpen = false;
  var phase = 'closed';
  var requests = new Set();
  function dirtyProduct() {
    return productDirty || !!(win.tinyMCE && win.tinyMCE.editors && Array.prototype.some.call(win.tinyMCE.editors, function (editor) { return editor.isDirty(); }));
  }
  function markDirty(event) {
    if (event.isTrusted && event.target.closest && event.target.closest('form[name="product"], form#product_form, form#form.product-page')) productDirty = true;
  }
  win.document.addEventListener('input', markDirty, true);
  win.document.addEventListener('change', markDirty, true);
  function staleFormMessage() {
    restoreStatus.textContent = confirmationPending ? t("Restoration in progress. Please wait before continuing.") : t("Reload the product before making further changes.");
    if (!confirmationPending) {
      var reload = win.document.createElement('button'); reload.type = 'button'; reload.className = 'btn btn-outline-secondary'; reload.textContent = t("Reload product");
      reload.addEventListener('click', function () {
        if (!dirtyProduct() || win.confirm(t("The product will reload. Unsaved changes will be lost. Continue?"))) win.location.reload();
      });
      restoreStatus.appendChild(reload);
    }
  }
  function blockStaleSave(event) {
    if (!restorationApplied && !confirmationPending) return;
    event.preventDefault(); event.stopImmediatePropagation(); staleFormMessage();
    var button = win.document.querySelector('.pixel-audit-history-button');
    if (!desiredOpen && button) open(button);
  }
  win.document.addEventListener('submit', function (event) {
    if (event.target.matches('form[name="product"], form#product_form, form#form.product-page')) blockStaleSave(event);
  }, true);
  win.document.addEventListener('click', function (event) {
    if (event.target.closest && event.target.closest('.product-form-save-button, #submit, #submit_and_stay, #product_form_save, .product-page .js-btn-save, #product_form_save_duplicate_btn, #product_form_save_go_to_catalog_btn, #product_form_save_new_btn')) blockStaleSave(event);
  }, true);
  panel.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.matches('[data-audit-restore]')) return;
    event.preventDefault(); event.stopPropagation();
    var target = form.closest('.pixel-audit-event').querySelector('[data-audit-restoration]');
    if (restorationApplied || confirmationPending) { staleFormMessage(); return; }
    if (dirtyProduct()) { target.textContent = t("Save your changes or reload the product before restoring data."); return; }
    if (restorationBusy) return;
    var body = new win.FormData(form);
    var confirming = body.get('action') === 'confirm';
    if (!confirming && !body.getAll('fields[]').length) { target.textContent = t("Select at least one field to preview."); return; }
    restorationBusy = true; confirmationPending = confirming;
    target.setAttribute('aria-busy', 'true'); target.textContent = confirming ? t("Restoring...") : t("Preparing preview...");
    if (confirming) staleFormMessage();
    // Do not abort a confirmation when the panel closes: its write may already be committed.
    win.fetch(form.getAttribute('action'), { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body })
      .then(function (response) {
        if (response.headers.get('X-Pixel-Audit-History') !== '1') throw new Error('Restore unavailable');
        if (response.headers.get('X-Pixel-Audit-Restored') === '1') restorationApplied = true;
        return response.text();
      }).then(function (html) {
        if (panel.contains(target)) target.innerHTML = html;
        if (restorationApplied) { staleFormMessage(); restoreStatus.insertAdjacentHTML('afterbegin', html); }
      }).catch(function () {
        target.textContent = confirming ? t("Restoration response unavailable. Reload the product to check its state before any further action.") : t("Preview unavailable. Please try again.");
        if (confirming) { restorationApplied = true; staleFormMessage(); }
      }).finally(function () {
        target.removeAttribute('aria-busy'); restorationBusy = false; confirmationPending = false;
        if (restorationApplied) staleFormMessage(); else restoreStatus.textContent = '';
      });
  });
  function buttons(expanded) {
    win.document.querySelectorAll('.pixel-audit-history-button').forEach(function (button) {
      button.setAttribute('aria-expanded', String(expanded));
    });
  }
  function cancel() {
    generation++;
    requests.forEach(function (controller) { controller.abort(); });
    requests.clear();
  }
  function isOpen() { return panel.classList.contains('sidebar-open'); }
  function focusTrigger() {
    if (!trigger) return;
    var target = trigger.getClientRects().length ? trigger : trigger.closest('.btn-collection') && trigger.closest('.btn-collection').querySelector('.dropdown-toggle');
    if (!target) return;
    if (target.tagName === 'A' && !target.hasAttribute('href') && !target.hasAttribute('tabindex')) target.setAttribute('tabindex', '0');
    target.focus();
  }
  function sidebar(action) {
    desiredOpen = action === 'show';
    if (!desiredOpen) {
      focusTrigger();
      hidden();
    }
    reconcile();
  }
  function reconcile() {
    if (phase === 'opening' || phase === 'closing') return;
    if (desiredOpen && phase === 'closed') {
      phase = 'opening'; cancel();
      panel.setAttribute('aria-hidden', 'false'); buttons(true);
      content.textContent = t("Loading history...");
      if (win.jQuery && win.jQuery.fn.sidebar) win.jQuery(panel).sidebar('show');
      else { panel.classList.add('sidebar-open'); shown(); }
    } else if (!desiredOpen && phase === 'open') {
      phase = 'closing';
      if (win.jQuery && win.jQuery.fn.sidebar) win.jQuery(panel).sidebar('hide');
      else { panel.classList.remove('sidebar-open'); closed(); }
    }
  }
  function shown() {
    phase = 'open';
    if (desiredOpen) { panel.querySelector('h2').focus(); load(content, config.endpoint); }
    reconcile();
  }
  function closed() {
    phase = 'closed'; hidden(); reconcile();
  }
  function hidden() {
    cancel(); buttons(false);
    if (trigger && panel.contains(win.document.activeElement)) focusTrigger();
    panel.setAttribute('aria-hidden', 'true');
  }
  function load(target, address) {
    var version = generation;
    var controller = new win.AbortController();
    requests.add(controller);
    target.classList.remove('pixel-audit-load-error'); target.classList.add('pixel-audit-loading');
    target.setAttribute('aria-busy', 'true');
    target.textContent = t("Loading history...");
    return win.fetch(address, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal })
      .then(function (response) {
        if (!response.ok || response.headers.get('X-Pixel-Audit-History') !== '1') throw new Error('History unavailable');
        return response.text();
      }).then(function (html) {
        if (version !== generation || !isOpen() || !panel.contains(target)) return;
        target.classList.remove('pixel-audit-loading'); target.innerHTML = html;
      }).catch(function (error) {
        if (error.name === 'AbortError' || version !== generation || !panel.contains(target)) return;
        target.classList.remove('pixel-audit-loading'); target.classList.add('pixel-audit-load-error');
        target.textContent = t("Unable to load history. ");
        var retry = win.document.createElement('button');
        retry.type = 'button'; retry.className = 'btn btn-outline-secondary'; retry.textContent = t("Try again");
        retry.addEventListener('click', function () { load(target, address); });
        target.appendChild(retry);
      }).finally(function () { requests.delete(controller); target.removeAttribute('aria-busy'); });
  }
  function open(button) {
    trigger = button;
    // Keep the product list/help sidebar independent, using the same native sidebar component.
    if (win.jQuery && win.jQuery.fn.sidebar) win.jQuery('.sidebar.sidebar-open').not(panel).sidebar('hide');
    sidebar('show');
  }
  if (win.jQuery) {
    win.jQuery(panel).on('shown.bs.sidebar', shown).on('hidden.bs.sidebar', closed);
    win.jQuery(panel).on('hide.bs.sidebar', function () {
      phase = 'closing'; desiredOpen = false; hidden();
    });
  }
  // Capture avoids the native toggle and any generic quick-navigation loader running a second time.
  win.document.addEventListener('click', function (event) {
    var button = event.target.closest && event.target.closest('.pixel-audit-history-button');
    if (!button) {
      if (desiredOpen && !panel.contains(event.target)) { desiredOpen = false; hidden(); reconcile(); }
      return;
    }
    event.preventDefault(); event.stopImmediatePropagation();
    if (desiredOpen) sidebar('hide'); else open(button);
  }, true);
  panel.addEventListener('click', function (event) {
    // A replaced pagination/retry node is detached before html's native outside-click handler runs.
    event.stopPropagation();
    var close = event.target.closest('[data-audit-close]');
    if (close) { sidebar('hide'); return; }
    var link = event.target.closest('[data-audit-page]');
    if (!link) return;
    event.preventDefault();
    var target = link.closest('[data-audit-details]') || content;
    if (target === content) cancel();
    load(target, link.href);
  });
  panel.addEventListener('change', function (event) {
    if (event.target.matches('[data-audit-restore-field]')) {
      event.target.closest('.pixel-audit-event').querySelector('[data-audit-restoration]').textContent = '';
      return;
    }
    if (!event.target.matches('[data-audit-family]')) return;
    var url = new win.URL(config.endpoint, win.location.href);
    url.searchParams.set('family', event.target.value);
    cancel(); load(content, url.href);
  });
  panel.addEventListener('toggle', function (event) {
    if (event.target.tagName !== 'DETAILS' || !event.target.open) return;
    var target = event.target.querySelector('[data-audit-details]');
    if (target && !target.dataset.loaded) { target.dataset.loaded = '1'; load(target, target.dataset.auditDetails); }
  }, true);
  win.document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && desiredOpen) { event.preventDefault(); sidebar('hide'); }
  });
}(window));
