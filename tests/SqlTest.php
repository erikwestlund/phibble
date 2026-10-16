<?php

declare(strict_types=1);

use Phibble\Expr\Expr;
use Phibble\Frame;
use Phibble\Sql\SqlTable;
use Phibble\Type;

use function Phibble\Expr\{col, desc, lag, mean, n, sum};

/*
 * SqlTable runs against SQLite always. To also run against PostgreSQL and
 * MySQL, set PHIBBLE_TEST_PGSQL / PHIBBLE_TEST_MYSQL to a PDO DSN (and
 * PHIBBLE_TEST_*_USER / _PASSWORD). The tests only run SELECTs over inline
 * data; they never create tables.
 */
function sqlConnections(): array
{
    $connections = ['sqlite' => fn () => new PDO('sqlite::memory:')];
    foreach (['pgsql' => 'PHIBBLE_TEST_PGSQL', 'mysql' => 'PHIBBLE_TEST_MYSQL'] as $driver => $env) {
        $dsn = getenv($env);
        if ($dsn) {
            $connections[$driver] = fn () => new PDO($dsn, getenv($env.'_USER') ?: null, getenv($env.'_PASSWORD') ?: null);
        }
    }

    return $connections;
}

/** A lazy table holding the frame's rows as inline SELECT ... UNION ALL data. */
function sqlFrom(PDO $pdo, Frame $frame): SqlTable
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $castFor = fn (Type $type) => match ($type) {
        Type::Int => ['sqlite' => 'INTEGER', 'pgsql' => 'BIGINT', 'mysql' => 'SIGNED'][$driver],
        Type::Float => ['sqlite' => 'REAL', 'pgsql' => 'DOUBLE PRECISION', 'mysql' => 'DOUBLE'][$driver],
        Type::Bool => ['sqlite' => 'INTEGER', 'pgsql' => 'BOOLEAN', 'mysql' => 'SIGNED'][$driver],
        default => ['sqlite' => 'TEXT', 'pgsql' => 'TEXT', 'mysql' => 'CHAR'][$driver],
    };
    $quote = fn (string $n) => $driver === 'mysql' ? "`{$n}`" : "\"{$n}\"";
    $selects = [];
    $bindings = [];
    foreach ($frame->toRows() as $i => $row) {
        $items = [];
        foreach ($row as $name => $value) {
            $type = $frame->type($name);
            $items[] = 'CAST(? AS '.$castFor($type).')'.($i === 0 ? ' AS '.$quote($name) : '');
            $bindings[] = $type === Type::Bool && $value !== null && $driver !== 'pgsql' ? (int) $value : $value;
        }
        $selects[] = 'SELECT '.implode(', ', $items);
    }

    return SqlTable::query($pdo, implode(' UNION ALL ', $selects), $bindings);
}

dataset('connections', fn () => array_map(fn ($c) => [$c], sqlConnections()));

it('matches dplyr for mutate expressions in SQL', function (Closure $connect) {
    $pdo = $connect();
    $failures = [];
    foreach (mutateCases() as $case => [$frame, $expr]) {
        if ($case === 'round') {
            continue;
        }
        $table = sqlFrom($pdo, $frame())->arrange(['i']);
        $out = $table->mutate(['out' => $expr])->collect()->column('out');
        if (normalise($out) !== expected($case)) {
            $failures[$case] = ['got' => normalise($out), 'want' => expected($case), 'sql' => $table->mutate(['out' => $expr]) instanceof SqlTable];
        }
    }

    expect($failures)->toBe([]);
})->with('connections');

it('matches dplyr for grouped mutate and summarise in SQL', function (Closure $connect) {
    $pdo = $connect();
    $grouped = sqlFrom($pdo, exprFrame())->groupBy(['g'])->arrange(['i']);

    expect(normalise($grouped->mutate(['out' => col('x')->sub(mean(col('x'), na_rm: true))])->collect()->column('out')))->toBe(expected('grp_mean'))
        ->and(normalise($grouped->mutate(['out' => lag(col('i'))])->collect()->column('out')))->toBe(expected('grp_lag'))
        ->and(normalise($grouped->mutate(['out' => Phibble\Expr\row_number()])->collect()->column('out')))->toBe(expected('grp_row_number'))
        ->and(normalise($grouped->mutate(['out' => Phibble\Expr\cumsum(col('i'))])->collect()->column('out')))->toBe(expected('grp_cumsum'));

    $failures = [];
    foreach (summariseCases() as [$case, $expr]) {
        $out = sqlFrom($pdo, exprFrame())->groupBy(['g'])->summarise(['out' => $expr])->collect()->column('out');
        if (normalise($out) !== expected($case)) {
            $failures[$case] = ['got' => normalise($out), 'want' => expected($case)];
        }
    }
    expect($failures)->toBe([]);
})->with('connections');

