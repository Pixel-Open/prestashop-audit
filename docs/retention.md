# Retention and data lifecycle

The default global duration is 180 days. Supported durations are 1 to 3650 days;
a per-family value of 0 inherits the global setting. Disabling a family stops new
collection but does not immediately erase its history.

```sh
php bin/console pixel:audit:purge --env=prod --no-debug --batch-size=500 --max-batches=100
```

Without any scheduling, the module purges by itself: after a request that recorded
audit data, at most once per hour, it runs up to two batches of 500 rows per category.
This keeps the journal bounded on hosting without cron or CLI access. On large shops,
or to clear a backlog, also schedule the command under the shop's PHP user. `batch-size` is bounded from 1 to
5000; `max-batches` limits work per category per invocation. The purge separately
counts expired events, hidden merge states, correlation tickets and orphan operations.
Run it more frequently when there is a backlog.

No expiry is stored per event. The purge compares the operation date with the **current**
retention policy, including for older events. Restoration also applies current
retention before physical deletion. Reducing retention can therefore make a source
unrestorable immediately. Increasing it cannot recover already deleted data.

Purge batches use transactions and retain tickets whose hidden states still require
cleanup. Active correlated saves are not purged under their held lock. Empty operation
headers older than one hour are also cleaned in bounded batches.

Restoration creates a new operation using its provider family's retention. Source IDs
remain as references even after the original event is purged. They are not a backup.

By default, uninstall preserves `PREFIX_pixel_audit_operation`,
`PREFIX_pixel_audit_event`, `PREFIX_pixel_audit_ticket` and module configuration.
Reinstallation reuses them. Permanent removal is an explicit choice: enable "Delete
audit data on uninstall" in the settings, after a backup and a review of legal
retention requirements. Uninstalling then drops the three tables and the module
configuration. A module reset uninstalls first, so it deletes them too.

## Personal data (GDPR)

When the official `psgdpr` module is installed, Pixel Audit answers its two hooks:

- `actionDeleteGDPRCustomer` deletes the events of the customer and of its current
  addresses, in every shop, including the events written by the deletion itself.
- `actionExportGDPRData` adds those events to the customer's export, one row per
  changed field, without the name of the employee who made the change.

Order events are kept, as PrestaShop keeps the orders. Events of addresses that were
physically deleted before the erasure request can no longer be linked to the customer:
they expire with the address retention. Without `psgdpr`, the hooks are never called.

Employee data has no erasure path: the author name of each operation and, when login
history is enabled, the login IP address stay in the journal until retention expires.
Size the employee family retention accordingly.
