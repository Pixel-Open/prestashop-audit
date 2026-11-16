<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Presentation\Presenter;
use Pixel\Module\Audit\Presentation\VisualDiff;

final class VisualDiffTest extends TestCase
{
    private function text(array $fragments, ?bool $changed = null): string
    {
        return implode('', array_column(array_filter($fragments, static fn (array $part): bool => $changed === null || $part['changed'] === $changed), 'text'));
    }

    public function testOnlyChangedWordsAreHighlighted(): void
    {
        $diff = VisualDiff::compare('White paint, 5 L', 'White paint, 10 L', 'text');
        self::assertSame('5', $this->text($diff['before'], true));
        self::assertSame('10', $this->text($diff['after'], true));
        self::assertSame('White paint,  L', $this->text($diff['before'], false));
        self::assertFalse($diff['limited']);
    }

    public function testLineContextAndWhitespaceArePreserved(): void
    {
        $before = "Intro\r\n  Old label\n\nEnd\r";
        $after = "Intro\r\n  New label\n\nEnd\r";
        $diff = VisualDiff::compare($before, $after, 'text');
        self::assertSame('Old', $this->text($diff['before'], true));
        self::assertSame('New', $this->text($diff['after'], true));
        self::assertSame($before, $this->text($diff['before']));
        self::assertSame($after, $this->text($diff['after']));
    }

    public function testListsMatchValuesAndKeepOrderDuplicatesAndTypes(): void
    {
        foreach (['json', 'set'] as $type) {
            $before = ['A', 'B', 'B', 1, ['label' => 'Nested']];
            $after = ['B', 'B', '1', ['label' => 'Nested'], 'C'];
            $diff = VisualDiff::compare($before, $after, $type);
            self::assertStringContainsString('"A"', $this->text($diff['before'], true));
            self::assertStringNotContainsString('"B"', $this->text($diff['before'], true));
            self::assertStringNotContainsString('Nested', $this->text($diff['after'], true));
            self::assertStringContainsString('"1"', $this->text($diff['after'], true));
            self::assertStringContainsString('"C"', $this->text($diff['after'], true));
            self::assertSame(Presenter::value($before, $type), $this->text($diff['before']));
            self::assertSame(Presenter::value($after, $type), $this->text($diff['after']));
        }
    }

    public function testNullEmptyTextZeroFalseAndEmptyListsRemainDistinct(): void
    {
        $diff = VisualDiff::compare(null, '', 'text');
        self::assertSame('null', $diff['before'][0]['kind']);
        self::assertSame('empty', $diff['after'][0]['kind']);
        self::assertTrue($diff['before'][0]['changed']);
        $diff = VisualDiff::compare('', '0', 'text');
        self::assertSame('0', $this->text($diff['after'], true));
        self::assertSame('text', $diff['after'][0]['kind']);
        $diff = VisualDiff::compare(false, true, 'boolean');
        self::assertSame('No', $this->text($diff['before'], true));
        self::assertSame('Yes', $this->text($diff['after'], true));
        foreach (['text', 'json'] as $type) {
            $boolean = VisualDiff::compare(false, '', $type);
            self::assertSame('No', $this->text($boolean['before']));
            self::assertSame('empty', $boolean['after'][0]['kind']);
        }
        $diff = VisualDiff::compare([], ['A'], 'json');
        self::assertSame('[]', $this->text($diff['before']));
        self::assertSame('', $this->text($diff['before'], true));
        self::assertStringContainsString('"A"', $this->text($diff['after'], true));
    }

    public function testScalarValuesAreComparedAsWholeValues(): void
    {
        foreach ([['10.50', '10.75', 'decimal'], [10, 11, 'integer'], ['2026-11-11', '2026-11-12', 'text']] as [$before, $after, $type]) {
            $diff = VisualDiff::compare($before, $after, $type);
            self::assertSame((string) $before, $this->text($diff['before'], true));
            self::assertSame((string) $after, $this->text($diff['after'], true));
        }
    }

    public function testHtmlAndUnicodeStayPlainTextWithoutNormalisation(): void
    {
        $before = '<img src=x onerror="alert(1)"><p>Café blanc 👩🏽</p>';
        $after = '<img src=x onerror="alert(1)"><p>Café rouge 👩🏻</p>';
        $diff = VisualDiff::compare($before, $after, 'text');
        self::assertSame($before, $this->text($diff['before']));
        self::assertSame($after, $this->text($diff['after']));
        self::assertSame('blanc👩🏽', $this->text($diff['before'], true));
        self::assertSame('rouge👩🏻', $this->text($diff['after'], true));
    }

    public function testLargeValuesAndUnrelatedTokenSetsFallBackWithoutTruncation(): void
    {
        foreach ([[str_repeat('A', 40000), str_repeat('B', 40000)],
            [implode(' ', range(1, 300)), implode(' ', range(401, 700))]] as [$before, $after]) {
            $diff = VisualDiff::compare($before, $after, 'text');
            self::assertTrue($diff['limited']);
            self::assertSame($before, $this->text($diff['before']));
            self::assertSame($after, $this->text($diff['after']));
        }
    }

    public function testLongUnchangedEdgesDoNotConsumeTheDetailedDiffBudget(): void
    {
        $prefix = str_repeat('context ', 1500);
        $diff = VisualDiff::compare($prefix . 'old tail', $prefix . 'new tail', 'text');
        self::assertFalse($diff['limited']);
        self::assertSame('old', $this->text($diff['before'], true));
        self::assertSame('new', $this->text($diff['after'], true));
    }

    public function testUnchangedValuesAndTypedSameDisplayNeverInventMissingValues(): void
    {
        foreach ([null, '', false, 0, [], 'same'] as $value) {
            $diff = VisualDiff::compare($value, $value, 'text');
            self::assertSame('', $this->text($diff['before'], true));
            self::assertSame('', $this->text($diff['after'], true));
        }
        $diff = VisualDiff::compare(null, '∅', 'text');
        self::assertSame('null', $diff['before'][0]['kind']);
        self::assertSame('text', $diff['after'][0]['kind']);
        self::assertTrue($diff['after'][0]['changed']);
    }

    public function testFragmentsReconstructEveryShortInputAcrossInsertionsAndDeletions(): void
    {
        $values = ['', 'a', 'b', 'a b', 'b a', 'a a', "a\nb", "b\na\n", '<a>', 'A  B'];
        foreach ($values as $before) {
            foreach ($values as $after) {
                $diff = VisualDiff::compare($before, $after, 'text');
                self::assertSame($before, $this->text($diff['before']));
                self::assertSame($after, $this->text($diff['after']));
            }
        }
    }
}
