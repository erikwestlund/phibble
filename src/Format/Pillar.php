<?php

declare(strict_types=1);

namespace Phibble\Format;

use DateTimeInterface;
use Phibble\Contracts\Table;
use Phibble\Type;

/**
 * Cell formatting shared by the printer and glimpse: pillar's shafts
 * (number, character, logical, date, datetime and list cells) and the
 * string helpers they rely on.
 *
 * @internal
 */
final class Pillar
{
    public const string ELLIPSIS = '…';

    public const array SUPERDIGITS = ['¹', '²', '³', '⁴', '⁵', '⁶', '⁷', '⁸', '⁹', '˟'];

    private const array RESERVED = [
        'if', 'else', 'repeat', 'while', 'function', 'for', 'next', 'break', 'TRUE', 'FALSE',
        'NULL', 'Inf', 'NaN', 'NA', 'NA_integer_', 'NA_real_', 'NA_character_', 'NA_complex_', 'in',
    ];

    /** Display width of a string, as pillar's get_extent(). */
    public static function extent(?string $s): int
    {
        return $s === null ? 2 : mb_strwidth($s, 'UTF-8');
    }

    /** Pad to $width on the left or right, as pillar's align(). */
    public static function align(string $s, int $width, string $align = 'left'): string
    {
        $spaces = str_repeat(' ', max($width - self::extent($s), 0));

        return $align === 'left' ? $s.$spaces : $spaces.$s;
    }

    /** Truncate to $width with a trailing ellipsis, as pillar's str_trunc(). */
    public static function truncate(string $s, int $width): string
    {
        if (self::extent($s) <= $width) {
            return $s;
        }

        return mb_substr($s, 0, max($width - 1, 0), 'UTF-8').self::ELLIPSIS;
    }

    /** R's is_syntactic(): names that print without backticks. */
    public static function isSyntactic(string $name): bool
    {
        if (in_array($name, self::RESERVED, true) || preg_match('/^\.\.(\.|\d+)$/', $name)) {
            return false;
        }

        return (bool) preg_match('/^((\p{L}|\.[._\p{L}])[._\p{L}\p{N}]*|\.)$/u', $name);
    }

    public static function tickIfNeeded(string $name): string
    {
        return self::isSyntactic($name) ? $name : '`'.str_replace(['\\', '`'], ['\\\\', '\\`'], $name).'`';
    }

    /** Format an integer with comma thousands separators, as pillar's big_mark(). */
    public static function bigMark(int $n): string
    {
        return number_format($n);
    }

    /** The type abbreviation pillar shows, without brackets. */
    public static function typeAbbr(Table $frame, string $name): string
    {
        $type = $frame->types()[$name];
        if ($type === Type::Factor && method_exists($frame, 'isOrdered') && $frame->isOrdered($name)) {
            return 'ord';
        }

        return $type->abbreviation();
    }

    /**
     * Build the shaft (formatted body) for one column.
     *
     * @param list<mixed> $values
     */
    public static function shaft(Type $type, array $values): Shaft
    {
        return match ($type) {
            Type::Float, Type::Int => DecimalShaft::fromValues($values, $type === Type::Int),
            Type::String, Type::Factor => self::characterShaft($values),
            Type::Bool => new SimpleShaft(
                array_map(fn ($v) => $v === null ? null : ($v ? 'TRUE' : 'FALSE'), $values),
                width: 5,
            ),
            Type::Date => new SimpleShaft(
                array_map(fn (?DateTimeInterface $v) => $v?->format('Y-m-d'), $values),
                width: 10,
            ),
            Type::DateTime => new SimpleShaft(
                array_map(fn (?DateTimeInterface $v) => $v?->format('Y-m-d H:i:s'), $values),
                width: 19,
            ),
            Type::List => self::listShaft($values),
        };
    }

