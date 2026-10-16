<?php

declare(strict_types=1);

namespace Phibble\Expr;

/**
 * A single value produced while evaluating an expression (a literal or an
 * aggregate). It is repeated when combined with per-row values.
 *
 * @internal
 */
final readonly class Scalar
{
    public function __construct(public mixed $value) {}
}
