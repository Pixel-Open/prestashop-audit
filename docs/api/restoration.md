# Restoration API

Restoration undoes selected fields from a visible **update** event: target = `before`,
expected current value = `after`. It is not an arbitrary historical snapshot restore.
Create/delete events and missing objects are not recreated.

## Product ObjectModel properties

Register the Product ObjectModelProvider before its declaration:

```php
$provider = new \Pixel\Module\Audit\Collector\ObjectModelProvider(
    'Product', 'product', 'example_products', 'example_extension.product', 'example_'
);
$provider->setFieldLabels(['example_note' => 'Packaging note']);
$registry->add($provider, 'Example product fields');
$registry->addProductRestoration(new \Pixel\Module\Audit\Api\ProductRestorationDefinition(
    'example_extension.product', 'example_products', 'example_extension', '1', [
        new \Pixel\Module\Audit\Api\ProductRestorationField(
            'example_note', 'Packaging note', 'text', translated: true,
            scope: 'shop', maxLength: 255
        ),
    ]
));
```

The property, Product definition and SQL column must **already exist**. Audit creates
neither columns nor overrides. A shop-scoped scalar lives in `product_shop`; a global
scalar in `product`; translated fields use existing `product_lang` rows in the source
shop. Model type, translation, scope and nullability are checked at preview/confirmation.

## Field contract

| Argument | Contract |
| --- | --- |
| `field`, `label` | Exact stable property/key and human label; no wildcard |
| `type` | text, integer, boolean or decimal, matching the snapshot/model |
| `translated`, `scope` | Translations must be shop-scoped; scalar scope is global or shop |
| `nullable` | Explicit opt-in; Product fields also require model `allow_null` and compatible SQL |
| `choices` | Closed list, compared after normalization |
| `minimum`, `maximum` | Optional numeric integer bounds |
| `maxLength`, `maxBytes` | Text character and UTF-8 byte limits; both apply |
| `required` | Reject blank text |
| `html` | HTML Product model field; all text also passes CleanHtml validation |
| `precision`, `scale` | Exact decimal SQL capacity, e.g. 10 and 3 |

Decimal values are exact strings: `2.500` equals `2.5`. Floats, scientific notation,
invalid choices, oversized values and silent rounding are rejected. NULL, zero and
empty text retain distinct meanings. The preview renders NULL as `∅`.

Keep the allowlist explicit. Adding a form field must not automatically expose it to
restoration. Do not expose secrets, technical identifiers, files, derived values or
relations. Native validations and declared constraints both apply. Rules only present
in a Symfony form must be represented here or in model validations before exposing a field.

## Transactional combination storage

For a combination annex, implement `RestorationStorageInterface` in the owning module:

- `subjectType()` currently returns `combination`.
- `tables()` lists every additional unprefixed table touched or locked; names are validated
  and all tables must use InnoDB on the same PDO master connection.
- `lockProduct()` locks the relevant rows/associations in deterministic order.
- `check()` verifies the real parent product and source shop association, and rejects
  missing or deleted objects/rows. Do not trust the audit root alone.
- `read()` returns every requested key with its current persisted value, uncached.
- `write()` updates selected fields only and throws on failure. It must not COMMIT,
  execute DDL/TRUNCATE, send mail or perform other irreversible external actions.
- `afterCommit()` handles optional cache/index synchronization after successful commit.

Register it with `addRestorationStorage($definition, $storage)` after its snapshot
provider. This is a trusted PHP extension point, never a table name, callback or writer
supplied over HTTP. Provider/family/owner must match. The first storage contract supports
only **global, non-translated combination fields**, attached to a product. Other entity
restoration is not supported. See the [complete example](../../examples/example_audit_extension).

The core owns CSRF, permission checks, source visibility, current family retention,
module availability, typed selection, preview signing, conflict detection, transaction,
nonce uniqueness and the linked audit operation. An adapter cannot bypass those checks.
Its business validations must be compatible with the declared constraints. A transaction
cannot reverse a malicious adapter's COMMIT or external side effects; integrate trusted,
reviewed code and test rollback explicitly.

## Preview, confirmation and migration

Previews do not write and expire after five minutes. They are bound to the employee,
session, source event, selected keys, context, contract and owner version. Confirmation
accepts a signed token, never browser-supplied target values. It rereads under locks,
rejects conflicts without forcing, and enforces one successful use even concurrently.

Business changes and the new audit event commit together. Audit failure rolls back the
business write and leaves the token retryable. The new event preserves provider/family,
subject/root and source operation/event IDs. Original JSON is immutable. Global fields
are recorded for all affected product shops within one operation.

The native writer performs partial ObjectModel updates, preserving unselected values
and existing translations. ProductSave/ProductUpdate synchronization occurs after COMMIT
because third-party cache hooks can issue TRUNCATE. A synchronization failure returns a
warning after the already-committed restoration. Overridden Product/ObjectModel update
methods require dedicated review and are refused by the standard service.

Bump the contract revision when its meaning or constraints change. Keep provider,
family and field keys stable. A changed declaration/owner version invalidates an old
preview. Never relabel historical keys to migrate ownership; migrate integration code
while retaining those identifiers.
