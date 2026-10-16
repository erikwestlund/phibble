<?php

declare(strict_types=1);

namespace Phibble\Expr;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The clock behind today() and now(). Tests can freeze it.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at;
    }

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
