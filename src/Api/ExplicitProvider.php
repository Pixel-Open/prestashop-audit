<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

/** Field owner for recordChange(); not polled by the snapshot collector. */
final class ExplicitProvider implements ProviderInterface
{
    public function __construct(private string $namespace, private string $familyName, private string $subjectType)
    {
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
        return new Snapshot(false);
    }
}
