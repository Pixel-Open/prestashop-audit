<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Presentation;

/** Presentation only: fragments are plain text and must be escaped by the template. */
final class VisualDiff
{
    private const MAX_BYTES = 65536;
    private const MAX_CELLS = 40000;
    private const MAX_TOKENS = 1200;

    public static function compare(mixed $before, mixed $after, string $type): array
    {
        $left = Presenter::value($before, is_bool($before) ? 'boolean' : $type);
        $right = Presenter::value($after, is_bool($after) ? 'boolean' : $type);
        $out = ['before' => [], 'after' => [], 'limited' => false];
        if ($before === $after) {
            return self::whole($before, $after, $left, $right, false, false);
        }
        if (strlen($left) + strlen($right) > self::MAX_BYTES) {
            return self::whole($before, $after, $left, $right, true, true);
        }
        if ($before === null || $after === null || $before === '' || $after === '' || $left === $right
            || !in_array($type, ['text', 'html', 'json', 'set'], true)
            || (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/D', $left)
                && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/D', $right))) {
            return self::whole($before, $after, $left, $right, true, false);
        }
        if (is_array($before) && is_array($after) && array_is_list($before) && array_is_list($after)) {
            return self::lists($before, $after, $left, $right);
        }
        if (strpbrk($left . $right, "\r\n") === false) {
            self::words($left, $right, $out);
            return $out;
        }
        $a = self::tokens($left, '/[^\r\n]*(?:\r\n|\r|\n)|[^\r\n]+$/u');
        $b = self::tokens($right, '/[^\r\n]*(?:\r\n|\r|\n)|[^\r\n]+$/u');
        $operations = $a !== null && $b !== null ? self::align($a, $b) : null;
        if ($operations === null) {
            return self::whole($before, $after, $left, $right, true, true);
        }
        $removed = $added = '';
        foreach ($operations as $operation) {
            if ($operation['kind'] === 'equal') {
                self::words($removed, $added, $out);
                $removed = $added = '';
                self::append($out['before'], $a[$operation['before']], false);
                self::append($out['after'], $b[$operation['after']], false);
            } elseif ($operation['kind'] === 'remove') {
                $removed .= $a[$operation['before']];
            } else {
                $added .= $b[$operation['after']];
            }
        }
        self::words($removed, $added, $out);
        return $out;
    }

    private static function whole(mixed $before, mixed $after, string $left, string $right, bool $changed, bool $limited): array
    {
        return ['before' => [['text' => $left, 'changed' => $changed, 'kind' => self::kind($before)]],
            'after' => [['text' => $right, 'changed' => $changed, 'kind' => self::kind($after)]], 'limited' => $limited];
    }

    private static function kind(mixed $value): string
    {
        return $value === null ? 'null' : ($value === '' ? 'empty' : 'text');
    }

    private static function tokens(string $text, string $pattern): ?array
    {
        return preg_match_all($pattern, $text, $matches) === false ? null : $matches[0];
    }

    private static function words(string $left, string $right, array &$out): void
    {
        if ($left === '' && $right === '') {
            return;
        }
        $pattern = '/[\p{L}\p{N}\p{M}_]+|[^\S\r\n]+|\r\n|\X/u';
        $a = self::tokens($left, $pattern);
        $b = self::tokens($right, $pattern);
        $operations = $a !== null && $b !== null ? self::align($a, $b) : null;
        if ($operations === null) {
            self::append($out['before'], $left, true);
            self::append($out['after'], $right, true);
            $out['limited'] = true;
            return;
        }
        foreach ($operations as $operation) {
            if ($operation['before'] !== null) {
                self::append($out['before'], $a[$operation['before']], $operation['kind'] !== 'equal');
            }
            if ($operation['after'] !== null) {
                self::append($out['after'], $b[$operation['after']], $operation['kind'] !== 'equal');
            }
        }
    }

    private static function lists(array $before, array $after, string $left, string $right): array
    {
        $encode = static fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $a = array_map($encode, $before);
        $b = array_map($encode, $after);
        $operations = self::align($a, $b);
        if ($operations === null) {
            return self::whole($before, $after, $left, $right, true, true);
        }
        $common = ['before' => [], 'after' => []];
        foreach ($operations as $operation) {
            if ($operation['kind'] === 'equal') {
                $common['before'][$operation['before']] = true;
                $common['after'][$operation['after']] = true;
            }
        }
        $out = ['before' => [], 'after' => [], 'limited' => false];
        foreach (['before' => $a, 'after' => $b] as $side => $items) {
            self::append($out[$side], $items ? "[\n" : '[]', false);
            foreach ($items as $index => $item) {
                $text = '    ' . str_replace("\n", "\n    ", $item) . ($index < count($items) - 1 ? ',' : '') . "\n";
                self::append($out[$side], $text, !isset($common[$side][$index]));
            }
            if ($items) {
                self::append($out[$side], ']', false);
            }
        }
        return $out;
    }

    private static function append(array &$fragments, string $text, bool $changed): void
    {
        if ($text === '') {
            return;
        }
        $last = count($fragments) - 1;
        if ($last >= 0 && $fragments[$last]['changed'] === $changed) {
            $fragments[$last]['text'] .= $text;
        } else {
            $fragments[] = ['text' => $text, 'changed' => $changed, 'kind' => 'text'];
        }
    }

    /** Trim common edges before applying a bounded longest common subsequence. */
    private static function align(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);
        $start = 0;
        while ($start < min($n, $m) && $a[$start] === $b[$start]) {
            ++$start;
        }
        $endA = $n;
        $endB = $m;
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            --$endA;
            --$endB;
        }
        $rows = $endA - $start;
        $columns = $endB - $start;
        if ($rows * $columns > self::MAX_CELLS || $rows + $columns > self::MAX_TOKENS) {
            return null;
        }
        $matrix = [];
        $matrix[$endA] = array_fill($start, $columns + 1, 0);
        for ($i = $endA - 1; $i >= $start; --$i) {
            $matrix[$i] = [$endB => 0];
            for ($j = $endB - 1; $j >= $start; --$j) {
                $matrix[$i][$j] = $a[$i] === $b[$j] ? $matrix[$i + 1][$j + 1] + 1 : max($matrix[$i + 1][$j], $matrix[$i][$j + 1]);
            }
        }
        $operations = [];
        for ($i = 0; $i < $start; ++$i) {
            $operations[] = ['kind' => 'equal', 'before' => $i, 'after' => $i];
        }
        $i = $j = $start;
        while ($i < $endA || $j < $endB) {
            if ($i < $endA && $j < $endB && $a[$i] === $b[$j]) {
                $operations[] = ['kind' => 'equal', 'before' => $i++, 'after' => $j++];
            } elseif ($i < $endA && ($j === $endB || $matrix[$i + 1][$j] >= $matrix[$i][$j + 1])) {
                $operations[] = ['kind' => 'remove', 'before' => $i++, 'after' => null];
            } else {
                $operations[] = ['kind' => 'add', 'before' => null, 'after' => $j++];
            }
        }
        while ($i < $n && $j < $m) {
            $operations[] = ['kind' => 'equal', 'before' => $i++, 'after' => $j++];
        }
        return $operations;
    }
}
