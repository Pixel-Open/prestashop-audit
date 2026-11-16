# Integration checklist

Use this checklist when connecting an existing module to the public API. Keep stable
event providers, family IDs and field keys so existing history remains readable.

For each existing owning module:

1. Move its configuration declarations to its `actionPixelAuditRegisterProviders` hook.
   Register the hook in its installation and upgrade migration.
2. Register its existing ObjectModelProvider with the same class/type/prefix/provider
   and family. Supply its readable labels through `setFieldLabels()`.
3. Keep existing Product restoration declarations and their field keys. The registry
   now excludes claimed prefixes generically instead of knowing a specific prefix.
4. Move any formerly embedded combination storage adapter into the owner. Implement
   `RestorationStorageInterface`, then call `addRestorationStorage()` with the **same**
   provider/family/owner IDs. Remove calls to the old storage-specific registrar.
5. Keep cache/index synchronization in the owner's `afterCommit()` method. The core
   does not import optional application modules.
6. For lifecycle configuration writes while an owner's hook is disabled, explicitly
   register its own parameter declarations from its per-write observation helper.
7. Update the owning integration and Audit together. Guard optional/new APIs when
   supporting an older Audit version; never eagerly load a new interface against it.

If an owning integration is absent or inactive, its old events remain readable using
stored labels and values. Restoration is unavailable until a compatible owner registers
again. Settings for temporarily absent families and keys are preserved. Do not reinstall
or drop tables to migrate.

Existing signed previews are short-lived and must be recreated after the code/contract
version changes. Source events are never modified by this migration. Native restoration,
configuration collection, retention, rights and AJAX correlation keep their contracts.
