<?php

declare(strict_types=1);

namespace Phibble\Io;

use DateTimeImmutable;
use Phibble\Column\TemporalColumn;
use DateTimeZone;
use Exception;
use Phibble\Column\ArrayColumn;
use Phibble\Column\Column;
use Phibble\Column\FactorColumn;
use Phibble\Type;

/**
 * Guesses and parses column types from text the way readr (vroom) does.
 *
 * One deliberate difference: whole numbers guess as int, where readr
 * guesses double.
 *
 * @internal
 */
final readonly class TextParser
{
    private const array TRUE = ['T', 'TRUE', 'true', 'True', 't'];

    private const array FALSE = ['F', 'FALSE', 'false', 'False', 'f'];

    private const string DATE = '/^(\d{4})[-\/](\d{2})[-\/](\d{2})$/';

    private const string DATETIME = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2})(?::(\d{2})(?::(\d{2})(\.\d+)?)?)?)?(Z|[+-]\d{2}(?::?\d{2})?)?$/';

    private string $grouping;

    private string $decimal;

    public function __construct(string $decimalMark = '.', ?string $groupingMark = null)
    {
        $this->decimal = $decimalMark;
        $this->grouping = $groupingMark ?? ($decimalMark === ',' ? '.' : ',');
    }

    /**
     * Guess the type of text values; nulls are ignored, and an all-null
     * column is bool. Values are not trimmed.
     *
     * @param list<?string> $values
     */
    public function guess(array $values): Type
    {
        $present = [];
        foreach ($values as $v) {
            if ($v !== null) {
                $present[] = $v;
            }
        }
        if ($present === []) {
            return Type::Bool;
        }

        $all = function (callable $test) use ($present): bool {
            foreach ($present as $v) {
                if (! $test($v)) {
                    return false;
                }
            }

            return true;
        };

        return match (true) {
            $all(fn ($v) => in_array($v, self::TRUE, true) || in_array($v, self::FALSE, true)) => Type::Bool,
            $all(fn ($v) => $this->guessesAsInt($v)) => Type::Int,
            $all(fn ($v) => $this->guessesAsNumber($v)) => Type::Float,
            $all(fn ($v) => preg_match(self::DATE, $v) === 1) => Type::Date,
            $all(fn ($v) => preg_match(self::DATETIME, $v) === 1) => Type::DateTime,
            default => Type::String,
        };
    }

    /**
     * Parse text into a column of the given type. Values that do not parse
     * become null; the second element counts them.
     *
     * Options: 'number' (parse_number(): take the first number in the text,
     * ignoring other characters), 'grouping' (allow grouping marks),
     * 'levels' and 'ordered' for factors.
     *
     * @param list<?string> $values
     * @param array{number?: bool, grouping?: bool, levels?: list<string>|null, ordered?: bool} $options
     * @return array{0: Column, 1: int}
     */
    public function parse(array $values, Type $type, array $options = []): array
    {
        if ($type === Type::Factor) {
            return $this->parseFactor($values, $options['levels'] ?? null, $options['ordered'] ?? false);
        }
        if ($type === Type::String || $type === Type::List) {
            return [new ArrayColumn($type, $values), 0];
        }

        if ($type === Type::Date || $type === Type::DateTime) {
            return $this->parseTemporal($values, $type);
        }
        $grouping = $options['grouping'] ?? false;
        $number = $options['number'] ?? false;
        $out = [];
        $failed = 0;
        foreach ($values as $v) {
            if ($v === null) {
                $out[] = null;

                continue;
            }
            $parsed = match ($type) {
                Type::Bool => $this->parseBool($v),
                Type::Int => $this->parseInt($v, $grouping),
                Type::Float => $number ? $this->parseNumber($v) : $this->parseDouble($v, $grouping),
                Type::Date => self::parseDate($v),
                Type::DateTime => self::parseDateTime($v),
            };
            if ($parsed === null) {
                $failed++;
            }
            $out[] = $parsed;
        }

        return [new ArrayColumn($type, $out), $failed];
    }

    /**
     * Dates and datetimes straight to integer storage. The common ISO
     * shapes are converted arithmetically; anything else is parsed to an
     * object first.
     *
     * @param list<?string> $values
     * @return array{0: TemporalColumn, 1: int}
     */
    private function parseTemporal(array $values, Type $type): array
    {
        $ticks = [];
        $failed = 0;
        foreach ($values as $v) {
            if ($v === null) {
                $ticks[] = null;

                continue;
            }
            $tick = null;
            $len = strlen($v);
            if (($len === 10 || ($len === 19 && ($v[10] === ' ' || $v[10] === 'T') && $v[13] === ':' && $v[16] === ':'))
                && $v[4] === '-' && $v[7] === '-'
                && ctype_digit(substr($v, 0, 4).substr($v, 5, 2).substr($v, 8, 2).($len === 19 ? substr($v, 11, 2).substr($v, 14, 2).substr($v, 17, 2) : ''))) {
                [$y, $m, $d] = [(int) substr($v, 0, 4), (int) substr($v, 5, 2), (int) substr($v, 8, 2)];
                [$h, $i, $s] = $len === 19 ? [(int) substr($v, 11, 2), (int) substr($v, 14, 2), (int) substr($v, 17, 2)] : [0, 0, 0];
                if (checkdate($m, $d, $y) && $h <= 23 && $i <= 59 && $s <= 59 && ($type === Type::DateTime || $len === 10)) {
                    $tick = $type === Type::Date
                        ? TemporalColumn::days($y, $m, $d)
                        : (TemporalColumn::days($y, $m, $d) * 86400 + $h * 3600 + $i * 60 + $s) * 1_000_000;
                }
            }
            if ($tick === null) {
                $object = $type === Type::Date ? self::parseDate($v) : self::parseDateTime($v);
                if ($object !== null) {
                    $tick = TemporalColumn::fromObjects($type, [$object])->ticks()[0];
                }
            }
            if ($tick === null) {
                $failed++;
            }
            $ticks[] = $tick;
        }

        return [new TemporalColumn($type, $ticks), $failed];
    }

    public function guessesAsInt(string $v): bool
    {
        $g = preg_quote($this->grouping, '/');
        if (! preg_match('/^[+-]?\d(?:[\d'.$g.']*\d)?$/', $v) || self::leadingZero($v)) {
            return false;
        }

        return $this->parseInt($v, true) !== null;
    }

    public function guessesAsNumber(string $v): bool
    {
        if (preg_match('/^[+-]?(inf|nan)$/i', $v)) {
            return true;
        }
        $g = preg_quote($this->grouping, '/');
        $d = preg_quote($this->decimal, '/');

        return preg_match('/^[+-]?(?:\d[\d'.$g.']*(?:'.$d.'\d*)?|'.$d.'\d+)(?:[eE][+-]?\d+)?$/', $v) === 1
            && ! self::leadingZero($v);
    }

    /** readr reads unsigned numbers with a leading zero ("007", "00.5", "0e5") as text. */
    private static function leadingZero(string $v): bool
    {
        return strlen($v) > 1 && $v[0] === '0' && (ctype_digit($v[1]) || $v[1] === 'e' || $v[1] === 'E');
    }

    private function parseBool(string $v): ?bool
    {
        return match (true) {
            in_array($v, self::TRUE, true), $v === '1' => true,
            in_array($v, self::FALSE, true), $v === '0' => false,
            default => null,
        };
    }

    private function parseInt(string $v, bool $grouping): ?int
    {
        // Fast path: plain digits that fit in 64 bits.
        if ($v === '') {
            return null;
        }
        if (strlen($v) < 19 && (ctype_digit($v) || ($v[0] === '-' && strlen($v) > 1 && ctype_digit(substr($v, 1))))) {
            return (int) $v;
        }
        if ($grouping) {
            $v = str_replace($this->grouping, '', $v);
        }
        if (! preg_match('/^[+-]?\d+$/', $v)) {
            return null;
        }
        $digits = ltrim($v, '+-');
        $digits = ltrim($digits, '0') ?: '0';
        $int = filter_var(($v[0] === '-' ? '-' : '').$digits, FILTER_VALIDATE_INT);

        return $int === false ? null : $int;
    }

    private function parseDouble(string $v, bool $grouping): ?float
    {
        // Fast path: an ordinary decimal number (is_numeric also allows
        // leading whitespace and hex-free exponents, which readr accepts).
        if ($this->decimal === '.' && is_numeric($v) && $v[0] !== ' ' && $v[0] !== "\t" && $v[0] !== "\n") {
            return (float) $v;
        }
        if (preg_match('/^([+-]?)(inf|nan)$/i', $v, $m)) {
            return strtolower($m[2]) === 'nan' ? NAN : ($m[1] === '-' ? -INF : INF);
        }
        if ($grouping) {
            $v = str_replace($this->grouping, '', $v);
        }
        $d = preg_quote($this->decimal, '/');
        if (! preg_match('/^[+-]?(?:\d+(?:'.$d.'\d*)?|'.$d.'\d+)(?:[eE][+-]?\d+)?$/', $v)) {
            return null;
        }

        return (float) str_replace($this->decimal, '.', $v);
    }

    /** readr::parse_number(): the first number in the text, ignoring grouping marks. */
    private function parseNumber(string $v): ?float
    {
        $g = preg_quote($this->grouping, '/');
        $d = preg_quote($this->decimal, '/');
        if (! preg_match('/[+-]?(?:\d[\d'.$g.']*(?:'.$d.'\d+)?|'.$d.'\d+)(?:[eE][+-]?\d+)?/', $v, $m)) {
            return null;
        }

        return $this->parseDouble($m[0], true);
    }

    /** Dates are UTC midnight. */
    public static function parseDate(string $v): ?DateTimeImmutable
    {
        if (! preg_match(self::DATE, $v, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', "{$m[1]}-{$m[2]}-{$m[3]}", new DateTimeZone('UTC')) ?: null;
    }

    /** ISO 8601 datetimes, converted to UTC. Values without an offset are UTC. */
    public static function parseDateTime(string $v): ?DateTimeImmutable
    {
        static $utc = null;
        $utc ??= new DateTimeZone('UTC');
        // Fast path: "YYYY-MM-DD HH:MM:SS" (or with T), already UTC.
        if (strlen($v) === 19 && ($v[10] === ' ' || $v[10] === 'T') && $v[4] === '-' && $v[7] === '-' && $v[13] === ':' && $v[16] === ':'
            && ctype_digit(substr($v, 0, 4).substr($v, 5, 2).substr($v, 8, 2).substr($v, 11, 2).substr($v, 14, 2).substr($v, 17, 2))) {
            $year = (int) substr($v, 0, 4);
            $month = (int) substr($v, 5, 2);
            $day = (int) substr($v, 8, 2);
            if (! checkdate($month, $day, $year) || (int) substr($v, 11, 2) > 23 || (int) substr($v, 14, 2) > 59 || (int) substr($v, 17, 2) > 59) {
                return null;
            }

            return DateTimeImmutable::createFromFormat('!Y-m-d?H:i:s', $v, $utc) ?: null;
        }
        if (! preg_match(self::DATETIME, $v, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        [$hour, $minute, $second] = [(int) ($m[4] ?? 0), (int) (($m[5] ?? '') ?: 0), (int) (($m[6] ?? '') ?: 0)];
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $fraction = ($m[7] ?? '') === '' ? '' : substr($m[7], 0, 7);
        $offset = $m[8] ?? '';
        $offset = match (true) {
            $offset === '', $offset === 'Z' => '+00:00',
            strlen($offset) === 3 => $offset.':00',
            strlen($offset) === 5 => substr($offset, 0, 3).':'.substr($offset, 3),
            default => $offset,
        };
        $text = sprintf('%s-%s-%sT%02d:%02d:%02d%s%s', $m[1], $m[2], $m[3], $hour, $minute, $second, $fraction, $offset);

        try {
            return new DateTimeImmutable($text)->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * readr's col_factor(): without levels, levels are in order of first
     * appearance. Values outside explicit levels are problems.
     *
     * @param list<?string> $values
     * @param list<string>|null $levels
     * @return array{0: Column, 1: int}
     */
    private function parseFactor(array $values, ?array $levels, bool $ordered): array
    {
        if ($levels === null) {
            $levels = [];
            foreach ($values as $v) {
                if ($v !== null) {
                    $levels[$v] = true;
                }
            }
            $levels = array_map(strval(...), array_keys($levels));
        }
        $column = FactorColumn::fromValues($values, $levels, $ordered);
        $failed = 0;
        foreach ($column->codes() as $i => $code) {
            if ($code === null && $values[$i] !== null) {
                $failed++;
            }
        }

        return [$column, $failed];
    }
}
