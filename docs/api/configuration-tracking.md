# Configuration tracking

Register exact keys from the owning module's hook, never a prefix or user-entered key:

```php
$registry->addConfigurationParameter(new \Pixel\Module\Audit\Api\ConfigurationParameter(
    key: 'EXAMPLE_FEATURE_ENABLED',
    owner: 'example_extension',
    label: 'Enable the example feature',
    type: 'boolean',
    translated: false,
    defaultEnabled: false
));
```

Types: text, boolean, integer, decimal, JSON and set. Translated parameters track each
language separately. Global, shop-group and shop overrides are distinct. Removing an
explicit override is logged as removing that override, not as setting the inherited value.

Enable the configuration family and select the exact key in Audit settings. Registration
reads no current values. Secret-like names and internal Audit keys are refused. The same
key cannot belong to two different owners.

Supported native service/ObjectModel paths are observed automatically. Direct static
`Configuration` or SQL calls in third-party modules need an explicit integration:

```php
$write = static fn (): bool => Configuration::updateValue('EXAMPLE_FEATURE_ENABLED', true);
$module = Module::isEnabled('pixel_audit') ? Module::getInstanceByName('pixel_audit') : null;
if ($module instanceof Pixel_audit) {
    $module->observeConfigurationChange([
        'operation' => 'update',
        'key' => 'EXAMPLE_FEATURE_ENABLED',
        'owner' => 'example_extension',
    ], $write);
} else {
    $write();
}
```

The scope also supports `delete_all` for `Configuration::deleteByName`. Follow the
existing ConfigurationObserver/bridge contract when supplying explicit shop/group
scopes. Do not claim to observe static or SQL writes merely because the key is checked.

If your module writes during install/uninstall while its hook is unavailable, register
its declarations from the trusted per-write integration too. Make that helper idempotent
for its own identical declarations; do not hide conflicting ownership. This preserves
lifecycle coverage without adding hard-coded module keys to Audit.