it('runs verbs lazily and matches Frame', function (Closure $connect) {
    $pdo = $connect();
    $frame = exprFrame();
    $sql = sqlFrom($pdo, $frame);

    $pipeline = fn ($t) => $t->filter(col('x')->gt(1))->select(['i', 'x', 'g'])->rename(['id' => 'i'])->arrange([desc(col('x'))]);
    $lazy = $pipeline($sql);

    expect($lazy)->toBeInstanceOf(SqlTable::class)
        ->and($lazy->collect()->toRows())->toBe($pipeline($frame)->toRows())
        ->and($sql->nrow())->toBe(7)
        ->and($sql->arrange(['i'])->slice(2, 2)->collect()->column('i'))->toBe([3, 4])
        ->and($sql->arrange(['i'])->head(2)->collect()->column('i'))->toBe([1, 2])
        ->and($sql->distinct(['g'])->arrange(['g'])->collect()->column('g'))->toBe(['a', 'b', null])
        ->and($sql->filter(col('x')->gt(mean(col('x'), na_rm: true)))->arrange(['i'])->collect()->column('i'))->toBe([4, 6, 7])
        ->and($sql->groupBy(['g'])->filter(col('x')->gt(mean(col('x'), na_rm: true)))->arrange(['i'])->collect()->column('i'))->toBe([1, 4, 6]);
})->with('connections');

it('joins in SQL like Frame::join', function (Closure $connect) {
    $pdo = $connect();
    $x = Frame::fromColumns(['k' => [1, 2, null, 2], 'a' => ['x1', 'x2', 'x3', 'x4'], 'v' => [1, 2, 3, 4]]);
    $y = Frame::fromColumns(['k' => [2, 3, null, 2], 'b' => ['y1', 'y2', 'y3', 'y4'], 'v' => [11, 12, 13, 14]]);
    $sx = sqlFrom($pdo, $x);
    $sy = sqlFrom($pdo, $y);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sort = fn (array $rows) => (function () use ($rows) {
        usort($rows, fn ($p, $q) => [$p['a'] ?? 'zz', $p['b'] ?? 'zz'] <=> [$q['a'] ?? 'zz', $q['b'] ?? 'zz']);

        return $rows;
    })();

    foreach (['inner', 'left', 'right', 'full', 'semi', 'anti'] as $type) {
        [$memory] = quietly(fn () => $x->join($y, ['k'], $type, relationship: 'many-to-many'));
        [$lazy] = quietly(fn () => $sx->join($sy, ['k'], $type));
        if ($type !== 'full' || $driver === 'sqlite') {
            expect($lazy)->toBeInstanceOf(SqlTable::class);
        }
        expect($sort($lazy->collect()->toRows()))->toBe($sort($memory->toRows()), "{$type} join");
    }
    expect($sx->join($sy, ['k'], 'inner', naMatches: false)->nrow())->toBe(4)
        ->and($sx->join($sy, ['k'], 'inner', keep: true)->columns())->toBe(['k.x', 'a', 'v.x', 'k.y', 'b', 'v.y']);
})->with('connections');

it('falls back to memory for closures and untranslatable expressions', function () {
    $sql = sqlFrom(new PDO('sqlite::memory:'), exprFrame());

    expect($sql->filter(fn ($r) => $r->i > 5))->toBeInstanceOf(Frame::class)
        ->and($sql->filter(fn ($r) => $r->i > 5)->column('i'))->toBe([6, 7])
        ->and($sql->mutate(['r' => Phibble\Expr\round(col('x'))]))->toBeInstanceOf(Frame::class)
        ->and($sql->summarise(['n' => fn ($t) => $t->nrow()])->column('n'))->toBe([7]);
});

it('shows the query and keeps bindings in order', function () {
    $pdo = new PDO('sqlite::memory:');
    $t = SqlTable::query($pdo, 'SELECT :a AS x UNION ALL SELECT :b', ['b' => 2, 'a' => 1])
        ->filter(col('x')->gt(0))
        ->mutate(['y' => col('x')->add(10)]);

    expect($t->showQuery())->toBe('SELECT "x", ("x" + ?) AS "y" FROM (SELECT * FROM (SELECT ? AS x UNION ALL SELECT ?) AS q1 WHERE ("x" > ?)) AS q2')
        ->and($t->bindings())->toBe([10, 1, 2, 0])
        ->and($t->collect()->column('y'))->toBe([11, 12])
        ->and($t->groupBy(['x'])->summarise(['n' => n(), 's' => sum(col('y'))])->collect()->toRows())
        ->toBe([['x' => 1, 'n' => 1, 's' => 11], ['x' => 2, 'n' => 1, 's' => 12]]);
});

