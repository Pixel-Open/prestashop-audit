<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

/** Convenient registration for small integrations without a dedicated provider class. */
final class CallbackProvider implements ProviderInterface
{
    public function __construct(
        private string $namespace,
        private string $familyName,
        private string $subjectType,
        private \Closure $reader,
    ) {
    }
    public function name(): string
    {
        return $this->namespace;
    }
    public function family(): string
    {
        return $this->familyName;
    }
    public function supports(Subject $subject): bool
    {
        return $subject->type === $this->subjectType;
    }
    public function snapshot(Subject $subject): Snapshot
    {
        return ($this->reader)($subject);
    }
}
