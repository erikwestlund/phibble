<?php

declare(strict_types=1);

namespace Phibble\Expr;

use InvalidArgumentException;

/**
 * The fixed registry of functions an Expr may call. Names follow dplyr/R.
 * Every backend implements this list and nothing else.
 *
 * Kinds:
 * - op, scalar: one value per row (scalars broadcast)
 * - agg: one value per group (recycled inside mutate)
 * - window: one value per row, computed over the group in row order
 */
final class Functions
{
    /**
     * name => [kind, min positional args, max positional args (null = any), allowed named args]
     *
     * @var array<string, array{0: string, 1: int, 2: ?int, 3: list<string>}>
     */
    public const array REGISTRY = [
        // Operators
        '+' => ['op', 2, 2, []],
        '-' => ['op', 2, 2, []],
        '*' => ['op', 2, 2, []],
        '/' => ['op', 2, 2, []],
        '%%' => ['op', 2, 2, []],
        '^' => ['op', 2, 2, []],
        'neg' => ['op', 1, 1, []],
        '==' => ['op', 2, 2, []],
        '!=' => ['op', 2, 2, []],
        '<' => ['op', 2, 2, []],
        '<=' => ['op', 2, 2, []],
        '>' => ['op', 2, 2, []],
        '>=' => ['op', 2, 2, []],
        '&' => ['op', 2, 2, []],
        '|' => ['op', 2, 2, []],
        '!' => ['op', 1, 1, []],

        // Scalar functions
        'is_na' => ['scalar', 1, 1, []],
        'if_else' => ['scalar', 3, 3, ['missing']],
        'case_when' => ['scalar', 2, null, ['default']],
        'coalesce' => ['scalar', 1, null, []],
        'in' => ['scalar', 2, 2, []],
        'between' => ['scalar', 3, 3, []],
        'na_if' => ['scalar', 2, 2, []],
        'near' => ['scalar', 2, 2, ['tol']],
        'abs' => ['scalar', 1, 1, []],
        'sqrt' => ['scalar', 1, 1, []],
        'exp' => ['scalar', 1, 1, []],
        'log' => ['scalar', 1, 1, ['base']],
        'log10' => ['scalar', 1, 1, []],
        'round' => ['scalar', 1, 1, ['digits']],
        'floor' => ['scalar', 1, 1, []],
        'ceiling' => ['scalar', 1, 1, []],
        'as_int' => ['scalar', 1, 1, []],
        'as_float' => ['scalar', 1, 1, []],
        'as_string' => ['scalar', 1, 1, []],
        'as_bool' => ['scalar', 1, 1, []],
        'str_lower' => ['scalar', 1, 1, []],
        'str_upper' => ['scalar', 1, 1, []],
        'str_length' => ['scalar', 1, 1, []],
        'str_trim' => ['scalar', 1, 1, ['side']],
        'str_sub' => ['scalar', 2, 3, []],
        'str_detect' => ['scalar', 2, 2, ['negate']],
        'str_starts' => ['scalar', 2, 2, ['negate']],
        'str_ends' => ['scalar', 2, 2, ['negate']],
        'str_replace' => ['scalar', 3, 3, []],
        'str_replace_all' => ['scalar', 3, 3, []],
        'paste0' => ['scalar', 1, null, []],
        'paste' => ['scalar', 1, null, ['sep']],

        // Aggregates
        'n' => ['agg', 0, 0, []],
        'sum' => ['agg', 1, 1, ['na_rm']],
        'mean' => ['agg', 1, 1, ['na_rm']],
        'median' => ['agg', 1, 1, ['na_rm']],
        'min' => ['agg', 1, 1, ['na_rm']],
        'max' => ['agg', 1, 1, ['na_rm']],
        'sd' => ['agg', 1, 1, ['na_rm']],
        'var' => ['agg', 1, 1, ['na_rm']],
        'quantile' => ['agg', 2, 2, ['na_rm']],
        'iqr' => ['agg', 1, 1, ['na_rm']],
        'n_distinct' => ['agg', 1, null, ['na_rm']],
        'first' => ['agg', 1, 1, ['default', 'order_by', 'na_rm']],
        'last' => ['agg', 1, 1, ['default', 'order_by', 'na_rm']],
        'nth' => ['agg', 2, 2, ['default', 'order_by', 'na_rm']],
        'any' => ['agg', 1, 1, ['na_rm']],
        'all' => ['agg', 1, 1, ['na_rm']],

        // Window functions
        'lag' => ['window', 1, 1, ['n', 'default', 'order_by']],
        'lead' => ['window', 1, 1, ['n', 'default', 'order_by']],
        'row_number' => ['window', 0, 1, []],
        'min_rank' => ['window', 1, 1, []],
        'dense_rank' => ['window', 1, 1, []],
        'percent_rank' => ['window', 1, 1, []],
        'cume_dist' => ['window', 1, 1, []],
        'ntile' => ['window', 0, 1, ['n']],
        'cumsum' => ['window', 1, 1, []],
        'cumprod' => ['window', 1, 1, []],
        'cummean' => ['window', 1, 1, []],
        'cummin' => ['window', 1, 1, []],
        'cummax' => ['window', 1, 1, []],
        'cumall' => ['window', 1, 1, []],
        'cumany' => ['window', 1, 1, []],
        'consecutive_id' => ['window', 1, null, []],
    ];

    /** Functions whose first argument may be a Desc (ranking direction). */
    public const array RANKING = ['row_number', 'min_rank', 'dense_rank', 'percent_rank', 'cume_dist', 'ntile'];

    public static function kind(string $fn): string
    {
        return self::REGISTRY[$fn][0] ?? throw new InvalidArgumentException("Unknown function '{$fn}'.");
    }

    /**
     * @param list<Expr> $args
     * @param array<string, Expr> $named
     */
    public static function check(string $fn, array $args, array $named): void
    {
        if (! isset(self::REGISTRY[$fn])) {
            throw new InvalidArgumentException("Unknown function '{$fn}'. Expressions may only call registered functions.");
        }
        [, $min, $max, $allowed] = self::REGISTRY[$fn];
        $count = count($args);
        if (! array_is_list($args) || $count < $min || ($max !== null && $count > $max)) {
            $expected = $max === null ? "at least {$min}" : ($min === $max ? (string) $min : "{$min} to {$max}");
            throw new InvalidArgumentException("{$fn}() takes {$expected} positional argument(s), got {$count}.");
        }
        foreach ($args as $i => $arg) {
            if (! $arg instanceof Expr) {
                throw new InvalidArgumentException('Argument '.($i + 1)." of {$fn}() is not an expression.");
            }
            if ($arg instanceof Desc && ! ($i === 0 && in_array($fn, self::RANKING, true))) {
                throw new InvalidArgumentException('desc() is only allowed in arrange(), ranking functions and order_by.');
            }
        }
        foreach ($named as $name => $arg) {
            if (! in_array($name, $allowed, true)) {
                throw new InvalidArgumentException("{$fn}() has no argument '{$name}'.".($allowed === [] ? '' : ' Allowed: '.implode(', ', $allowed).'.'));
            }
            if (! $arg instanceof Expr || ($arg instanceof Desc && $name !== 'order_by')) {
                throw new InvalidArgumentException("Argument '{$name}' of {$fn}() must be an expression.");
            }
        }
        if ($fn === 'case_when' && $count % 2 !== 0) {
            throw new InvalidArgumentException('case_when() takes condition/value pairs; pass the fallback as default.');
        }
        if ($fn === 'ntile' && ! isset($named['n'])) {
            throw new InvalidArgumentException('ntile() needs n.');
        }
    }
}
