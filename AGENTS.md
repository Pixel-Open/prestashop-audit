# Agent instructions

Target PrestaShop 8.2 and 9, PHP 8.1 minimum. Do not add core/template overrides.
Read README.md and the relevant docs/api guide before changing an integration.
See CONTRIBUTING.md for the development tools.

- Keep the core independent of any optional application module, table or vendor prefix.
  Register ownership, configuration, labels and storage through the public registry API.
- The supported surface is the `Pixel\Module\Audit\Api` namespace, the
  `actionPixelAuditRegisterProviders` hook and `Pixel_audit::audit()`. Other classes are
  internal. Do not break the supported surface without versioning the contract.
- Keep provider/family/field identifiers stable. Never rewrite historical JSON to improve
  presentation or migrate an owner. No HTTP input may select a SQL writer or target value.
- Expose explicit, versioned restoration allowlists. Check real storage scope, type,
  nullability, bounds, choices and model/business validations. Exact decimals are strings.
- Share the backend between journal and product sidebar. Preserve read-only preview,
  signed one-use confirmation, conflict rejection and full revalidation under locks.
- Preserve native product read/update, audit read, dedicated restoration rights, source
  family/module availability and shop controls. Global fields require all product shops.
- Write selected fields only. Business data and audit must commit atomically; reread the
  exact result. No adapter COMMIT, DDL, TRUNCATE or irreversible external side effects.
  Synchronization hooks belong after COMMIT. Report post-commit failures as warnings.
  The restoration rules above are detailed in docs/api/restoration.md.
- English source strings, comments and documentation. Translate user-facing messages with
  PrestaShop's system. Add or remove each string in both translations/en-US and
  translations/fr-FR catalogs (trans-unit id = md5 of the source string).
  Keep comments only for contracts, invariants and non-obvious reasons.
  Use the ASCII hyphen, not Unicode dash characters, in source and labels.
- Keep examples generic and executable. Do not embed host project paths, credentials,
  deployment commands or private module dependencies in public documentation.
- Run `composer check --working-dir=tools` and `tools/integration.sh <prestashop-flashlight tag>`
  (Docker; installs the module in a disposable shop and runs `tests/Integration`), or the
  relevant `php modules/pixel_audit/tests/Integration/*.php` fixtures from the root of a
  disposable shop. Do not claim runtime compatibility from static analysis alone.
- A released version is frozen: a later change opens a new version with its own
  CHANGELOG.md section, never new lines under a published one. Add an upgrade/ migration
  for hook, tab or schema changes, without erasing audit data.
- Do not install development dependencies into runtime vendor/. Tests and tools are
  excluded from installable release archives. In a host project, run mutating browser
  tests only through its snapshot/restore wrapper.
