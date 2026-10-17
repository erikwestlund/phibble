<?php

declare(strict_types=1);

namespace Phibble\Expr;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Phibble\CalendarDate;

// Helpers in this namespace shadow these built-ins (see helpers.php).
use function floor;
use function max;
use function round;
use function str_replace;
use function str_split;

/**
 * lubridate's basics, value by value, with lubridate's semantics: parsing
 * returns NA for invalid input, month and year arithmetic that lands on an
 * invalid date gives NA, ceiling_date() on a Date moves past a boundary.
 *
 * @internal
 */
final class Temporal
{
    private const array UNIT_SECONDS = ['secs' => 1, 'mins' => 60, 'hours' => 3600, 'days' => 86400, 'weeks' => 604800];

    // ---------------------------------------------------------------
    // Parsing
    // ---------------------------------------------------------------

    /** ymd(), dmy() and mdy(): a date from digits in that order; any separators. */
    public static function parseDate(?string $text, string $order, ?string $tz): ?DateTimeInterface
    {
        if ($text === null) {
            return null;
        }
        $parts = self::parts(trim($text), $order, false);
        if ($parts === null) {
            return null;
        }
        [$y, $m, $d] = $parts;
        if ($tz === null) {
            return (new CalendarDate('@0'))->setTimezone(self::zone('UTC'))->setDate($y, $m, $d);
        }

        return self::local($y, $m, $d, 0, 0, 0, 0, $tz);
    }

    /** ymd_hms(), dmy_hms() and mdy_hms(). */
    public static function parseDateTime(?string $text, string $order, ?string $tz): ?DateTimeImmutable
    {
        if ($text === null) {
            return null;
        }
        $parts = self::parts(trim($text), $order, true);
        if ($parts === null) {
            return null;
        }

        return self::local(...[...$parts, $tz ?? 'UTC']);
    }

