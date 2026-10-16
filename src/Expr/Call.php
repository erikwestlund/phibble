<?php

declare(strict_types=1);

namespace Phibble\Expr;

/**
 * A call to a registered function or operator, with positional and named
 * arguments. Named arguments carry options such as lag's n, default and
 * order_by, case_when's default, and every aggregate's na_rm.
 */
final readonly class Call extends Expr
{
    /**
     * @param list<Expr> $args
     * @param array<string, Expr> $named
     */
    public function __construct(public string $fn, public array $args = [], public array $named = [])
    {
        Functions::check($fn, $args, $named);
    }

    public function toArray(): array
    {
        $node = ['type' => 'call', 'fn' => $this->fn, 'args' => array_map(fn (Expr $a) => $a->toArray(), $this->args)];
        if ($this->named !== []) {
            $node['named'] = array_map(fn (Expr $a) => $a->toArray(), $this->named);
        }

        return $node;
    }

    public function columns(): array
    {
        $all = array_map(fn (Expr $a) => $a->columns(), [...$this->args, ...array_values($this->named)]);

        return array_values(array_unique(array_merge([], ...$all)));
    }

    /** A named argument, or null when not given. */
    public function option(string $name): ?Expr
    {
        return $this->named[$name] ?? null;
    }
}
