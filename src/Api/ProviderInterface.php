<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

interface ProviderInterface
{
    /** Stable, unique namespace, e.g. example_extension.details. */
    public function name(): string;
    public function family(): string;
    public function supports(Subject $subject): bool;
    /** Read committed/persisted data; never the submitted form or an object cache. */
    public function snapshot(Subject $subject): Snapshot;
}
