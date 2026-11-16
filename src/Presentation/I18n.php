<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Presentation;

final class I18n
{
    public static function trans(string $message, array $parameters = [], ?string $locale = null): string
    {
        if (!class_exists(\Context::class)) {
            return strtr($message, $parameters);
        }
        return \Context::getContext()->getTranslator()->trans($message, $parameters, 'Modules.Pixelaudit.Admin', $locale);
    }

    public static function labels(array $labels): array
    {
        return array_map([self::class, 'trans'], $labels);
    }

    public static function javascript(): string
    {
        $messages = [
            "History",
            "Product history",
            "Restoration response unavailable. Reload the product to check its state before any further action.",
            "Save your changes or reload the product before restoring data.",
            "The product will reload. Unsaved changes will be lost. Continue?",
            "Reload the product before making further changes.",
            "Restoration in progress. Please wait before continuing.",
            "Select at least one field to preview.",
            "Preview unavailable. Please try again.",
            "Unable to load history. ",
            "Loading history...",
            "Product history #",
            "Reload product",
            "Try again",
            "Response unavailable. Reload the history to check whether the restoration completed before trying again.",
            "Preparing preview...",
            "Restoring...",
            "Reload history",
        ];
        return json_encode(array_combine($messages, array_map([self::class, 'trans'], $messages)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
