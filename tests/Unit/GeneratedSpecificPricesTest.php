<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Api\Field;
use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Api\Subject;
use Pixel\Module\Audit\Collector\GeneratedSpecificPrices;
use Pixel\Module\Audit\Core\Buffer;
use Pixel\Module\Audit\Core\Comparator;

final class GeneratedSpecificPricesTest extends TestCase
{
    private function entry(int $id, array $values = [], int $shop = 1): array
    {
        $fields = [];
        foreach ($values + ['id_specific_price_rule' => 62, 'id_product_attribute' => 0, 'id_shop' => $shop, 'from_quantity' => 1, 'reduction' => '0.3', 'price' => '-1', 'from' => '2026-06-24 00:00:00', 'to' => '2026-07-01 00:00:00'] as $name => $value) {
            $type = in_array($name, ['reduction', 'price'], true) ? 'decimal' : (is_int($value) ? 'integer' : 'text');
            $fields[$name] = new Field($value, $name, $type);
        }
        return ['subject' => new Subject('specific_price', $id, $shop, 'product', 3402), 'snapshot' => new Snapshot(true, $fields)];
    }

    private function compare(array $before, array $after): array
    {
        $reads = 0;
        $collector = new GeneratedSpecificPrices(static function (int $product) use (&$reads, $before, $after): array {
            self::assertSame(3402, $product);
            return $reads++ === 0 ? $before : $after;
        });
        $collector->capture(3402);
        $collector->capture(3402);
        self::assertSame(1, $reads);
        $buffer = new Buffer(new Comparator());
        foreach ($collector->changes() as [$subject, $old, $new, $operation]) {
            $buffer->observe($subject, 'native.specific_price', 'prices', $old, $new, $operation);
        }
        foreach (array_merge($before, $after) as $entry) {
            self::assertTrue($collector->handles($entry['subject']));
        }
        self::assertFalse($collector->handles(new Subject('specific_price', 99999, 1, 'product', 3402)));
        return $buffer->events();
    }

    public function testIdenticalRegenerationAndDecimalFormattingProduceNoEvent(): void
    {
        self::assertSame([], $this->compare([$this->entry(1)], [$this->entry(2, ['reduction' => '0.300000', 'price' => '-1.000000'])]));
    }

    public function testChangedDiscountAndDatesProduceOneUpdateWithRealBeforeValues(): void
    {
        $events = $this->compare([$this->entry(1)], [$this->entry(2, ['reduction' => '0.4', 'to' => '2026-08-01 00:00:00'])]);
        self::assertCount(1, $events);
        self::assertSame('update', $events[0]['action']);
        self::assertSame('0.3', $events[0]['changes']['reduction']['before']);
        self::assertSame('0.4', $events[0]['changes']['reduction']['after']);
        self::assertSame(['reduction', 'to'], array_keys($events[0]['changes']));
        self::assertSame(2, $events[0]['subject']->id);
    }

    public function testRealCreationAndSqlDeletionRemainVisible(): void
    {
        self::assertSame('create', $this->compare([], [$this->entry(2)])[0]['action']);
        self::assertSame('delete', $this->compare([$this->entry(1)], [])[0]['action']);
    }

    public function testDifferentRulesCombinationsAndShopsDoNotCancelEachOther(): void
    {
        foreach ([['id_specific_price_rule' => 63], ['id_product_attribute' => 42], ['from_quantity' => 2], ['id_currency' => 2], ['id_group' => 3]] as $scope) {
            self::assertCount(2, $this->compare([$this->entry(1)], [$this->entry(2, $scope)]));
        }
        self::assertCount(2, $this->compare([$this->entry(1)], [$this->entry(2, [], 2)]));
    }

    public function testDuplicateScopesMatchIdenticalRowsBeforePairingChanges(): void
    {
        $before = [$this->entry(1), $this->entry(2, ['reduction' => '0.5'])];
        $after = [$this->entry(3, ['reduction' => '0.5']), $this->entry(4, ['reduction' => '0.4'])];
        $events = $this->compare($before, $after);
        self::assertCount(1, $events);
        self::assertSame('0.3', $events[0]['changes']['reduction']['before']);
        self::assertSame('0.4', $events[0]['changes']['reduction']['after']);
        self::assertSame([], $this->compare($before, [$this->entry(3, ['reduction' => '0.5']), $this->entry(4)]));
        self::assertCount(1, $this->compare($before, [$this->entry(3)]));
    }

    public function testFailedFinalReadDoesNotSuppressNativeEvents(): void
    {
        $reads = 0;
        $entry = $this->entry(1);
        $collector = new GeneratedSpecificPrices(static function () use (&$reads, $entry): array {
            if ($reads++ > 0) {
                throw new \RuntimeException('Read failed');
            }
            return [$entry];
        });
        $collector->capture(3402);
        try {
            $collector->changes();
            self::fail('Read failure should be reported.');
        } catch (\RuntimeException $error) {
            self::assertFalse($collector->handles($entry['subject']));
        }
    }

    public function testResetAllowsANewBaselineAndKeepsLocalOperation(): void
    {
        $rows = [$this->entry(1)];
        $collector = new GeneratedSpecificPrices(static function () use (&$rows): array { return $rows; });
        $collector->capture(3402, 'first');
        $rows = [$this->entry(2, ['price' => '12'])];
        self::assertSame('first', $collector->changes()[0][3]);
        $collector->reset();
        self::assertFalse($collector->handles($rows[0]['subject']));
        $collector->capture(3402, 'second');
        self::assertSame([], $collector->changes());
    }
}
