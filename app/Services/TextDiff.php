<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Line diff with word-level highlighting, without external dependency.
 *
 * Common leading and trailing lines are removed first; the remaining block is compared with a
 * longest-common-subsequence table whose directions are kept in a byte string (n*m bytes), so
 * memory stays bounded. Above MAX_CELLS the diff is not computed and the result is marked
 * "truncated" with simple counts, which the interface shows as a summary.
 */
final class TextDiff
{
    public const MAX_CELLS = 9000000;
    private const CONTEXT = 3;

    /**
     * @return array{rows: list<array>, added: int, removed: int, truncated: bool}
     * Each row: ['type' => 'same'|'add'|'del'|'gap', 'old' => ?int, 'new' => ?int, 'text' => string, 'parts' => ?list<array{0:bool,1:string}>]
     */
    public static function compare(string $old, string $new, bool $ignoreFormatting = false, bool $full = false): array
    {
        $a = self::lines($old);$b = self::lines($new);
        if ($ignoreFormatting) { $a = array_map([self::class, 'normalizeLine'], $a);$b = array_map([self::class, 'normalizeLine'], $b); }
        $ops = self::operations($a, $b);
        if ($ops === null) {
            [$added, $removed] = self::countChanges($a, $b);
            return ['rows' => [], 'added' => $added, 'removed' => $removed, 'truncated' => true];
        }
        $rows = [];$added = 0;$removed = 0;$i = 0;$j = 0;
        foreach ($ops as $op) {
            if ($op === '=') $rows[] = ['type' => 'same', 'old' => ++$i, 'new' => ++$j, 'text' => $a[$i - 1], 'parts' => null];
            elseif ($op === '-') { $rows[] = ['type' => 'del', 'old' => ++$i, 'new' => null, 'text' => $a[$i - 1], 'parts' => null];$removed++; }
            else { $rows[] = ['type' => 'add', 'old' => null, 'new' => ++$j, 'text' => $b[$j - 1], 'parts' => null];$added++; }
        }
        self::highlightWords($rows);
        return ['rows' => $full ? $rows : self::withContext($rows), 'added' => $added, 'removed' => $removed, 'truncated' => false];
    }

    /** [added, removed] line counts, exact when the diff is computable, approximate otherwise. */
    public static function stats(string $old, string $new): array
    {
        $a = self::lines($old);$b = self::lines($new);
        $ops = self::operations($a, $b);
        if ($ops === null) return self::countChanges($a, $b);
        $counts = count_chars(implode('', $ops), 1);
        return [$counts[ord('+')] ?? 0, $counts[ord('-')] ?? 0];
    }

    /** Neutralizes rewrites made by the editor: trailing spaces, bullet markers, table padding, rules. */
    public static function normalizeLine(string $line): string
    {
        $line = rtrim($line);
        $line = preg_replace('/^(\s*)[*+](\s+)/', '$1-$2', $line) ?? $line;
        if (preg_match('/^\s*(\*\s*){3,}$|^\s*(_\s*){3,}$|^\s*(-\s*){3,}$/', $line)) return '---';
        if (preg_match('/^\s*\|.*\|\s*$/', $line)) {
            $cells = array_map('trim', explode('|', trim(trim($line), '|')));
            foreach ($cells as &$cell) {
                if (preg_match('/^(:?)-+(:?)$/', $cell, $m)) $cell = $m[1] . '---' . $m[2];
            }
            return '| ' . implode(' | ', $cells) . ' |';
        }
        return $line;
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($text === '') return [];
        if (str_ends_with($text, "\n")) $text = substr($text, 0, -1);
        return explode("\n", $text);
    }

