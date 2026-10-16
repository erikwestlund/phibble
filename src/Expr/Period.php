<?php

declare(strict_types=1);

namespace Phibble\Expr;

/**
 * A lubridate period such as months(1), added to or subtracted from dates
 * and datetimes by clock arithmetic.
 *
 * @internal
 */
final readonly class Period
{
    public const array UNITS = ['seconds', 'minutes', 'hours', 'days', 'weeks', 'months', 'years'];

    public function __construct(public int|float $amount, public string $unit) {}

    public function negate(): self
    {
        return new self(-$this->amount, $this->unit);
    }
}
