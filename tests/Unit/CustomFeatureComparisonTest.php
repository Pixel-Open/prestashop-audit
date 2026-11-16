<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Api\Field;
use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Collector\CustomFeatureComparison;
use Pixel\Module\Audit\Core\Comparator;

final class CustomFeatureComparisonTest extends TestCase
{
    private function snapshot(array $values, string $price = '12'): Snapshot
    {
        $fields = ['supplier.1.0.price' => new Field($price, 'Supplier price', 'decimal')];
        $members = [];
        foreach ($values as [$feature, $id, $languages]) {
            $members[] = $feature . ':' . $id;
            foreach ($languages as $lang => $text) {
                $fields['feature.' . $feature . '.' . $id . '.lang_' . $lang] = new Field($text, 'Custom value');
            }
        }
        $fields['features'] = new Field($members, 'Features', 'set');
        return new Snapshot(true, $fields);
    }

    private function diff(Snapshot $before, Snapshot $after, array $custom): array
    {
        [$old, $new] = CustomFeatureComparison::snapshots($before, $after, array_fill_keys($custom, true));
        return (new Comparator())->diff($old, $new);
    }

    public function testEqualMultilingualValuesWithNewIdsAreSilentAndSnapshotsUnchanged(): void
    {
        $before = $this->snapshot([[3, 10, [1 => 'Cotton', 2 => 'Coton']]]);
        $after = $this->snapshot([[3, 20, [2 => 'Coton', 1 => 'Cotton']]]);
        self::assertSame([], $this->diff($before, $after, [10, 20]));
        self::assertArrayHasKey('feature.3.10.lang_1', $before->fields);
        self::assertArrayHasKey('feature.3.20.lang_1', $after->fields);
    }

    public function testChangedTranslationMissingLanguageOrChangedFeatureRemainVisible(): void
    {
        $before = $this->snapshot([[3, 10, [1 => 'Cotton', 2 => 'Coton']]]);
        foreach ([[3, 20, [1 => 'Cotton', 2 => 'Lin']], [3, 20, [1 => 'Cotton']], [4, 20, [1 => 'Cotton', 2 => 'Coton']]] as $value) {
            self::assertNotEmpty($this->diff($before, $this->snapshot([$value]), [10, 20]));
        }
    }

    public function testPredefinedChoicesWithEqualLabelsAreNotCancelled(): void
    {
        $before = $this->snapshot([[3, 10, [1 => 'Cotton']]]);
        $after = $this->snapshot([[3, 20, [1 => 'Cotton']]]);
        self::assertNotEmpty($this->diff($before, $after, []));
        self::assertNotEmpty($this->diff($before, $after, [20]));
    }

    public function testDuplicateValuesPreserveRealAdditionsAndRemovals(): void
    {
        $one = $this->snapshot([[3, 10, [1 => 'Cotton']]]);
        $two = $this->snapshot([[3, 20, [1 => 'Cotton']], [3, 21, [1 => 'Cotton']]]);
        self::assertNotEmpty($this->diff($one, $two, [10, 20, 21]));
        self::assertNotEmpty($this->diff($two, $one, [10, 20, 21]));
        self::assertSame([], $this->diff($two, $this->snapshot([[3, 30, [1 => 'Cotton']], [3, 31, [1 => 'Cotton']]]), [20, 21, 30, 31]));
    }

    public function testUnrelatedSupplierPriceAndSameIdEditsArePreserved(): void
    {
        $before = $this->snapshot([[3, 10, [1 => 'Cotton']]]);
        $after = $this->snapshot([[3, 20, [1 => 'Cotton']]], '15');
        self::assertSame(['supplier.1.0.price'], array_keys($this->diff($before, $after, [10, 20])));
        self::assertSame(['feature.3.10.lang_1'], array_keys($this->diff($before, $this->snapshot([[3, 10, [1 => 'Linen']]]), [10])));
    }

    public function testExistingValueCannotCancelARealRemovedValue(): void
    {
        $before = $this->snapshot([[3, 10, [1 => 'Cotton']], [3, 20, [1 => 'Cotton']]]);
        $after = $this->snapshot([[3, 20, [1 => 'Cotton']]]);
        self::assertNotEmpty($this->diff($before, $after, [10, 20]));
    }
}
