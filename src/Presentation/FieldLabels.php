<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Presentation;

use Pixel\Module\Audit\Api\{Registry, FieldLabelProviderInterface};

/** Resolve at read time without rewriting immutable historical events. */
final class FieldLabels
{
    public function __construct(private Registry $registry, private array $languages)
    {
    }

    public function label(string $provider, string $key, string $historical): string
    {
        $base = $key;
        $language = null;
        if (preg_match('/^(.+)\.lang_([1-9][0-9]*)$/D', $key, $match)) {
            $base = $match[1];
            $language = $match[2];
            $historical = preg_replace('/\s*\[(?:langue|language)\s+#?\d+\]$/ui', '', $historical);
        }
        $source = $this->registry->providers()[$provider] ?? null;
        $label = $source instanceof FieldLabelProviderInterface ? $source->fieldLabel($base) : null;
        $label = $label !== null && trim($label) !== '' ? $label : $historical;
        $label = I18n::trans($label);
        return $label . ($language !== null ? ' [' . ($this->languages[$language] ?? I18n::trans('Deleted language (#') . $language . ')') . ']' : '');
    }
}
