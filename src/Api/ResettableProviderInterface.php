<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

/** Optional lifecycle for a provider keeping request-cycle state. */
interface ResettableProviderInterface extends ProviderInterface
{
    public function reset(): void;
}
