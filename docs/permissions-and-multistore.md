# Permissions and multistore

| Action | Required permissions |
| --- | --- |
| Read the journal | Read `AdminPixelAudit`, within the employee's allowed shop context |
| Edit settings | Read and update `AdminPixelAuditSettings` |
| Read product history | Native product read and journal read |
| Restore fields | Product read/update, journal read and update `AdminPixelAuditRestore` |

Restoration permission is granted by default only to SuperAdmin, including when a
non-SuperAdmin profile installs the module. The technical restoration tab is hidden
from the menu but available in the native profile-permission screen.

The core product editor can require update permission to open its page. Audit does
not bypass that core restriction; its history endpoint remains read-only and applies
its own product-read/journal-read checks.

Every endpoint enforces permissions server-side. POST operations also require a
session-bound CSRF proof. Knowing an operation, event or product ID grants no access.
An empty shop context never grants access to global events. Shared events with shop
ID `0` require at least one authorized shop in the current context.

Global settings do not imply global read access. Product history restricts results to
that product and accessible shops, even when an operation also changed other products.
AJAX history responses are private and must not be cached by a shared proxy.

For restoration, translated fields are written only in the source event's existing
shop/language row. Global fields require employee access to every shop associated
with the product, or SuperAdmin. The preview lists the affected shops. Changing shop
context, permissions or associations invalidates a stale preview.

The owning module must remain enabled, including in the source shop; the source
family must be enabled and its current retention must still allow the event. The
restoration permission is currently shared by all providers, not per extension.
