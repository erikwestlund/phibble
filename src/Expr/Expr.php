<?php

declare(strict_types=1);

namespace Phibble\Expr;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * An inspectable, serialisable column expression, evaluated per group with
 * dplyr's vector semantics. Backends (in-memory, SQL, Polars) all read the
 * same tree: Col, Lit, Call, plus Desc for sort keys.
 *
 *     col('price')->mul(1.1)->gt(100)->and(col('brand')->isIn(['a', 'b']))
 */
abstract readonly class Expr
{
    /** @return array<string, mixed> A JSON-friendly description of the tree. */
    abstract public function toArray(): array;

    /** @return list<string> Column names this expression reads, in order of first use. */
    abstract public function columns(): array;

    /** @param array<string, mixed> $node */
    public static function fromArray(array $node): self
    {
        return match ($node['type'] ?? null) {
            'col' => new Col($node['name']),
            'lit' => Lit::fromArray($node),
            'call' => new Call(
                $node['fn'],
                array_map(self::fromArray(...), $node['args'] ?? []),
                array_map(self::fromArray(...), $node['named'] ?? []),
            ),
            'desc' => new Desc(self::fromArray($node['expr'])),
            default => throw new InvalidArgumentException('Unknown expression node: '.json_encode($node)),
        };
    }

    /** Wrap a PHP value as an expression; expressions pass through. */
    public static function of(mixed $value): self
    {
        return $value instanceof self ? $value : new Lit($value);
    }

    public function add(mixed $other): Call
    {
        return new Call('+', [$this, self::of($other)]);
    }

    public function sub(mixed $other): Call
    {
        return new Call('-', [$this, self::of($other)]);
    }

    public function mul(mixed $other): Call
    {
        return new Call('*', [$this, self::of($other)]);
    }

    public function div(mixed $other): Call
    {
        return new Call('/', [$this, self::of($other)]);
    }

    public function mod(mixed $other): Call
    {
        return new Call('%%', [$this, self::of($other)]);
    }

    public function pow(mixed $other): Call
    {
        return new Call('^', [$this, self::of($other)]);
    }

    public function neg(): Call
    {
        return new Call('neg', [$this]);
    }

    public function eq(mixed $other): Call
    {
        return new Call('==', [$this, self::of($other)]);
    }

    public function ne(mixed $other): Call
    {
        return new Call('!=', [$this, self::of($other)]);
    }

    public function lt(mixed $other): Call
    {
        return new Call('<', [$this, self::of($other)]);
    }

    public function le(mixed $other): Call
    {
        return new Call('<=', [$this, self::of($other)]);
    }

    public function gt(mixed $other): Call
    {
        return new Call('>', [$this, self::of($other)]);
    }

    public function ge(mixed $other): Call
    {
        return new Call('>=', [$this, self::of($other)]);
    }

    public function and(mixed $other): Call
    {
        return new Call('&', [$this, self::of($other)]);
    }

    public function or(mixed $other): Call
    {
        return new Call('|', [$this, self::of($other)]);
    }

    public function not(): Call
    {
        return new Call('!', [$this]);
    }

    public function isNa(): Call
    {
        return new Call('is_na', [$this]);
    }

    /** @param list<mixed> $values */
    public function isIn(array $values): Call
    {
        return new Call('in', [$this, new Lit(array_values($values))]);
    }

    public function between(mixed $low, mixed $high): Call
    {
        return new Call('between', [$this, self::of($low), self::of($high)]);
    }

    public function desc(): Desc
    {
        return new Desc($this);
    }
}
