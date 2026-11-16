<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

/** Trusted integration code. Writes must preserve the caller's transaction. */
interface RestorationStorageInterface
{
    public function subjectType(): string;
    /** Unprefixed InnoDB table names. */
    public function tables(): array;
    public function lockProduct(int $product): void;
    /** Verify the real parent product and source shop association. */
    public function check(int $product, int $subject, int $shop, bool $lock): void;
    public function read(int $subject, array $fields, ProductRestorationDefinition $definition): array;
    /** Selected fields only. No COMMIT, DDL or external side effects. */
    public function write(array $plan): void;
    public function afterCommit(int $product): void;
}
