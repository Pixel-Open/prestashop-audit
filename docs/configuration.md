# Configuration and coverage

Settings apply globally to all shops. Changes to Pixel Audit's own settings are always
audited, even when every selectable family is disabled. Journal dates are UTC.

Native families are enabled on installation. Newly registered extension families are
not enabled automatically. Select them explicitly before testing an integration.
Each configuration key has its own checkbox, in addition to the configuration family.

The native configuration allowlist covers shop availability and contact information,
maintenance, catalog/B2B/guest modes, stock behavior, tax, default country/language/
currency/carrier, carrier sorting, shipping thresholds and handling, returns,
packaging, terms and conditions, invoice settings and mail method/format. The complete
list is shown in the settings screen; passwords and arbitrary prefixes are excluded.

## Coverage and limitations

| Family | Observed writes |
| --- | --- |
| Products | ObjectModel fields, translations, shops, category/feature/tag/carrier/accessory/document/pack/supplier relations, image and downloadable-file metadata |
| Combinations | ObjectModel fields, attribute and image relations, linked to the parent product |
| Specific prices | Persisted ObjectModel writes, excluding temporary cart-bound prices; catalog-rule prices compared across observed product writes without technical row-ID noise |
| Stock | StockAvailable ObjectModel writes, linked to the product |
| Categories | ObjectModel fields and translations |
| Customers and addresses | Curated identity, business and address fields; customer groups |
| Orders | Main fields, statuses, lines, shipments, payments and credit slips |
| Employees and permissions | Curated identity/profile fields and native profile-permission actions |
| Configuration | Explicitly registered keys through supported service/hook/integration paths |

Direct SQL in another module requires an explicit provider and a watch or record call.
Core services, imports and third-party modules that bypass observed paths are not
magically covered. Normal snapshots are read at watch/flush boundaries; concurrent
outside writes can affect the values observed. This is not database-level auditing.

For observed product writes, generated catalog-rule prices are captured before the
write and compared at flush by rule, product, combination and pricing scope. Identical
regenerations are omitted; changed values, newly applicable prices and SQL removals
remain visible under the existing native specific-price provider. Manual specific
prices keep their native collection. Existing historical events are not rewritten.
An unobserved SQL write or rule application without an initial product snapshot
cannot be reconciled this way.

The native combination provider also captures initial per-shop default flags at
the product boundary. This prevents the legacy editor's temporary SQL reset of
`default_on` from appearing as a change when the same default is selected again.
Other combination fields and genuinely changed default flags remain observed.

Image cover flags and combination attribute/image sets are also read before
technical resets. Reassigning the same values is silent; real cover or association
changes remain visible. Custom feature values replaced by new IDs are omitted
only when the feature, all language values and multiplicity match exactly, and
both the removed and added values are custom. Predefined choices, changed or
missing translations, additions and removals remain observed. Historical field
keys are kept intact; existing events are never rewritten.

Legacy product actions are captured early through the `actionAdminControllerInitAfter`
hook; Symfony product, image and combination actions use a pre-controller listener.

Collection reads each watched object before and after the write, in the same request.
Bulk imports through observed paths therefore cost extra queries per object; disable
the affected families during a large import if the history is not needed. Correlated
requests of one product save, and concurrent writes of one tracked configuration key,
wait for a named database lock for up to five seconds.

Tracked configuration relies on a decorator of PrestaShop's configuration adapter,
which extends that concrete class. A PrestaShop release changing the adapter's
constructor or signatures can require a module update: check Diagnostics after an upgrade.

Card data, credentials, technical secrets, download identifiers and file contents are
not retained. Names and addresses are still personal data: choose retention and access
permissions accordingly. Erasure and export requests are covered in [retention](retention.md). A database administrator can alter the audit tables.

Only explicitly declared fields support undo. Native restoration is restricted to
reference, name, description, short description, SEO title and SEO description.
Prices, stock, deleted objects, files and arbitrary associations cannot be restored
by the native writer. Extension adapters have their own explicit, validated contract.

ObjectModelProvider excludes prefixes claimed by another registered ObjectModelProvider
for the same class/type. This prevents overlapping native/extension collection without
hard-coding a particular vendor's field prefix. Integrations must register the field
owner consistently; do not register competing prefix owners.
