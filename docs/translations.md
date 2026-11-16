# Translations

Source wording is English. User-facing module messages use the
`Modules.Pixelaudit.Admin` domain through PrestaShop's translator or Smarty `{l}`.
The English catalog lists messages used by both static templates and dynamically
resolved metadata. A French catalog is shipped separately under translations/fr-FR.

JavaScript receives a JSON dictionary serialized with HTML-safe JSON flags; messages
are inserted as text. The sidebar structure and restoration notices use Smarty
fragments instead of translated HTML embedded in PHP/JavaScript source.

For another locale, add translations/LOCALE/ModulesPixelauditAdmin.LOCALE.xlf using
English source IDs and translated targets. Keep placeholders unchanged. Validate
settings, error messages, toolbar/sidebar, labels and restoration in a real back office.
Do not translate provider/family/field identifiers or persisted values.

Extension-owned labels should use that extension's own domain. The field label API
receives the base key and appends the configured language name in the common presenter.
Legacy stored labels are not rewritten when translations or labels change.
