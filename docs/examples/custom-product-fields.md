# Custom product fields

Assume your module already owns a translated `example_note` property in Product and
its existing `product_lang` column. Audit does not add that property or its storage.

Use the [registration example](../api/restoration.md#product-objectmodel-properties).
Register the hook both on install and in an upgrade for existing shops. Enable the
Example product fields family in Audit settings. Save a product twice with different
notes. The journal should show one extension event with keys such as
`example_note.lang_1`, alongside any distinct native changes.

Prefix ownership excludes these properties from the native snapshot collector after
registration. `setFieldLabels()` supplies readable labels even for older technical
labels. The common presenter adds the language name; do not put it in your base label.

For SQL writes outside ObjectModel hooks, explicitly call:

```php
$audit->watch(\Pixel\Module\Audit\Api\Subject::product($productId, $shopId));
// Persist the business change through the owning module's validated write path.
// If this code opened a transaction, COMMIT it before calling $audit->flush().
```

With restoration permission, the preview should propose the first note only while
the current value still equals the event's second note. A later edit must produce a
conflict. Another shop/language and unrelated native fields must remain unchanged.
