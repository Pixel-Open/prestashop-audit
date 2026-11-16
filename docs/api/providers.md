# Providers and operations

Register `actionPixelAuditRegisterProviders` at installation and in your extension's
upgrade migration. Inside the hook, check that `$params['registry']` is an Audit
`Registry`. Keep Audit optional: load classes that implement its interfaces only
inside a compatible, guarded registration path.

The supported surface is the `Pixel\Module\Audit\Api` namespace, this hook and
`Pixel_audit::audit()`. Everything else is internal and may change between releases.

A `ProviderInterface` supplies `name()`, `family()`, `supports(Subject)` and
`snapshot(Subject)`. Names are stable namespaces such as `example_extension.notes`.
A snapshot contains existence plus a map of `Field(value, label, type, sensitive)`.
Supported comparison types include text, integer, boolean, decimal, JSON and sets.
Decimals should be plain exact strings; no floats or scientific notation.

```php
$registry->add(new \Pixel\Module\Audit\Api\CallbackProvider(
    'example_extension.notes', 'example_notes', 'example_note',
    static function (\Pixel\Module\Audit\Api\Subject $subject): \Pixel\Module\Audit\Api\Snapshot {
        $row = Db::getInstance()->getRow(
            'SELECT note FROM `' . _DB_PREFIX_ . 'example_note` WHERE id_note=' . $subject->id,
            false
        );
        return $row
            ? new \Pixel\Module\Audit\Api\Snapshot(true, [
                'note' => new \Pixel\Module\Audit\Api\Field($row['note'], 'Note'),
            ])
            : new \Pixel\Module\Audit\Api\Snapshot(false);
    }
), 'Example notes');
```

Read the master connection with SQL/object caches disabled. Never build a snapshot
from the submitted form. Call `$audit->watch($subject)` before the business write.
For a creation you can use `watch($subject, true)` when the identifier is known.
`Snapshot(false)` represents an absent object, not an existing row full of NULLs.

Use `new Subject('example_note', $id, $shopId, 'product', $productId)` to attach an
annex entity to a product's history. Validate the parent and shop in the business
code; a root declaration is metadata, not authorization.

For already persisted before/after values, register `ExplicitProvider` and call
`recordChange()` as shown in the README. It is not polled by the snapshot collector.

`beginOperation()` and `endOperation()` group trusted explicit calls within one PHP
request. Never accept an operation ID from an HTTP parameter. The product editor's
signed AJAX correlation is managed separately by Audit.

Normal collection flushes at request shutdown. Call `flush()` after COMMIT when your
code owns a transaction. Audit refuses to implicitly commit another caller's transaction.
Ordinary logging failure produces a diagnostic and does not turn a successful business
write into a rollback. Controlled restoration has the stronger atomic contract described
in [restoration](restoration.md).

Secrets are removed before buffering, including sensitive JSON keys. Mark any additional
private field as sensitive and never put credentials under an innocuous key. Values are
limited to 256 KiB and events to 2 MiB. Stable field keys must not be reused with a new meaning.