    /**
     * parse_date_time() for orders made of Y, y, m, d, H, M and S, such as
     * "Ymd HMS" or "dmY". The first order that fits wins.
     *
     * @param list<string> $orders
     */
    public static function parseOrders(?string $text, array $orders, ?string $tz): ?DateTimeImmutable
    {
        if ($text === null) {
            return null;
        }
        foreach ($orders as $order) {
            $letters = str_split(str_replace([' ', '-', '/', ':', '_'], '', $order));
            $regex = '';
            foreach ($letters as $i => $letter) {
                $regex .= ($i === 0 ? '' : '\D*').match ($letter) {
                    'Y' => '(\d{4})',
                    'y' => '(\d{2})',
                    'm', 'd', 'H', 'M' => '(\d{1,2})',
                    'S' => '(\d{1,2}(?:\.\d+)?)',
                    default => throw new InvalidArgumentException("parse_date_time() does not support the order letter '{$letter}'."),
                };
            }
            if (! preg_match('/^\s*'.$regex.'\s*$/', $text, $m)) {
                continue;
            }
            $v = ['Y' => null, 'm' => 1, 'd' => 1, 'H' => 0, 'M' => 0, 'S' => '0'];
            foreach ($letters as $i => $letter) {
                $value = $m[$i + 1];
                if ($letter === 'y') {
                    // lubridate's two-digit years: 00-68 are 2000s, 69-99 1900s.
                    $v['Y'] = (int) $value + ((int) $value <= 68 ? 2000 : 1900);
                } elseif ($letter === 'Y') {
                    $v['Y'] = (int) $value;
                } else {
                    $v[$letter] = $letter === 'S' ? $value : (int) $value;
                }
            }
            [$sec, $micro] = self::seconds((string) $v['S']);
            $result = self::checked($v['Y'], $v['m'], $v['d'], $v['H'], $v['M'], $sec)
                ? self::local($v['Y'], $v['m'], $v['d'], $v['H'], $v['M'], $sec, $micro, $tz ?? 'UTC')
                : null;
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------
    // Parts
    // ---------------------------------------------------------------

    public static function part(?DateTimeInterface $x, string $fn, int $weekStart = 7): int|float|null
    {
        if ($x === null) {
            return null;
        }

        return match ($fn) {
            'year' => (int) $x->format('Y'),
            'month' => (int) $x->format('n'),
            'day' => (int) $x->format('j'),
            'yday' => (int) $x->format('z') + 1,
            'quarter' => intdiv((int) $x->format('n') - 1, 3) + 1,
            'wday' => ((int) $x->format('w') - ($weekStart % 7) + 7) % 7 + 1,
            'hour' => (int) $x->format('G'),
            'minute' => (int) $x->format('i'),
            'second' => (int) $x->format('s') + (int) $x->format('u') / 1e6,
        };
    }

    // ---------------------------------------------------------------
    // Rounding
    // ---------------------------------------------------------------

    public static function round(?DateTimeInterface $x, string $unit, string $how, int $weekStart = 7): ?DateTimeInterface
    {
        if ($x === null) {
            return null;
        }
        [$n, $unit] = self::unit($unit);
        $floor = self::floor(self::immutable($x), $n, $unit, $weekStart);
        if ($how === 'floor') {
            return $floor;
        }
        // Dates move past a boundary (lubridate's change_on_boundary for Date).
        $isDate = $x instanceof CalendarDate;
        $ceiling = $floor == $x && ! $isDate ? $floor : self::add($floor, $n, $unit);
        if ($how === 'ceiling') {
            return $ceiling;
        }
        $down = $x->getTimestamp() - $floor->getTimestamp();
        $up = $ceiling->getTimestamp() - $x->getTimestamp();

        return $up <= $down ? $ceiling : $floor;
    }

    private static function floor(DateTimeImmutable $x, int $n, string $unit, int $weekStart): DateTimeImmutable
    {
        [$y, $m, $d, $h, $i, $s] = array_map(intval(...), explode(' ', $x->format('Y n j G i s')));

        return match ($unit) {
            'second' => $x->setTime($h, $i, $s - $s % $n),
            'minute' => $x->setTime($h, $i - $i % $n, 0),
            'hour' => $x->setTime($h - $h % $n, 0, 0),
            'day' => $x->setDate($y, $m, $d - ($d - 1) % $n)->setTime(0, 0, 0),
            'week' => $x->setTime(0, 0, 0)->modify('-'.(((int) $x->format('w') - ($weekStart % 7) + 7) % 7).' days'),
            'month' => $x->setDate($y, $m - ($m - 1) % $n, 1)->setTime(0, 0, 0),
            'quarter' => $x->setDate($y, $m - ($m - 1) % (3 * $n), 1)->setTime(0, 0, 0),
            'year' => $x->setDate($y - $y % $n, 1, 1)->setTime(0, 0, 0),
        };
    }

    /** @return array{0: int, 1: string} "15 minutes" → [15, 'minute'] */
    private static function unit(string $unit): array
    {
        if (! preg_match('/^\s*(\d+)?\s*([a-z]+?)s?\s*$/i', $unit, $m)) {
            throw new InvalidArgumentException("Unknown time unit '{$unit}'.");
        }
        $name = strtolower($m[2]);
        $name = match ($name) {
            'sec' => 'second',
            'min' => 'minute',
            'bimonth' => 'month',
            default => $name,
        };
        if (! in_array($name, ['second', 'minute', 'hour', 'day', 'week', 'month', 'quarter', 'year'], true)) {
            throw new InvalidArgumentException("Unknown time unit '{$unit}'.");
        }
        $n = $m[1] === '' ? 1 : (int) $m[1];

        return [$m[2] === 'bimonth' ? 2 * $n : max(1, $n), $name];
    }

    private static function add(DateTimeImmutable $x, int $n, string $unit): DateTimeImmutable
    {
        return match ($unit) {
            'quarter' => $x->modify('+'.(3 * $n).' months'),
            default => $x->modify("+{$n} {$unit}"),
        };
    }

    // ---------------------------------------------------------------
    // Arithmetic
    // ---------------------------------------------------------------

    /** Clock arithmetic with a period; invalid month and year results are NA, as in lubridate. */
    public static function plus(DateTimeInterface $x, Period $p): ?DateTimeInterface
    {
        $x = self::immutable($x);
        $amount = $p->amount;
        if (in_array($p->unit, ['months', 'years'], true)) {
            $months = (int) $amount * ($p->unit === 'years' ? 12 : 1);
            $total = (int) $x->format('Y') * 12 + (int) $x->format('n') - 1 + $months;
            $y = intdiv($total, 12) - ($total < 0 && $total % 12 !== 0 ? 1 : 0);
            $m = $total - $y * 12 + 1;
            $d = (int) $x->format('j');

            return checkdate($m, $d, $y) ? $x->setDate($y, $m, $d) : null;
        }
        if ($p->unit === 'days' || $p->unit === 'weeks') {
            $days = (int) $amount * ($p->unit === 'weeks' ? 7 : 1);

            return $x->modify(($days < 0 ? '' : '+')."{$days} days");
        }
        $seconds = $amount * ['hours' => 3600, 'minutes' => 60, 'seconds' => 1][$p->unit];
        $whole = (int) floor($seconds);
        $micro = (int) round(($seconds - $whole) * 1e6);
        $out = $x->modify(($whole < 0 ? '' : '+')."{$whole} seconds");

        return $micro === 0 ? $out : $out->modify("+{$micro} microseconds");
    }

    /** difftime(x, y, units): a double in the given units. */
    public static function difference(DateTimeInterface $x, DateTimeInterface $y, string $units): float
    {
        $factor = self::UNIT_SECONDS[$units] ?? throw new InvalidArgumentException("difftime() units must be secs, mins, hours, days or weeks, got '{$units}'.");
        $seconds = ((float) $x->format('U.u')) - ((float) $y->format('U.u'));

        return $seconds / $factor;
    }

    // ---------------------------------------------------------------
    // Time zones and the clock
    // ---------------------------------------------------------------

    public static function withTz(?DateTimeInterface $x, string $tz): ?DateTimeImmutable
    {
        return $x === null ? null : DateTimeImmutable::createFromInterface($x)->setTimezone(self::zone($tz));
    }

    /** The same clock time in another zone; NA for a time that zone skips (DST gap). */
    public static function forceTz(?DateTimeInterface $x, string $tz): ?DateTimeImmutable
    {
        if ($x === null) {
            return null;
        }
        $clock = $x->format('Y-m-d H:i:s.u');
        $out = new DateTimeImmutable($clock, self::zone($tz));

        return $out->format('Y-m-d H:i:s.u') === $clock ? $out : null;
    }

    public static function today(?string $tz): CalendarDate
    {
        $now = Clock::now()->setTimezone(self::zone($tz ?? 'UTC'));

        return (new CalendarDate('@0'))->setTimezone(self::zone('UTC'))
            ->setDate((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));
    }

    public static function now(?string $tz): DateTimeImmutable
    {
        return Clock::now()->setTimezone(self::zone($tz ?? 'UTC'));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Numbers from text in the given order of y, m, d (and H, M, S).
     *
     * @return list<int>|null [y, m, d] or [y, m, d, H, M, S, micro]
     */
    private static function parts(string $text, string $order, bool $time): ?array
    {
        // Each part is either fixed-width digits run together (20240131, 235959)
        // or separated by non-digits, as lubridate reads them.
        $date = [
            'ymd' => '(?:(\d{4})(\d{2})(\d{2})|(\d{4})\D+(\d{1,2})\D+(\d{1,2}))',
            'dmy' => '(?:(\d{2})(\d{2})(\d{4})|(\d{1,2})\D+(\d{1,2})\D+(\d{4}))',
            'mdy' => '(?:(\d{2})(\d{2})(\d{4})|(\d{1,2})\D+(\d{1,2})\D+(\d{4}))',
        ][$order];
        $clock = '(?:(\d{2})(\d{2})(\d{2}(?:\.\d+)?)|(\d{1,2})\D+(\d{1,2})\D+(\d{1,2}(?:\.\d+)?))';
        // Date and time are separated by non-digits, unless the text is all digits.
        $pattern = $time ? $date.(ctype_digit($text) ? '' : '\D+').$clock : $date;
        if (! preg_match('/^'.$pattern.'$/', $text, $raw)) {
            return null;
        }
        // Keep the groups of whichever alternative matched.
        $m = [0 => $raw[0]];
        foreach (array_slice($raw, 1) as $value) {
            if ($value !== '') {
                $m[] = $value;
            }
        }
        [$y, $mo, $d] = match ($order) {
            'ymd' => [(int) $m[1], (int) $m[2], (int) $m[3]],
            'dmy' => [(int) $m[3], (int) $m[2], (int) $m[1]],
            'mdy' => [(int) $m[3], (int) $m[1], (int) $m[2]],
        };
        if (count($m) < ($time ? 7 : 4)) {
            return null;
        }
        if (! $time) {
            return checkdate($mo, $d, $y) ? [$y, $mo, $d] : null;
        }
        [$sec, $micro] = self::seconds($m[6]);
        if (! self::checked($y, $mo, $d, (int) $m[4], (int) $m[5], $sec)) {
            return null;
        }

        return [$y, $mo, $d, (int) $m[4], (int) $m[5], $sec, $micro];
    }

    /** @return array{0: int, 1: int} */
    private static function seconds(string $s): array
    {
        $whole = (int) floor((float) $s);

        return [$whole, (int) round(((float) $s - $whole) * 1e6)];
    }

    private static function checked(?int $y, int $m, int $d, int $h, int $i, int $s): bool
    {
        return $y !== null && checkdate($m, $d, $y) && $h <= 23 && $i <= 59 && $s <= 59;
    }

    /** A local time in a zone; NA when the zone skips it. */
    private static function local(int $y, int $m, int $d, int $h, int $i, int $s, int $micro, string $tz): ?DateTimeImmutable
    {
        $out = (new DateTimeImmutable('@0'))->setTimezone(self::zone($tz))->setDate($y, $m, $d)->setTime($h, $i, $s, $micro);

        return (int) $out->format('G') === $h ? $out : null;
    }

    private static function immutable(DateTimeInterface $x): DateTimeImmutable
    {
        return $x instanceof DateTimeImmutable ? $x : DateTimeImmutable::createFromInterface($x);
    }

    private static function zone(string $tz): DateTimeZone
    {
        static $zones = [];
        try {
            return $zones[$tz] ??= new DateTimeZone($tz);
        } catch (Exception) {
            throw new InvalidArgumentException("Unknown time zone '{$tz}'.");
        }
    }
}
