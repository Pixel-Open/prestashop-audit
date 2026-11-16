<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

/** Optional presentation API. Return plain text or null to preserve the historical label. */
interface FieldLabelProviderInterface
{
    /** Base field key, without the .lang_ID suffix. Never grants restoration rights. */
    public function fieldLabel(string $field): ?string;
}
