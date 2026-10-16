<?php

declare(strict_types=1);

namespace Phibble\Sql;

/**
 * A piece of SQL with its positional bindings, in textual order. Joining
 * fragments keeps placeholders and bindings aligned.
 */
final readonly class Fragment
{
    /** @param list<mixed> $bindings */
    public function __construct(public string $sql, public array $bindings = []) {}

    public static function raw(string $sql): self
    {
        return new self($sql);
    }

    public static function param(mixed $value): self
    {
        return new self('?', [$value]);
    }

    /**
     * Build a fragment from a template where each {} takes the next part.
     *
     * @param list<Fragment|string> $parts
     */
    public static function format(string $template, array $parts): self
    {
        $pieces = explode('{}', $template);
        if (count($pieces) !== count($parts) + 1) {
            throw new \LogicException("Template '{$template}' expects ".(count($pieces) - 1).' parts, got '.count($parts).'.');
        }
        $sql = $pieces[0];
        $bindings = [];
        foreach ($parts as $i => $part) {
            $part = is_string($part) ? self::raw($part) : $part;
            $sql .= $part->sql.$pieces[$i + 1];
            array_push($bindings, ...$part->bindings);
        }

        return new self($sql, $bindings);
    }

    /** @param list<Fragment> $parts */
    public static function join(string $glue, array $parts): self
    {
        return self::format(implode($glue, array_fill(0, count($parts), '{}')), $parts);
    }
}
