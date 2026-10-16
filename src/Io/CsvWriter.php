<?php

declare(strict_types=1);

namespace Phibble\Io;

use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Phibble\Frame;
use Phibble\Type;

/**
 * Formats a frame as delimited text the way readr::write_delim() does.
 *
 * @internal
 */
final class CsvWriter
{
    public static function format(
        Frame $frame,
        string $delimiter = ',',
        string $na = 'NA',
        bool $colNames = true,
        string $quote = 'needed',
        string $escape = 'double',
        string $eol = "\n",
    ): string {
        if (! in_array($quote, ['needed', 'all', 'none'], true)) {
            throw new InvalidArgumentException("quote must be 'needed', 'all' or 'none', got '{$quote}'.");
        }
        if (! in_array($escape, ['double', 'backslash', 'none'], true)) {
            throw new InvalidArgumentException("escape must be 'double', 'backslash' or 'none', got '{$escape}'.");
        }

        $types = $frame->types();
        foreach ($types as $name => $type) {
            if ($type === Type::List) {
                throw new InvalidArgumentException("Column '{$name}' is a list-column; CSV cannot hold it. Unnest or drop it first.");
            }
        }

        $cell = function (?string $text, bool $textual) use ($delimiter, $quote, $escape): string {
            if ($textual) {
                $text = match ($escape) {
                    'double' => str_replace('"', '""', $text),
                    'backslash' => str_replace('"', '\\"', $text),
                    'none' => $text,
                };
            }
            $wrap = match ($quote) {
                'all' => $textual,
                'none' => false,
                'needed' => $text !== '' && strpbrk($text, $delimiter."\"\n\r") !== false,
            };

            return $wrap ? '"'.$text.'"' : $text;
        };

        $lines = [];
        if ($colNames) {
            $lines[] = implode($delimiter, array_map(fn (string $n) => $cell($n, true), $frame->columns()));
        }

        $columns = [];
        foreach ($frame->toColumns() as $name => $values) {
            $type = $types[$name];
            $textual = in_array($type, [Type::String, Type::Factor, Type::Date, Type::DateTime], true);
            $columns[] = array_map(function (mixed $v) use ($type, $textual, $na, $cell): string {
                $text = self::text($v, $type);

                return $text === null ? $na : $cell($text, $textual);
            }, $values);
        }

        for ($i = 0, $n = $frame->nrow(); $i < $n; $i++) {
            $lines[] = implode($delimiter, array_column($columns, $i));
        }

        return $lines === [] ? '' : implode($eol, $lines).$eol;
    }

    /** The text for one value, or null for NA. */
    private static function text(mixed $v, Type $type): ?string
    {
        return match (true) {
            $v === null => null,
            is_bool($v) => $v ? 'TRUE' : 'FALSE',
            is_int($v) => (string) $v,
            is_float($v) => self::formatDouble($v),
            $v instanceof DateTimeInterface => $type === Type::Date
                ? $v->format('Y-m-d')
                : \DateTimeImmutable::createFromInterface($v)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            default => (string) $v,
        };
    }

    /**
     * readr's double formatting: the shortest digits that round-trip, in
     * fixed notation when that stays short, otherwise in readr's
     * scientific forms ("15e14", "1.2e-4"). NaN is NA.
     */
    public static function formatDouble(float $x): ?string
    {
        if (is_nan($x)) {
            return null;
        }
        if (is_infinite($x)) {
            return $x > 0 ? 'Inf' : '-Inf';
        }
        if ($x == 0.0) {
            return '0';
        }

        $sign = $x < 0 ? '-' : '';
        $abs = abs($x);
        for ($p = 1; $p <= 17; $p++) {
            $s = sprintf('%.'.($p - 1).'e', $abs);
            if ((float) $s === $abs) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $s);
        $digits = rtrim(str_replace('.', '', $mantissa), '0') ?: '0';
        $n = strlen($digits);
        $e = (int) $exponent - ($n - 1); // value = digits × 10^e

        if ($e >= 0) {
            return $sign.($e === 0 || $n + $e <= 15 ? $digits.str_repeat('0', $e) : $digits.'e'.$e);
        }

        $after = -$e;
        if ($n > $after) {
            return $sign.substr($digits, 0, $n - $after).'.'.substr($digits, $n - $after);
        }
        $zeros = $after - $n;
        if ($zeros <= 2) {
            return $sign.'0.'.str_repeat('0', $zeros).$digits;
        }

        return $sign.$digits[0].($n > 1 ? '.'.substr($digits, 1) : '').'e'.($e + $n - 1);
    }
}
