<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Api\{Subject, Snapshot, Field};
use Pixel\Module\Audit\Core\{ShopScope, Buffer, Comparator};

final class ReviewTest extends TestCase
{
    public function testEquivalentRootsCannotGenerateTwoLocalEvents(): void
    {
        $buffer = new Buffer(new Comparator());
        $snapshot = static fn ($value) => new Snapshot(true, ['price' => new Field($value, 'Prix', 'decimal')]);
        $buffer->observe(Subject::product(480, 1), 'product', 'products', $snapshot('1'), $snapshot('2'));
        $buffer->observe(new Subject('product', 480, 1, 'product', 480), 'product', 'products', $snapshot('2'), $snapshot('3'));
        $events = $buffer->events();
        self::assertCount(1, $events);
        self::assertSame('1', $events[0]['changes']['price']['before']);
        self::assertSame('3', $events[0]['changes']['price']['after']);
    }

    public function testAnEmptyOrInvalidContextDoesNotInventGlobalShopAccess(): void
    {
        self::assertSame([], ShopScope::allowed([], [1], false));
        self::assertSame([], ShopScope::allowed([0, -1], [1], true));
        self::assertSame([], ShopScope::allowed([2], [1], false));
        self::assertSame([1], ShopScope::allowed([0, -1, 1, 1], [], true));
    }
}
