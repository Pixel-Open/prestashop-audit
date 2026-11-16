(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else api.install(root);
}(typeof window === 'undefined' ? globalThis : window, function () {
  'use strict';

  // Each user save consumes a different server-issued token. No debounce or time-window merge.
  function Campaign(tokens, close, refill) {
    this.tokens = tokens.slice();
    this.current = null;
    this.close = close;
    this.refill = refill;
  }
  Campaign.prototype.start = function () {
    if (this.current) this.finish(this.current);
    var token = this.tokens.shift();
    this.current = token ? {token: token, pending: 0, main: false, held: false, done: false, requested: false} : null;
    if (this.tokens.length < 2) this.refill();
    return this.current;
  };
  Campaign.prototype.ensure = function () { return this.current || this.start(); };
  Campaign.prototype.finish = function (context) {
    if (!context || context.done) return;
    context.requested = true;
    this.settle(context);
  };
  Campaign.prototype.settle = function (context) {
    if (!context || context.done || context.held || context.pending || !context.requested) return;
    context.done = true;
    if (this.current === context) this.current = null;
    this.close(context.token);
  };

  function install(win) {
    var node = win.document.getElementById('pixel-audit-product-config');
    if (!node || win.PixelAuditProductSave) return;
    var config;
    try { config = JSON.parse(node.textContent); } catch (error) { return; }
    var nativeFetch = win.fetch.bind(win);
    var refreshing = false;
    function call(action, extra) {
      var body = new win.URLSearchParams(Object.assign({action: action, audit_csrf: config.csrf, product_id: config.productId}, extra || {}));
      return nativeFetch(config.endpoint, {method: 'POST', credentials: 'same-origin', body: body});
    }
    var campaign = new Campaign(config.tokens || [], function (token) {
      call('close', {operation: token}).catch(function () {});
    }, function () {
      if (refreshing) return;
      refreshing = true;
      call('tokens').then(function (response) { if (!response.ok) throw new Error('token refresh'); return response.json(); })
        .then(function (data) { if (Array.isArray(data.tokens)) campaign.tokens = data.tokens; })
        .catch(function () {})
        .finally(function () { refreshing = false; });
    });
    // Refresh capabilities for long editing sessions. This timer never groups actions.
    win.setInterval(function () { campaign.refill(); }, 120000);
    var formSelector = 'form[name="product"], form#product_form, form#form.product-page';
    var saveSelector = '.product-form-save-button, #submit, #submit_and_stay, #product_form_save, .product-page .js-btn-save, #product_form_save_duplicate_btn, #product_form_save_go_to_catalog_btn, #product_form_save_new_btn';
    function productForm(form) { return form && form.matches && form.matches(formSelector); }
    function primaryForm() { return win.document.querySelector(formSelector); }
    function isMutation(method, address) {
      try {
        var url = new win.URL(address, win.location.href);
        var endpoint = new win.URL(config.endpoint, win.location.href);
        return !/^(GET|HEAD|OPTIONS)$/i.test(method) && url.origin === win.location.origin
          && !(url.pathname === endpoint.pathname && ['AdminPixelAuditOperation', 'AdminPixelAuditRestore'].indexOf(url.searchParams.get('controller')) !== -1);
      } catch (error) { return false; }
    }
    function isMain(address) {
      var form = primaryForm();
      if (!form) return false;
      try {
        var url = new win.URL(address, win.location.href);
        var action = new win.URL(form.action, win.location.href);
        return url.pathname === action.pathname && url.searchParams.get('controller') === action.searchParams.get('controller');
      } catch (error) { return false; }
    }
    function completed(context) {
      context.pending--;
      // Let synchronous completion callbacks launch the next request before closing.
      win.queueMicrotask(function () { campaign.settle(context); });
    }
    win.document.addEventListener('click', function (event) {
      var button = event.target.closest && event.target.closest(saveSelector);
      if (button && !button.disabled) campaign.start();
      else if (event.isTrusted && campaign.current) campaign.finish(campaign.current);
    }, true);
    win.document.addEventListener('input', function (event) {
      if (event.isTrusted && event.target.closest && event.target.closest(formSelector) && campaign.current) campaign.finish(campaign.current);
    }, true);
    win.document.addEventListener('submit', function (event) {
      if (productForm(event.target)) campaign.ensure();
    }, true);
    win.document.addEventListener('formdata', function (event) {
      if (productForm(event.target) && campaign.current) event.formData.set('_pixel_audit_operation', campaign.current.token);
    }, true);

    // Covers the shadow form submitted via jQuery/native form.submit() on PS 8.2/9.
    var submit = win.HTMLFormElement.prototype.submit;
    win.HTMLFormElement.prototype.submit = function () {
      if (!productForm(this)) return submit.apply(this, arguments);
      var context = campaign.ensure();
      var field = null;
      if (context) {
        field = win.document.createElement('input');
        field.type = 'hidden'; field.name = '_pixel_audit_operation'; field.value = context.token;
        this.appendChild(field);
      }
      try { return submit.apply(this, arguments); }
      finally { if (field) field.remove(); }
    };

    var requests = new WeakMap();
    var open = win.XMLHttpRequest.prototype.open;
    var send = win.XMLHttpRequest.prototype.send;
    var setHeader = win.XMLHttpRequest.prototype.setRequestHeader;
    win.XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
      var request = requests.get(this);
      if (request && String(name).toLowerCase() === 'x-pixel-audit-operation') request.auditHeader = String(value);
      return setHeader.apply(this, arguments);
    };
    win.XMLHttpRequest.prototype.open = function (method, address) {
      requests.set(this, {method: method, address: address});
      return open.apply(this, arguments);
    };
    win.XMLHttpRequest.prototype.send = function () {
      var request = requests.get(this);
      var context = campaign.current;
      if (!context || !request || !isMutation(request.method, request.address)) return send.apply(this, arguments);
      if (request.auditHeader && request.auditHeader !== context.token) return send.apply(this, arguments);
      if (!request.auditHeader) this.setRequestHeader('X-Pixel-Audit-Operation', context.token);
      context.pending++;
      context.main = context.main || isMain(request.address);
      var settled = false;
      function finishOnce() { if (!settled) { settled = true; completed(context); } }
      this.addEventListener('loadend', finishOnce, {once: true});
      try { return send.apply(this, arguments); }
      catch (error) { finishOnce(); throw error; }
    };
    win.fetch = function (input, init) {
      var request = input instanceof win.Request ? input : null;
      var method = (init && init.method) || (request && request.method) || 'GET';
      var address = request ? request.url : String(input);
      var context = campaign.current;
      if (!context || !isMutation(method, address)) return nativeFetch(input, init);
      var headers = new win.Headers((init && init.headers) || (request && request.headers) || {});
      headers.set('X-Pixel-Audit-Operation', context.token);
      context.pending++;
      context.main = context.main || isMain(address);
      return nativeFetch(input, Object.assign({}, init || {}, {headers: headers}))
        .finally(function () { completed(context); });
    };
    win.PixelAuditProductSave = {
      start: function () { var context = campaign.start(); return context ? context.token : null; },
      finish: function () { campaign.finish(campaign.current); },
      run: async function (callback) {
        var context = campaign.start();
        if (context) context.held = true;
        try { return await callback(); }
        finally { if (context) { context.held = false; campaign.finish(context); } }
      }
    };
  }
  return {Campaign: Campaign, install: install};
}));
