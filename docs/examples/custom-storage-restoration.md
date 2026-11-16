# Custom combination storage and restoration

The complete optional [example module](../../examples/example_audit_extension) owns
`PREFIX_example_audit_combination` with exact decimal capacity, unit and container label.
It depends on no other extension. It demonstrates the same API used by third-party
integrations, without special cases in the Audit core.

1. Copy `examples/example_audit_extension/` to the shop's `modules/` directory.
2. Install it through Module Manager. Audit may be absent; ordinary capacity writes
   still work. The restoration adapter loads only with a compatible Audit API.
3. Enable the Example capacities family in Audit settings and grant the normal dedicated
   restoration permission to the employee profile that will test undo.
4. From an authorized back-office write path, call the example module's
   `saveCapacity($combinationId, '5.000', 'L', 'Can')`, then save another value.
5. Inspect the event in the journal or product history. Preview undo and confirm.

The example exposes no HTTP write endpoint and no new editable product form. Your
application must provide its own permission-checked business UI. The helper validates
capacity format, unit, text and the source shop's combination association. It is not an
alternative to employee authorization.

Its provider returns Snapshot(false) for an absent row. Its adapter refuses missing
rows instead of recreating them. All values are global: access to every product shop
is required. The first creation is logged but cannot be undone as an update. Only a
subsequent update event is restorable.

Read `CapacityStorage.php` for fixed column mapping, row locks, parent verification,
NULL preservation and exact decimal writing. No float conversion, native-column change,
override or dynamic SQL identifier is needed.

Also test a conflict after preview, replay/concurrent confirmation, a revoked right,
a removed source/row/association, disabled family, shortened retention and a forced
audit INSERT failure. On that failure no capacity value may remain changed. See
[testing](../testing.md) for the common checklist.
