<?php

declare(strict_types=1);

namespace Phibble;

use DateTimeImmutable;

/**
 * A calendar date (UTC midnight). Date columns return these, so a date
 * passed through an expression or a closure stays a date rather than
 * becoming a datetime. It behaves as any DateTimeImmutable.
 */
final class CalendarDate extends DateTimeImmutable {}
