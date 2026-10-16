<?php

declare(strict_types=1);

namespace Phibble\Format;

use DateTimeInterface;
use Phibble\Contracts\Table;
use Phibble\Type;

/**
 * Renders a transposed overview of a table, as tibble's glimpse(): one line
 * per column with its type and as many values as fit the width.
 */
final class Glimpse
{
    public static function render(Table $table, int $width = 80): string
    {
        $frame = $table->collect();
        $lines = ['Rows: '.Pillar::bigMark($frame->nrow()), 'Columns: '.Pillar::bigMark($frame->ncol())];
        if (method_exists($frame, 'isRowwise') && $frame->isRowwise()) {
            $lines[] = 'Rowwise: '.implode(', ', $frame->groups());
        } elseif ($frame->groups() !== []) {
            $groups = iterator_count((fn () => yield from $frame->eachGroup())());
            $lines[] = 'Groups: '.implode(', ', $frame->groups()).' ['.Pillar::bigMark($groups).']';
        }
        if ($frame->ncol() === 0) {
            return implode("\n", $lines);
        }

        $head = $frame->head(intdiv($width, 3));
        $names = array_map(Pillar::tickIfNeeded(...), $head->columns());
        $nameWidth = max(array_map(Pillar::extent(...), $names));
        foreach ($head->columns() as $i => $name) {
            $prefix = '$ '.Pillar::align($names[$i], $nameWidth).' <'.Pillar::typeAbbr($head, $name).'> ';
            $values = implode(', ', self::formatColumn($head, $name));
            $lines[] = $prefix.Pillar::truncate($values, $width - Pillar::extent($prefix) - 1);
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private static function formatColumn(Table $frame, string $name): array
    {
        $values = $frame->column($name);

        return match ($frame->types()[$name]) {
            Type::Float => self::formatNumbers($values),
            Type::Int => array_map(fn (?int $v) => $v === null ? 'NA' : (string) $v, $values),
            Type::Bool => array_map(fn (?bool $v) => $v === null ? 'NA' : ($v ? 'TRUE' : 'FALSE'), $values),
            Type::String => array_map(fn (?string $v) => $v === null ? 'NA' : self::quote($v), $values),
            Type::Factor => self::formatFactor($frame, $name, $values),
            Type::Date => array_map(fn (?DateTimeInterface $v) => $v?->format('Y-m-d') ?? 'NA', $values),
            Type::DateTime => self::formatDateTimes($values),
            Type::List => array_map(self::formatListCell(...), $values),
        };
    }

    /**
     * @param list<?string> $values
     * @return list<string>
     */
    private static function formatFactor(Table $frame, string $name, array $values): array
    {
        $levels = method_exists($frame, 'levels') ? $frame->levels($name) : [];
        $quote = array_any($levels, fn (string $l) => str_contains($l, ','));

        return array_map(fn (?string $v) => $v === null ? 'NA' : ($quote ? self::quote($v) : $v), $values);
    }

    /**
     * R's format() for date-times: dates only when every value is midnight.
     *
     * @param list<?DateTimeInterface> $values
     * @return list<string>
     */
    private static function formatDateTimes(array $values): array
    {
        $midnight = array_all($values, fn (?DateTimeInterface $v) => $v === null || $v->format('H:i:s') === '00:00:00');
        $format = $midnight ? 'Y-m-d' : 'Y-m-d H:i:s';

        return array_map(fn (?DateTimeInterface $v) => $v?->format($format) ?? 'NA', $values);
    }

    private static function formatListCell(mixed $value): string
    {
        if ($value === null) {
            return '<NULL>';
        }
        if ($value instanceof Table) {
            return '[<tbl_df['.$value->nrow().' x '.$value->ncol().']>]';
        }
        if (! is_array($value)) {
            return implode(', ', self::formatAtomic([$value]));
        }

        $atomic = array_all($value, fn ($v) => $v === null || is_scalar($v) || $v instanceof DateTimeInterface)
            && array_is_list($value)
            && Pillar::objSum($value)[1] !== 'list';
        if (! $atomic) {
            return '['.implode(', ', array_map(self::formatListCell(...), array_values($value))).']';
        }
        $formatted = implode(', ', self::formatAtomic($value));

        return count($value) === 1 ? $formatted : "<{$formatted}>";
    }

    /**
     * Format a PHP list the way R formats the equivalent atomic vector.
     *
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function formatAtomic(array $values): array
    {
        $present = array_filter($values, fn ($v) => $v !== null);

        return match (true) {
            array_all($present, fn ($v) => is_bool($v)) => array_map(fn ($v) => $v === null ? 'NA' : ($v ? 'TRUE' : 'FALSE'), $values),
            array_all($present, fn ($v) => is_int($v)) => array_map(fn ($v) => $v === null ? 'NA' : (string) $v, $values),
            array_all($present, fn ($v) => is_int($v) || is_float($v)) => self::formatNumbers($values),
            array_all($present, fn ($v) => $v instanceof DateTimeInterface) => self::formatDateTimes($values),
            default => array_map(fn ($v) => $v === null ? 'NA' : self::quote((string) $v), $values),
        };
    }

    /** R's encodeString(x, quote = '"'). */
    private static function quote(string $s): string
    {
        $escaped = strtr($s, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\t" => '\\t', "\r" => '\\r']);

        return '"'.$escaped.'"';
    }

    /**
     * R's format() for a double vector (formatReal with 7 significant
     * digits): a shared number of decimals, or scientific notation for the
     * whole vector when that is narrower.
     *
     * @param list<int|float|null> $values
     * @return list<string>
     */
    public static function formatNumbers(array $values): array
    {
        $neg = false;
        $rgt = PHP_INT_MIN;
        $mxl = PHP_INT_MIN;
        $mnl = PHP_INT_MAX;
        $mxsl = PHP_INT_MIN;
        $mxns = PHP_INT_MIN;
        $anyFinite = false;
        foreach ($values as $x) {
            if ($x === null || ! is_finite((float) $x)) {
                continue;
            }
            $anyFinite = true;
            $x = (float) $x;
            $negative = $x < 0;
            if ($x == 0.0) {
                $kp = 0;
                $nsig = 1;
            } else {
                [$mantissa, $exponent] = explode('e', sprintf('%.6e', abs($x)));
                $kp = (int) $exponent;
                $nsig = strlen(rtrim(str_replace('.', '', $mantissa), '0')) ?: 1;
            }
            $left = $kp + 1;
            $sleft = ($negative ? 1 : 0) + ($left <= 0 ? 1 : $left);
            $right = $nsig - $left;
            if ($negative) {
                $neg = true;
            }
            $rgt = max($rgt, $right);
            $mxl = max($mxl, $left);
            $mnl = min($mnl, $left);
            $mxsl = max($mxsl, $sleft);
            $mxns = max($mxns, $nsig);
        }

        $scientific = false;
        $digits = 0;
        if ($anyFinite) {
            if ($mxl < 0) {
                $mxsl = 1 + ($neg ? 1 : 0);
            }
            $rgt = max($rgt, 0);
            $fixedWidth = $mxsl + $rgt + ($rgt !== 0 ? 1 : 0);
            $expDigits = ($mxl > 100 || $mnl <= -99) ? 2 : 1;
            $sciDigits = $mxns - 1;
            $sciWidth = ($neg ? 1 : 0) + ($sciDigits > 0 ? 1 : 0) + $sciDigits + 4 + $expDigits;
            if ($fixedWidth <= $sciWidth) {
                $digits = $rgt;
            } else {
                $scientific = true;
                $digits = $sciDigits;
            }
        }

        return array_map(function ($x) use ($scientific, $digits): string {
            if ($x === null) {
                return 'NA';
            }
            $x = (float) $x;
            if (is_nan($x)) {
                return 'NaN';
            }
            if (is_infinite($x)) {
                return $x > 0 ? 'Inf' : '-Inf';
            }
            if (! $scientific) {
                return sprintf("%.{$digits}f", $x);
            }
            [$mantissa, $exponent] = explode('e', sprintf("%.{$digits}e", $x));
            $exponent = (int) $exponent;

            return $mantissa.'e'.($exponent < 0 ? '-' : '+').str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
        }, $values);
    }
}
