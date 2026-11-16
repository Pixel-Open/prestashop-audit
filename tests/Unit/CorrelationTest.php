<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Correlation\EventMerger;
use Pixel\Module\Audit\Correlation\SignedOperation;

final class CorrelationTest extends TestCase
{
    public function testTokenIsBoundToEmployeeSessionProductAndShopScope(): void
    {
        $signer = new SignedOperation(str_repeat('s', 32));
        $token = $signer->issue(3, 'session-one', 480, [2, 1], 1000);
        $claims = $signer->verify($token, 3, 'session-one', [1, 2], 1001);
        self::assertNotNull($claims);
        self::assertSame(480, $claims['product']);
        self::assertNull($signer->verify($token, 4, 'session-one', [1, 2], 1001));
        self::assertNull($signer->verify($token, 3, 'session-two', [1, 2], 1001));
        self::assertNull($signer->verify($token, 3, 'session-one', [1], 1001));
        self::assertNull($signer->verify($token, 3, 'session-one', [1, 2], 1600));
        self::assertNull($signer->verify($token . 'x', 3, 'session-one', [1, 2], 1001));
        self::assertNull($signer->verify('client-selected-operation', 3, 'session-one', [1, 2], 1001));
    }

    public function testEachSaveGetsAnIndependentNonce(): void
    {
        $signer = new SignedOperation(str_repeat('s', 32));
        $one = $signer->verify($signer->issue(3, 'session', 480, [1], 1000), 3, 'session', [1], 1001);
        $two = $signer->verify($signer->issue(3, 'session', 480, [1], 1000), 3, 'session', [1], 1001);
        self::assertNotSame($one['nonce'], $two['nonce']);
    }

    public function testPersistentMergeKeepsFirstBeforeAndLastAfter(): void
    {
        $merger = new EventMerger();
        $event = static fn ($a, $b) => ['action' => 'update', 'changes' => ['price' => ['label' => 'Prix', 'type' => 'decimal', 'before' => $a, 'after' => $b]]];
        $first = $merger->merge(null, $event('10', '12'));
        $second = $merger->merge($first, $event('12', '15'));
        $changes = json_decode($second['changes'], true);
        self::assertSame('10', $changes['price']['before']);
        self::assertSame('15', $changes['price']['after']);
        self::assertSame(1, $second['is_visible']);
        $cancelled = $merger->merge($second, $event('15', '10'));
        self::assertSame(0, $cancelled['is_visible']);
        self::assertSame([], json_decode($cancelled['changes'], true));
        $resumed = $merger->merge($cancelled, $event('10', '20'));
        self::assertSame('10', json_decode($resumed['changes'], true)['price']['before']);
        self::assertSame(1, $resumed['is_visible']);
    }

    public function testCreationThenDeletionDisappearsButExistingDeletionRemains(): void
    {
        $merger = new EventMerger();
        $create = ['action' => 'create', 'changes' => ['name' => ['label' => 'Nom', 'type' => 'text', 'before' => null, 'after' => 'A']]];
        $delete = ['action' => 'delete', 'changes' => ['name' => ['label' => 'Nom', 'type' => 'text', 'before' => 'A', 'after' => null]]];
        self::assertSame(0, $merger->merge($merger->merge(null, $create), $delete)['is_visible']);
        self::assertSame('delete', $merger->merge(null, $delete)['action']);
        self::assertSame(1, $merger->merge(null, $delete)['is_visible']);
    }

    public function testValidCapabilityCannotGroupAnotherProductOrShop(): void
    {
        $operation = new \Pixel\Module\Audit\Correlation\ProductOperation(['product' => 480, 'shops' => [1]]);
        self::assertTrue($operation->accepts(\Pixel\Module\Audit\Api\Subject::product(480, 1)));
        self::assertFalse($operation->accepts(\Pixel\Module\Audit\Api\Subject::product(481, 1)));
        self::assertFalse($operation->accepts(\Pixel\Module\Audit\Api\Subject::product(480, 2)));
        self::assertFalse($operation->accepts(new \Pixel\Module\Audit\Api\Subject('customer', 480, 1)));
        self::assertTrue($operation->accepts(new \Pixel\Module\Audit\Api\Subject('combination', 12, 1, 'product', 480)));
    }
}
