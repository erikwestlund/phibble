<?php

declare(strict_types=1);

namespace Phibble\Column;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Phibble\CalendarDate;
use Phibble\Type;

/**
 * Dates and datetimes stored as integers: days since 1970-01-01 for dates
 * (as R's Date), microseconds since the epoch for datetimes. Objects are
 * built only when values are read (values(), get()), so a million
 * datetimes take integers' memory rather than objects'.
 *
 * A datetime column has one timezone, like R's POSIXct "tzone"; values
 * are shown in it. Dates are UTC midnight.
 */
final readonly class TemporalColumn implements Column
{
    private const int MICRO = 1_000_000;

    /**
     * @param list<?int> $ticks Days (date) or microseconds (datetime); null is NA.
     */
    public function __construct(
        private Type $type,
        private array $ticks,
        private string $timezone = 'UTC',
    ) {
        if ($type !== Type::Date && $type !== Type::DateTime) {
            throw new InvalidArgumentException('TemporalColumn holds dates or datetimes.');
        }
    }

    /**
     * @param list<?DateTimeInterface> $values
     */
    public static function fromObjects(Type $type, array $values): self
    {
        $ticks = [];
        $timezone = null;
        foreach ($values as $value) {
            if ($value === null) {
                $ticks[] = null;

                continue;
            }
            if ($type === Type::Date) {
                $ticks[] = self::days((int) $value->format('Y'), (int) $value->format('n'), (int) $value->format('j'));

                continue;
            }
            $timezone ??= $value->getTimezone()->getName();
            $ticks[] = $value->getTimestamp() * self::MICRO + (int) $value->format('u');
        }

        return new self($type, $ticks, $type === Type::Date ? 'UTC' : ($timezone ?? 'UTC'));
    }

    /** Days since 1970-01-01 for a calendar date. */
    public static function days(int $year, int $month, int $day): int
    {
        return intdiv(gmmktime(0, 0, 0, $month, $day, $year), 86400);
    }

    /** @return list<?int> Days or microseconds since the epoch. */
    public function ticks(): array
    {
        return $this->ticks;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function type(): Type
    {
        return $this->type;
    }

    public function values(): array
    {
        $prototype = $this->prototype();

        return array_map(fn (?int $t) => $t === null ? null : $this->object($prototype, $t), $this->ticks);
    }

    public function get(int $index): mixed
    {
        $t = $this->ticks[$index];

        return $t === null ? null : $this->object($this->prototype(), $t);
    }

    public function take(array $indices): static
    {
        $ticks = [];
        foreach ($indices as $i) {
            $ticks[] = $i === null ? null : $this->ticks[$i];
        }

        return new self($this->type, $ticks, $this->timezone);
    }

    public function count(): int
    {
        return count($this->ticks);
    }

    private function prototype(): DateTimeImmutable
    {
        static $prototypes = [];

        $key = $this->type->value.':'.$this->timezone;

        return $prototypes[$key] ??= $this->type === Type::Date
            ? new CalendarDate('@0')->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable('@0')->setTimezone(new DateTimeZone($this->timezone));
    }

    private function object(DateTimeImmutable $prototype, int $t): DateTimeImmutable
    {
        if ($this->type === Type::Date) {
            return $prototype->setTimestamp($t * 86400);
        }
        $seconds = intdiv($t, self::MICRO);
        $micro = $t % self::MICRO;
        if ($micro < 0) {
            $seconds--;
            $micro += self::MICRO;
        }
        $object = $prototype->setTimestamp($seconds);

        return $micro === 0 ? $object : $object->setMicrosecond($micro);
    }
}