    /** List of '=', '-', '+' operations, or null when the changed block is too large. */
    private static function operations(array $a, array $b): ?array
    {
        $n = count($a);$m = count($b);$start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) $start++;
        $endA = $n;$endB = $m;
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) { $endA--;$endB--; }
        $midA = array_slice($a, $start, $endA - $start);$midB = array_slice($b, $start, $endB - $start);
        $middle = self::lcs($midA, $midB, self::MAX_CELLS);
        if ($middle === null) return null;
        return array_merge(array_fill(0, $start, '='), $middle, array_fill(0, $n - $endA, '='));
    }

    /** LCS-based operations between two token lists, or null above $limit cells. */
    private static function lcs(array $a, array $b, int $limit): ?array
    {
        $n = count($a);$m = count($b);
        if ($n === 0) return array_fill(0, $m, '+');
        if ($m === 0) return array_fill(0, $n, '-');
        if ($n * $m > $limit) return null;
        // Map tokens to integers for fast comparison.
        $ids = [];$x = [];$y = [];
        foreach ($a as $t) $x[] = $ids[$t] ??= count($ids);
        foreach ($b as $t) $y[] = $ids[$t] ??= count($ids);
        // Suffix LCS lengths, row by row; directions stored as bytes: 'd' diagonal, 'u' skip a, 'l' skip b.
        $dir = str_repeat('l', $n * $m);
        $next = array_fill(0, $m + 1, 0);
        for ($i = $n - 1; $i >= 0; $i--) {
            $row = array_fill(0, $m + 1, 0);
            $xi = $x[$i];$base = $i * $m;
            for ($j = $m - 1; $j >= 0; $j--) {
                if ($xi === $y[$j]) { $row[$j] = $next[$j + 1] + 1;$dir[$base + $j] = 'd'; }
                elseif ($next[$j] >= $row[$j + 1]) { $row[$j] = $next[$j];$dir[$base + $j] = 'u'; }
                else $row[$j] = $row[$j + 1];
            }
            $next = $row;
        }
        $ops = [];$i = 0;$j = 0;
        while ($i < $n && $j < $m) {
            $d = $dir[$i * $m + $j];
            if ($d === 'd') { $ops[] = '=';$i++;$j++; }
            elseif ($d === 'u') { $ops[] = '-';$i++; }
            else { $ops[] = '+';$j++; }
        }
        while ($i++ < $n) $ops[] = '-';
        while ($j++ < $m) $ops[] = '+';
        return $ops;
    }

    private static function countChanges(array $a, array $b): array
    {
        $countA = array_count_values($a);$countB = array_count_values($b);$added = 0;$removed = 0;
        foreach ($countB as $line => $count) $added += max(0, $count - ($countA[$line] ?? 0));
        foreach ($countA as $line => $count) $removed += max(0, $count - ($countB[$line] ?? 0));
        return [$added, $removed];
    }

    /** Pairs consecutive deleted/added blocks line by line and marks the changed words. */
    private static function highlightWords(array &$rows): void
    {
        $count = count($rows);
        for ($k = 0; $k < $count;) {
            if ($rows[$k]['type'] !== 'del') { $k++;continue; }
            $dels = [];while ($k < $count && $rows[$k]['type'] === 'del') $dels[] = $k++;
            $adds = [];while ($k < $count && $rows[$k]['type'] === 'add') $adds[] = $k++;
            if (!$adds || count($dels) > 50 || count($adds) > 50) continue;
            foreach (array_map(null, $dels, $adds) as [$d, $a]) {
                if ($d === null || $a === null) continue;
                $old = self::tokens($rows[$d]['text']);$new = self::tokens($rows[$a]['text']);
                $ops = self::lcs($old, $new, 40000);
                if ($ops === null) continue;
                $delParts = [];$addParts = [];$i = 0;$j = 0;
                foreach ($ops as $op) {
                    if ($op === '=') { self::part($delParts, false, $old[$i++]);self::part($addParts, false, $new[$j++]); }
                    elseif ($op === '-') self::part($delParts, true, $old[$i++]);
                    else self::part($addParts, true, $new[$j++]);
                }
                $rows[$d]['parts'] = $delParts;$rows[$a]['parts'] = $addParts;
            }
        }
    }

    private static function part(array &$parts, bool $changed, string $text): void
    {
        $last = count($parts) - 1;
        if ($last >= 0 && $parts[$last][0] === $changed) $parts[$last][1] .= $text;
        else $parts[] = [$changed, $text];
    }

    /** @return list<string> words, spaces and punctuation as separate tokens */
    private static function tokens(string $line): array
    {
        return preg_split('/(\s+|[^\p{L}\p{N}]+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Keeps CONTEXT unchanged lines around changes and replaces longer runs by a 'gap' row. */
    private static function withContext(array $rows): array
    {
        $keep = [];$count = count($rows);
        foreach ($rows as $index => $row) {
            if ($row['type'] === 'same') continue;
            for ($k = max(0, $index - self::CONTEXT); $k <= min($count - 1, $index + self::CONTEXT); $k++) $keep[$k] = true;
        }
        $result = [];$skipped = 0;
        foreach ($rows as $index => $row) {
            if (isset($keep[$index])) {
                if ($skipped) { $result[] = ['type' => 'gap', 'old' => null, 'new' => null, 'text' => $skipped . ' ligne' . ($skipped > 1 ? 's' : '') . ' identique' . ($skipped > 1 ? 's' : ''), 'parts' => null];$skipped = 0; }
                $result[] = $row;
            } else $skipped++;
        }
        if ($skipped && $result) $result[] = ['type' => 'gap', 'old' => null, 'new' => null, 'text' => $skipped . ' ligne' . ($skipped > 1 ? 's' : '') . ' identique' . ($skipped > 1 ? 's' : ''), 'parts' => null];
        return $result;
    }
}