it('compiles dialect-specific SQL', function () {
    expect((new Phibble\Sql\MysqlDialect())->quote('a`b'))->toBe('`a``b`')
        ->and((new Phibble\Sql\PostgresDialect())->nullSafeEquals(Phibble\Sql\Fragment::raw('a'), Phibble\Sql\Fragment::raw('b'))->sql)
        ->toBe('(a IS NOT DISTINCT FROM b)');
});

it('opens tables and queries with tbl()', function () {
    $pdo = new PDO('sqlite::memory:');

    expect(Phibble\tbl($pdo, 'SELECT 1 AS a')->collect()->column('a'))->toBe([1])
        ->and(Phibble\tbl($pdo, 'sqlite_master')->columns())->toContain('name')
        ->and((string) Phibble\tbl($pdo, 'SELECT 1 AS a'))->toStartWith("# Source: SQL [SQLite]\n# A tibble: 1 × 1");
});

it('uses every aggregate inside grouped mutate like Frame', function (Closure $connect) {
    $pdo = $connect();
    $failures = [];
    foreach (summariseCases() as [$case, $expr]) {
        $expected = null;
        try {
            $expected = normalise(exprFrame()->groupBy(['g'])->arrange(['i'])->mutate(['out' => $expr])->column('out'));
        } catch (Throwable) {
            continue; // quantile/iqr with NA throw in memory too
        }
        $out = normalise(sqlFrom($pdo, exprFrame())->groupBy(['g'])->arrange(['i'])->mutate(['out' => $expr])->collect()->column('out'));
        if ($out !== $expected) {
            $failures[$case] = ['got' => $out, 'want' => $expected];
        }
    }

    expect($failures)->toBe([]);
})->with('connections');

it('keeps the row order when reading one column', function (Closure $connect) {
    $sql = sqlFrom($connect(), exprFrame())->arrange([desc(col('i'))]);

    expect($sql->column('g'))->toBe([null, 'b', 'a', 'b', 'a', 'b', 'a']);
})->with('connections');

it('matches dplyr join_by in SQL', function (Closure $connect) {
    $pdo = $connect();
    $failures = [];
    foreach (joinByCases() as $case => [$x, $y, $by, $type, $options]) {
        $expected = joinByExpected($case);
        $lazy = sqlFrom($pdo, $x())->join(sqlFrom($pdo, $y()), $by, $type, ...$options);
        $out = $lazy->collect();
        if ($out->columns() !== $expected['columns']) {
            $failures[$case] = ['columns' => $out->columns(), 'want' => $expected['columns']];

            continue;
        }
        // SQL row order is unspecified; compare sorted rows.
        $got = joinRows($expected['columns'], $out->toRows());
        $want = joinRows($expected['columns'], $expected['rows']);
        if ($got !== $want) {
            $failures[$case] = ['got' => $got, 'want' => $want, 'native' => $lazy instanceof SqlTable];
        }
    }

    expect($failures)->toBe([]);
})->with('connections');

it('translates first, last, nth and str_replace to SQL', function (Closure $connect) {
    $pdo = $connect();
    $frame = exprFrame()->groupBy(['g'])->arrange(['i']);
    $sql = sqlFrom($pdo, exprFrame())->groupBy(['g'])->arrange(['i']);
    $aggs = [
        'f' => Phibble\Expr\first(col('x')),
        'l' => Phibble\Expr\last(col('s')),
        'n2' => Phibble\Expr\nth(col('i'), 2),
        'nm2' => Phibble\Expr\nth(col('i'), -2),
        'lo' => Phibble\Expr\last(col('s'), order_by: col('x')),
    ];

    $mutated = $sql->mutate($aggs);
    $summary = $sql->summarise($aggs);

    expect($mutated)->toBeInstanceOf(SqlTable::class)
        ->and($summary)->toBeInstanceOf(SqlTable::class)
        ->and(normalise($mutated->collect()->column('nm2')))->toBe(normalise($frame->mutate($aggs)->column('nm2')));
    foreach (array_keys($aggs) as $name) {
        expect(normalise($summary->collect()->column($name)))->toBe(normalise($frame->summarise($aggs)->column($name)), $name)
            ->and(normalise($mutated->collect()->column($name)))->toBe(normalise($frame->mutate($aggs)->column($name)), $name);
    }

    $replaced = sqlFrom($pdo, exprFrame())->arrange(['i'])->mutate(['r' => Phibble\Expr\str_replace(col('s'), 'a', 'A')]);
    expect($replaced)->toBeInstanceOf(SqlTable::class)
        ->and($replaced->collect()->column('r'))->toBe(expected('str_replace'));
})->with('connections');
