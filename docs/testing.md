# Testing and release validation

## Standalone checks

```sh
composer install --working-dir=tools
composer check --working-dir=tools
php tools/build.php
```

Unit tests use the module's own source and autoloader without PrestaShop or another
module. The portable PHPStan scope covers pure/API contracts with a minimal ObjectModel
stub. It is not a substitute for analysis against real PrestaShop services/classes.
Use the host shop's autoload/bootstrap to analyse controllers and integrations too.

CI checks source on PHP 8.1, 8.2, 8.3 and 8.4. Build validation checks structure, docs,
forbidden application dependencies and production packaging. Development dependencies
are pinned against a PHP 8.1 platform in tools/composer.lock.

## Integration job

```sh
tools/integration.sh 8.2.9-8.1-nginx
tools/integration.sh 9.1.5-8.4-nginx
```

The script needs Docker only. It starts a disposable PrestaShop from the
[prestashop-flashlight](https://github.com/PrestaShop/prestashop-flashlight) image with
the given tag (PrestaShop version, PHP version, web server) and a MariaDB container,
copies the checkout into it, then removes both. On a shop that has never seen the
module it checks the installation and its hook registrations, runs every fixture of
`tests/Integration`, runs the purge command, uninstalls and installs again over the
journal that the default uninstallation keeps. CI runs it on both tags above for every
push and pull request.

## Real-shop checklist

Use disposable shops or a snapshot/restore wrapper. Never run mutating browser tests
against production. Record exact PrestaShop/PHP/database versions and test outcomes.

- Fresh install without optional modules, upgrade preserving old events and settings,
  uninstall/reinstall without accidental journal deletion.
- English employee locale and French employee locale: labels, errors, JavaScript,
  settings and restoration results. No raw HTML or translated strings used as HTML.
- Native writes and SQL integrations; unchanged values and failed business saves;
  disabled families/keys; no duplicated field ownership.
- One product save across several AJAX requests gives one operation; independent
  saves/tabs/employees stay distinct; expired/forged correlation proofs fail closed.
- Retention increases/decreases, per-family overrides, batch limits, active saves,
  hidden states, empty operations and purge backlog.
- Journal/settings/product permissions independently; direct endpoint requests,
  CSRF failures, inaccessible shops/products, shared events and empty shop context.
- Native Product fields and a generic extension's combination storage: preview only,
  nullable values, exact decimals, changed constraints, unsupported fields and HTML.
- Conflict before/after preview, changed context/rights/family/owner/contract,
  source expiration/purge, missing row/translation/combination/parent/shop association.
- Concurrent confirmation and replay: at most one successful application.
- Forced audit INSERT failure rolls back business values; a retry can then succeed.
  Successful restore adds one linked operation and does not rewrite the source.
- Global fields require all product shops; translations and shop fields remain isolated.
- Post-commit synchronization failure produces a warning without claiming a rollback.

Test runtime **PrestaShop 8.2 / PHP 8.1** and a supported **PrestaShop 9** environment
before publishing verified compatibility. Static analysis targeting PHP 8.1 is not
proof that every PrestaShop hook/service has been exercised on that runtime.

## Current status

The standalone suite passes 76 tests / 570 assertions, including multilingual
custom-feature replacement tests (predefined choices, duplicate values, genuine
additions and removals) and the generated specific-price regressions.

Four integration fixtures run from the shop root against session-local temporary
tables that shadow the shop tables. They exercise the real Audit flush and do not
modify persisted business data or historical audit events:

- `php modules/pixel_audit/tests/Integration/product-save-noise.php`: legacy hook,
  Symfony image parent resolution, a silent technical-only save (custom feature ID
  regeneration, cover/combination relation resets, category reordering, cache
  timestamps, download storage metadata), then real SEO, translated feature, image,
  supplier price, stock and download-name edits.
- `php modules/pixel_audit/tests/Integration/generated-specific-prices.php`: native
  creation suppression, real updates, additions/removals, reverted changes, manual
  prices and shop/group scopes.
- `php modules/pixel_audit/tests/Integration/default-combinations.php`: unchanged
  default, real switch, independent shop defaults, new collection baseline and a
  simultaneous real combination price change.
- `php modules/pixel_audit/tests/Integration/purge-retention.php`: per-family
  retention, batch budget, header cleanup, orphan repair by the command only and
  personal data erasure.

They pass on PrestaShop 8.2.7 / PHP 8.1.34 and PrestaShop 9.1.5 / PHP 8.4.27, and the
integration job passes on new PrestaShop 8.2.9 / PHP 8.1.34 and 9.1.5 / PHP 8.4.27 shops.

The interface, restoration, retention, permission and visual diff checks below were
run in a browser on PrestaShop 9.1.5 / PHP 8.4.27. On PrestaShop 8.2.7 / PHP 8.1.34,
a targeted read-only check covered native calls, configuration decorator loading,
shop group lookup and subscriber registration. A complete end-to-end runtime
validation on PrestaShop 8.2 / PHP 8.1 (product saves, configuration persistence,
login collection, restoration) is still pending.

## Interface checks

Inspect the journal filters, matching/page counts, operation details and the configuration page on desktop and a narrow viewport. Parameter tables may scroll within their section; they must not force the whole page to overflow. Verify the all-shops scope notice and disabled controls for read-only settings access.

Journal and product history share the field comparison component. Check both preview and confirmation, immutable source data, conflict refusal, keyboard closing/focus return, network retry and independent native product-list sidebar behaviour. Rebuild the archive after interface changes.

## Visual diff checks

Verify word-level highlighting in journal details and the product sidebar. In a restoration preview the current value is the removed side and the target value is the added side. Confirm that conflicts, signed confirmation, permissions and shop scope behave as before and the original stored event hash stays identical.

Check empty text, NULL, literal null symbols, zero, booleans, list additions/removals, duplicate list items, Unicode and multiline whitespace. Historical HTML must appear as escaped text without executing tags or handlers. Large values must show the detailed-highlighting notice and remain complete. Inspect both desktop and mobile layouts and translated labels. See [visual differences](visual-diff.md).