    /** @param list<mixed> $values */
    private static function characterShaft(array $values): SimpleShaft
    {
        $strings = array_map(fn ($v) => $v === null ? null : (string) $v, $values);
        $ambiguous = array_any($strings, fn (?string $s) => $s !== null && preg_match('/^$|^ | $|\\\\|"/', $s));
        $naIndent = 0;
        if ($ambiguous) {
            $strings = array_map(fn (?string $s) => $s === null ? null : '"'.str_replace('"', '\\"', $s).'"', $strings);
            $naIndent = 1;
        }

        $width = max(0, ...array_map(self::extent(...), $strings ?: ['']));
        if ($strings === []) {
            $width = 0;
        }
        $na = '<NA>';

        return new SimpleShaft(
            $strings,
            width: $width,
            minWidth: min($width, 3),
            na: $na,
            naIndent: $width > self::extent($na) ? $naIndent : 0,
        );
    }

    /** @param list<mixed> $values */
    private static function listShaft(array $values): SimpleShaft
    {
        $full = [];
        $short = [];
        foreach ($values as $value) {
            [$summary, $abbr] = self::objSum($value);
            $full[] = "<{$summary}>";
            $short[] = '<'.($abbr ?? $summary).'>';
        }
        $width = $full === [] ? 0 : max(array_map(self::extent(...), $full));
        $min = $short === [] ? 0 : max(array_map(self::extent(...), $short));

        return new SimpleShaft($full, width: $width, minWidth: $min, short: $short);
    }

    /**
     * pillar's obj_sum() for a list-column cell: the full summary and the
     * short form used when the column is squeezed.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function objSum(mixed $value): array
    {
        if ($value === null) {
            return ['NULL', null];
        }
        if ($value instanceof Table) {
            $dim = self::bigMark($value->nrow()).' × '.self::bigMark($value->ncol());

            return ["tibble [{$dim}]", 'tibble'];
        }
        if (is_array($value)) {
            $abbr = array_is_list($value) ? self::vectorAbbr($value) : 'named list';

            return ["{$abbr} [".self::bigMark(count($value)).']', $abbr];
        }
        if (is_scalar($value) || $value instanceof DateTimeInterface) {
            $abbr = self::vectorAbbr([$value]);

            return ["{$abbr} [1]", $abbr];
        }

        $class = $value::class;
        $short = substr($class, (int) strrpos('\\'.$class, '\\'));

        return [$short, null];
    }

    /** @param list<mixed> $values */
    private static function vectorAbbr(array $values): string
    {
        $present = array_filter($values, fn ($v) => $v !== null);
        if ($present === []) {
            return $values === [] ? 'list' : 'lgl';
        }

        return match (true) {
            array_all($present, fn ($v) => is_bool($v)) => 'lgl',
            array_all($present, fn ($v) => is_int($v)) => 'int',
            array_all($present, fn ($v) => is_int($v) || is_float($v)) => 'dbl',
            array_all($present, fn ($v) => is_string($v)) => 'chr',
            array_all($present, fn ($v) => $v instanceof DateTimeInterface) => 'dttm',
            default => 'list',
        };
    }

    /**
     * Greedy word wrap like R's strwrap(): lines shorter than $width,
     * continuation lines indented by $exdent.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $width, int $exdent = 0): array
    {
        $words = preg_split('/ +/', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $indent = $lines === [] ? 0 : $exdent;
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && $indent + self::extent($candidate) >= $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        $lines[] = $line;

        return array_map(
            fn (string $l, int $i) => ($i === 0 ? '' : str_repeat(' ', $exdent)).$l,
            $lines,
            array_keys($lines),
        );
    }

    /**
     * pillar's distribute_pillars() for a list of widths over tiers.
     *
     * @param list<int> $widths
     * @param list<int> $tierWidths
     * @return list<?int> Tier (0-based) per width; null when it does not fit.
     */
    public static function distribute(array $widths, array $tierWidths): array
    {
        $tiers = array_fill(0, count($widths), null);
        $tier = 0;
        $x = 0;
        foreach ($widths as $i => $w) {
            if ($x + $w > $tierWidths[$tier]) {
                if ($w > $tierWidths[$tier]
                    && ($tier === count($tierWidths) - 1 || $w > $tierWidths[$tier + 1])) {
                    break;
                }
                $tier++;
                $x = 0;
                if ($tier >= count($tierWidths)) {
                    break;
                }
            }
            $tiers[$i] = $tier;
            $x += $w + 1;
        }

        return $tiers;
    }
}
