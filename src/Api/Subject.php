<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

final class Subject
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly int $shopId = 0,
        public readonly ?string $rootType = null,
        public readonly ?int $rootId = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $type) || $id < 1 || $shopId < 0) {
            throw new \InvalidArgumentException('Invalid audit subject.');
        }
        if (($rootType === null) !== ($rootId === null)
            || ($rootType !== null && (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $rootType) || $rootId < 1))) {
            throw new \InvalidArgumentException('Invalid audit root.');
        }
    }

    public static function product(int $id, int $shopId): self
    {
        return new self('product', $id, $shopId);
    }

    public function key(): string
    {
        return $this->type . ':' . $this->id . ':' . $this->shopId
            . ':' . $this->rootKey();
    }

    public function rootKey(): string
    {
        return ($this->rootType ?? $this->type) . ':' . ($this->rootId ?? $this->id);
    }
}
