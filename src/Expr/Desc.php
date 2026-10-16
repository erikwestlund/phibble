<?php

declare(strict_types=1);

namespace Phibble\Expr;

/** Descending sort key, for arrange() only. */
final readonly class Desc extends Expr
{
    public function __construct(public Expr $expr) {}

    public function toArray(): array
    {
        return ['type' => 'desc', 'expr' => $this->expr->toArray()];
    }

    public function columns(): array
    {
        return $this->expr->columns();
    }
}
