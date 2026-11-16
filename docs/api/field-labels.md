# Field labels and language names

`FieldLabelProviderInterface` is optional and complements `ProviderInterface`:

```php
public function fieldLabel(string $field): ?string
{
    return ['packaging_note' => 'Packaging note', 'advisor_name' => 'Advisor name'][$field] ?? null;
}
```

The method receives the base key without `.lang_ID`. Return plain text, with no HTML
or language suffix. Return NULL to preserve the stored label. Journal, product panel
and restoration preview share this read-time resolver. Stored events are not rewritten.

`ObjectModelProvider` already implements the interface. Extensions can call
`setFieldLabels(['example_note' => 'Packaging note'])` before registering it. English
native labels are translated through `Modules.Pixelaudit.Admin`; extension-owned labels
should use their own translation domain in their provider.

The resolver replaces the legacy `[langue N]` or new `[language N]` suffix with the
configured language name. Inactive languages are included. An unknown/deleted language
uses `Deleted language (#N)` instead of inventing a name.

A removed provider falls back to its stored label. This is the current presentation
label, not a historical record of every field rename. The label API changes neither
field keys, write permissions nor restoration eligibility.

Keep the dependency optional: do not load a class implementing the interface against
an older Audit version that does not provide it. Guard interface/class loading in the
registration hook, or maintain a compatible provider class for that older version.
