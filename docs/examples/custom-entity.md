# Custom entity collection

A module can track its own entity without modifying the core SubjectResolver. Assume
it owns `PREFIX_example_note(id_note, note)` and has registered the CallbackProvider
from [providers](../api/providers.md).

From trusted business code, with identifiers already authorized:

```php
$subject = new \Pixel\Module\Audit\Api\Subject(
    'example_note', $noteId, $shopId, 'product', $productId
);
$audit->watch($subject);
// Persist the note through your repository and handle any business transaction.
// Flush after COMMIT if you opened the transaction yourself.
```

The provider reads before/after from storage. The entity appears in the journal and in
its parent product's history, restricted to the source shop scope. Use NULL root fields
when it has no product parent; it then appears as its own root in the general journal.

Reading a custom entity's audit history does not grant business permissions to its
underlying data. The write path must authorize its own employee, shop and parent.
Custom entity collection does not automatically make its fields restorable. The current
restoration API only handles Product properties and declared combination annexes.
