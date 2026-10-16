<?php

declare(strict_types=1);

namespace Phibble\Expr;

/** A column reference. */
final readonly class Col extends Expr
{
    public function __construct(public string $name) {}

    public function toArray(): array
    {
        return ['type' => 'col', 'name' => $this->name];
    }

    public function columns(): array
    {
        return [$this->name];
    }
}
