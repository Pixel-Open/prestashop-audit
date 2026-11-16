# PrestaShop Audit Log

## Presentation

Pixel Audit records back-office changes as operations with an author, date, shop and
field-level before/after values. Merchants can inspect the journal or open product
history directly from the product editor.

The module works on its own. Other modules can use its provider, configuration,
field-label and restoration APIs without adding a core override.

## Features

- Native product, combination, stock, category, customer, address, order and employee history.
- Product-related changes grouped across the AJAX requests of a single save.
- Explicit allowlist of tracked shop configuration parameters.
- Global retention with per-family overrides and a bounded purge command.
- Shop-aware access controls and a separate restoration permission.
- Selected-field undo with preview, conflict detection, signed confirmation and an immutable audit trail.
- Word and list diffs with removed/added fragments in the journal, product history and restoration preview.
- English source messages and a French translation through PrestaShop's translation system.

See [coverage and limitations](docs/configuration.md#coverage-and-limitations) before
relying on the journal for a particular business process.

## Requirements

- PrestaShop 8.2 or 9.x.
- PHP 8.1 or later, with JSON, mbstring, PDO and PDO MySQL.
- MySQL or MariaDB with InnoDB for the product, audit and any restoration adapter tables.

The target compatibility range is distinct from the versions actually tested. See
[testing](docs/testing.md) for the current validation status.

## Installation

### Admin

Upload the installable `pixel_audit.zip` archive in **Modules > Module Manager > Upload a module**.
The archive must contain a single `pixel_audit/` directory. Install **Audit log**.

### Manually

Copy the module to `modules/pixel_audit`, then install it from the Module Manager.
The runtime Composer autoloader is included; merchants do not need development tools.
For a source checkout, run `composer install --no-dev --optimize-autoloader` before packaging.

## Configuration

Open **Configure** from the Module Manager. Select the families and exact configuration
keys to track. Native families are enabled on installation; extension families require
an explicit opt-in. Disabling collection does not delete existing history.

Retention defaults to 180 days. A family value of `0` inherits the global duration.
Expired data is purged automatically in small batches, at most once per hour, after an
audited request. For large shops, also schedule the purge command from the shop root
using the shop's PHP user:

```sh
php bin/console pixel:audit:purge --env=prod --no-debug --batch-size=500 --max-batches=100
```

The command processes bounded batches. Schedule it more frequently if it cannot clear
the backlog in one run. No cron job is installed automatically; the hourly automatic
purge needs none.

[Configuration](docs/configuration.md) | [Translations](docs/translations.md) | [Retention](docs/retention.md) |
[Permissions and multistore](docs/permissions-and-multistore.md)

## Usage

The Diagnostics tab shows the module version, the last recorded collection error and global storage statistics for its three tables (exact row counts and estimated data/index sizes). This historical entry does not indicate whether a problem is still ongoing; consult the PHP logs for details.

Open **Advanced Parameters > Audit log** to filter operations and inspect changes. The operation list shows matching and page counts within your shop scope. Visible filter labels, a reset action and responsive before/after comparisons keep the journal usable on smaller screens.
Choose 20 (default), 50, 100 or 200 operations per page in the journal filters. The back-office cookie remembers this choice for future visits.
On a product editor, **History** opens a panel loaded on demand, including related
combination, price and stock events within the employee's shop scope.

For a restorable update, select the fields and choose **Preview undo**. The preview
compares the current values with the source event's `after` values. Any conflict
refuses the operation. Confirmation restores only the selected `before` values and
creates a new linked operation. Reading history alone does not permit restoration.

The [visual diff](docs/visual-diff.md) highlights removed text in red and added text in green, while keeping unchanged context readable. In a preview, it compares the current values with the values to restore.

This is selected-field undo, not a complete backup or a way to recreate deleted objects.

### Back-office logins

The Back-office logins tab filters the journal to successful employee logins.
Details include the employee, UTC timestamp and IP address. Collection requires the
Enable back-office login history option (off by default), independently of employee
change tracking. Employee family retention still applies. Login events are global
(shop 0) and visible to employees with audit read access in a valid shop context.
Failed attempts, logout and existing sessions are not collected; past logins cannot
be reconstructed. No credentials or session identifiers are stored.
PrestaShop 8.2 uses the native login hook; PrestaShop 9 uses Symfony LoginSuccessEvent.
Employee names and login IP addresses are personal data kept until
[retention](docs/retention.md) expires.

## Developer API

Register an extension through `actionPixelAuditRegisterProviders`. Use stable provider,
family and field identifiers so existing events remain readable across upgrades.

```php
public function hookActionPixelAuditRegisterProviders(array $params): void
{
    $registry = $params['registry'] ?? null;
    if (!$registry instanceof \Pixel\Module\Audit\Api\Registry) {
        return;
    }

    $registry->add(new \Pixel\Module\Audit\Api\ExplicitProvider(
        'example_audit_extension.product', 'example_product_data', 'product'
    ), 'Example product data');
}
```

Record a persisted change from trusted business code:

```php
$module = Module::getInstanceByName('pixel_audit');
if ($module instanceof Pixel_audit && ($audit = $module->audit()) !== null) {
    $audit->recordChange(
        \Pixel\Module\Audit\Api\Subject::product($productId, $shopId),
        'example_audit_extension.product',
        'packaging_note',
        new \Pixel\Module\Audit\Api\Field($oldValue, 'Packaging note'),
        new \Pixel\Module\Audit\Api\Field($persistedValue, 'Packaging note')
    );
}
```

Use persisted values, not submitted form data. For snapshot providers, call `watch()`
**before** the write. When the business code owns a transaction, flush the journal
only **after** its COMMIT. Normal collection is observational; controlled restoration
uses a separate atomic business-write and audit-write transaction.

The supported surface is the `Pixel\Module\Audit\Api` namespace, the
`actionPixelAuditRegisterProviders` hook and `Pixel_audit::audit()`. Other classes are
internal and may change between releases.

- [Providers and operations](docs/api/providers.md)
- [Configuration tracking](docs/api/configuration-tracking.md)
- [Field labels and language names](docs/api/field-labels.md)
- [Restoration contracts](docs/api/restoration.md)

## Examples

All examples use a fictitious extension and standard PrestaShop APIs:

- [Custom product fields](docs/examples/custom-product-fields.md)
- [Custom entity collection](docs/examples/custom-entity.md)
- [Custom combination storage and restoration](docs/examples/custom-storage-restoration.md)

A complete optional [example module](examples/example_audit_extension) demonstrates
snapshot collection, nullable exact decimals, configuration registration and a
transactional combination storage adapter. It lives in the repository only: it is
neither installed by Pixel Audit nor shipped in the release archive.

## Upgrade and removal

Version 1.0.0 is the first public release. Use the Module Manager update action for
future releases. Extension declarations and storage adapters belong to their owning
modules; follow [the integration checklist](docs/integrations.md) when connecting them.
Keep historical identifiers and JSON unchanged.

Uninstalling Pixel Audit removes its hooks and tabs but **keeps the journal and
settings** by default, to avoid accidental data loss. The purge command is unavailable
while the module is uninstalled. To remove everything, enable "Delete audit data on
uninstall" in the settings first. Customer erasure and export requests are handled
through the `psgdpr` module. See [retention](docs/retention.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for standalone tools, tests and release packaging,
and [AGENTS.md](AGENTS.md) for integration rules. User-facing source strings, comments
and documentation must be in English. Keep comments focused on invariants and reasons.

## License

[MIT](LICENSE), Copyright Pixel Open.
