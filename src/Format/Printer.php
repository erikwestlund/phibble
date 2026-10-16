<?php

declare(strict_types=1);

namespace Phibble\Format;

use Phibble\Contracts\Table;

/**
 * Renders a table the way tibble prints one (pillar 1.11, plain text):
 * a header comment, columns squeezed to fit the width, and footers for
 * hidden rows, hidden columns and abbreviated names.
 *
 * Multi-tier output (tibble's behaviour when the print width exceeds the
 * console width) is not reproduced: the whole width is one tier.
 */
final class Printer
{
    private const int PRINT_MAX = 20;

    private const int PRINT_MIN = 10;

    private const int MAX_EXTRA_COLS = 100;

    private const int MAX_FOOTER_LINES = 7;

    private const int MIN_TITLE_CHARS = 20;

    private const string NBSP = "\u{00A0}";

    /**
     * @param int|null $n Rows to show; by default all when there are at most
     *     20, otherwise 10.
     */
    public static function render(Table $table, ?int $n = null, int $width = 80): string
    {
        $frame = $table->collect();
        $rows = $frame->nrow();
        $columns = $frame->columns();
        $n = $n !== null && $n >= 0 ? $n : ($rows > self::PRINT_MAX ? self::PRINT_MIN : $rows);
        $shown = $frame->head($n);
        $rowsMissing = $rows > $n ? $rows - $n : 0;

        $lines = self::header($frame, $width);

        $body = [];
        $extra = $columns;
        $abbreviated = [];
        if ($shown->nrow() > 0 && $columns !== []) {
            [$body, $extra, $abbreviated] = self::body($shown, $width);
        }
        array_push($lines, ...$body);

        $footers = [];
        if ($columns !== [] && $rowsMissing > 0) {
            $footers[] = [self::bigMark($rowsMissing), 'more', $rowsMissing === 1 ? 'row' : 'rows'];
        }
        if ($abbreviated !== []) {
            $words = ['abbreviated', count($abbreviated) === 1 ? 'name:' : 'names:'];
            foreach ($abbreviated as $i => $title) {
                $words[] = Pillar::SUPERDIGITS[min($i, 9)]."\u{200B}".$title.($i < count($abbreviated) - 1 ? ',' : '');
            }
            $footers[] = $words;
        }
        if ($extra !== []) {
            $footers[] = self::extraColumnsFooter($frame, $extra, $rows > 0 && $shown->nrow() > 0);
        }

        $budget = self::MAX_FOOTER_LINES;
        foreach ($footers as $words) {
            if ($budget <= 0) {
                break;
            }
            $wrapped = self::wrapFooter($words, $width, $budget);
            array_push($lines, ...$wrapped);
            $budget -= count($wrapped);
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private static function header(Table $frame, int $width): array
    {
        $entries = ['A tibble' => self::bigMark($frame->nrow()).' × '.self::bigMark($frame->ncol())];
        if (method_exists($frame, 'isRowwise') && $frame->isRowwise()) {
            $entries['Rowwise'] = implode(', ', $frame->groups());
        } elseif ($frame->groups() !== []) {
            $groups = iterator_count((fn () => yield from $frame->eachGroup())());
            $entries['Groups'] = implode(', ', $frame->groups()).' ['.self::bigMark($groups).']';
        }

        $labelWidth = max(array_map(fn (string $k) => Pillar::extent($k.':'), array_keys($entries)));
        $lines = [];
        foreach ($entries as $label => $value) {
            $label = $label.':';
            $text = $label.str_repeat(self::NBSP, $labelWidth - Pillar::extent($label)).' '.$value;
            foreach (Pillar::wrap($text, $width - 2, 2) as $line) {
                $lines[] = '# '.str_replace(self::NBSP, ' ', rtrim($line, ' '));
            }
        }

        return $lines;
    }

    /**
     * Lay out the visible columns, as pillar's ctl_colonnade() on one tier.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private static function body(Table $shown, int $width): array
    {
        $rows = $shown->nrow();
        $rowidWidth = (int) floor(log10(max($rows, 1))) + 1;
        $tier = $width - $rowidWidth - 1;

        // Build pillars until the minimum widths no longer fit.
        $pillars = [];
        $remaining = $tier;
        $columns = $shown->columns();
        foreach ($columns as $name) {
            $pillar = self::pillar($shown, $name);
            if ($remaining < $pillar['min']) {
                break;
            }
            $remaining = max($remaining - $pillar['min'] - 1, 0);
            $pillars[] = $pillar;
        }
        $extra = array_slice($columns, count($pillars));
        if ($pillars === []) {
            return [[], $extra, []];
        }

        // Each pillar may grow until the rest still fit at their minimum.
        $offsets = [];
        $after = $tier;
        for ($i = count($pillars) - 1; $i >= 0; $i--) {
            $offsets[$i] = $after;
            $after -= $pillars[$i]['min'] + 1;
        }

        $cells = [self::rowidColumn($rows, $rowidWidth)];
        $abbreviated = [];
        $x = 0;
        foreach ($pillars as $i => $pillar) {
            $available = $offsets[$i] - $x;
            if ($x > 0) {
                $available = max($available - 1, 0);
            }
            [$column, $used, $truncatedTitle] = self::formatPillar($pillar, $available, count($abbreviated) + 1);
            if ($truncatedTitle) {
                $abbreviated[] = $pillar['title'];
            }
            $cells[] = $column;
            $x = ($x > 0 ? $x + 1 : $x) + $used;
        }

        $lines = [];
        foreach (array_keys($cells[0]) as $line) {
            $lines[] = implode(' ', array_map(fn (array $column) => $column[$line], $cells));
        }

        return [$lines, $extra, $abbreviated];
    }

    /** @return array<string, mixed> */
    private static function pillar(Table $shown, string $name): array
    {
        $title = Pillar::tickIfNeeded($name);
        $titleWidth = Pillar::extent($title);
        $titleMin = $titleWidth <= self::MIN_TITLE_CHARS ? $titleWidth : min($titleWidth, self::MIN_TITLE_CHARS + 2);
        $type = Pillar::typeAbbr($shown, $name);
        $typeWidth = max(Pillar::extent("<{$type}>"), 5);
        $shaft = Pillar::shaft($shown->types()[$name], $shown->column($name));

        return [
            'title' => $title,
            'titleWidth' => $titleWidth,
            'type' => $type,
            'typeWidth' => $typeWidth,
            'shaft' => $shaft,
            'min' => max($titleMin, 5, $shaft->minWidth()),
        ];
    }

    /**
     * pillar_format_parts_2(): title, type and cells at the given width.
     *
     * @param array<string, mixed> $pillar
     * @return array{0: list<string>, 1: int, 2: bool}
     */
    private static function formatPillar(array $pillar, int $width, int $footnote): array
    {
        $titleWidth = min($width, $pillar['titleWidth']);
        $title = $pillar['title'];
        $truncated = false;
        if (Pillar::extent($title) > $titleWidth) {
            $unticked = str_replace('`', '', $title);
            $title = mb_substr($unticked, 0, max($titleWidth - 2, 0), 'UTF-8').Pillar::ELLIPSIS.Pillar::SUPERDIGITS[min($footnote, 10) - 1];
            $truncated = true;
        }

        $typeWidth = min($width, $pillar['typeWidth']);
        $type = $pillar['type'];
        if ($typeWidth - 2 < Pillar::extent($type)) {
            $type = mb_substr($type, 0, max($typeWidth - 2, 0), 'UTF-8');
        }
        $type = "<{$type}>";

        /** @var Shaft $shaft */
        $shaft = $pillar['shaft'];
        $data = $shaft->format(min($width, $shaft->width()));

        $flat = [$title, $type, ...$data];
        $extent = max(array_map(Pillar::extent(...), $flat));
        if ($extent > $width) {
            $flat = array_map(fn (string $s) => Pillar::truncate($s, $width), $flat);
            $extent = $width;
        }

        return [array_map(fn (string $s) => Pillar::align($s, $extent, $shaft->align()), $flat), $extent, $truncated];
    }

    /** @return list<string> */
    private static function rowidColumn(int $rows, int $width): array
    {
        $column = [str_repeat(' ', $width), str_repeat(' ', $width)];
        for ($i = 1; $i <= $rows; $i++) {
            $column[] = str_pad((string) $i, $width, ' ', STR_PAD_LEFT);
        }

        return $column;
    }

    /**
     * @param list<string> $extra
     * @return list<string>
     */
    private static function extraColumnsFooter(Table $frame, array $extra, bool $more): array
    {
        $total = count($extra);
        $vars = [];
        foreach (array_slice($extra, 0, self::MAX_EXTRA_COLS) as $name) {
            $vars[] = Pillar::tickIfNeeded($name).' <'.Pillar::typeAbbr($frame, $name).'>';
        }
        if ($total > self::MAX_EXTRA_COLS) {
            $vars[] = Pillar::ELLIPSIS;
        }
        foreach (array_keys($vars) as $i) {
            if ($i < count($vars) - 1) {
                $vars[$i] .= ',';
            }
        }

        return [
            self::bigMark($total),
            ...($more ? ['more'] : []),
            $total === 1 ? 'variable:' : 'variables:',
            ...$vars,
        ];
    }

    /**
     * pillar's wrap_footer_bullet_one(): words packed into lines that start
     * with "# ℹ" (then "#  "), ending in an ellipsis when they run out of
     * lines.
     *
     * @param list<string> $words
     * @return list<string>
     */
    private static function wrapFooter(array $words, int $width, int $lines): array
    {
        $maxExtent = $width - 1;
        $tiers = min(count($words), $lines);
        $tierWidths = [...array_fill(0, max($tiers - 1, 0), $maxExtent - 4), $maxExtent - 4 - 2];
        $widths = array_map(fn (string $w) => min(Pillar::extent($w), $maxExtent - 4), $words);
        $assigned = Pillar::distribute($widths, $tierWidths);

        $split = [];
        foreach ($words as $i => $word) {
            if ($assigned[$i] !== null) {
                $split[$assigned[$i]][] = $word;
            }
        }
        if (in_array(null, $assigned, true) && $split !== []) {
            $split[array_key_last($split)][] = Pillar::ELLIPSIS;
        }

        $out = [];
        foreach (array_values($split) as $i => $tierWords) {
            $out[] = implode(' ', ['#', $i === 0 ? 'ℹ' : ' ', ...$tierWords]);
        }

        return $out;
    }

    private static function bigMark(int $n): string
    {
        return Pillar::bigMark($n);
    }
}
