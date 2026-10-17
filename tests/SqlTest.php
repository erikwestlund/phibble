<?php

declare(strict_types=1);

use Phibble\Expr\Expr;
use Phibble\DataFrame;
use Phibble\Sql\SqlTable;
use Phibble\Type;

use function Phibble\Expr\{col, desc, lag, mean, n, sum};


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

it('runs verbs lazily and matches DataFrame', function (Closure $connect) {
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

it('joins in SQL like DataFrame::join', function (Closure $connect) {
    $pdo = $connect();
    $x = DataFrame::fromColumns(['k' => [1, 2, null, 2], 'a' => ['x1', 'x2', 'x3', 'x4'], 'v' => [1, 2, 3, 4]]);
    $y = DataFrame::fromColumns(['k' => [2, 3, null, 2], 'b' => ['y1', 'y2', 'y3', 'y4'], 'v' => [11, 12, 13, 14]]);
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

    expect($sql->filter(fn ($r) => $r->i > 5))->toBeInstanceOf(DataFrame::class)
        ->and($sql->filter(fn ($r) => $r->i > 5)->column('i'))->toBe([6, 7])
        ->and($sql->mutate(['r' => Phibble\Expr\round(col('x'))]))->toBeInstanceOf(DataFrame::class)
        ->and($sql->summarise(['n' => fn ($t) => $t->nrow()])->column('n'))->toBe([7]);
});

it('shows the query and keeps bindings in order', function () {
    $pdo = new PDO('sqlite::memory:');
    $t = SqlTable::query($pdo, 'SELECT :a AS x UNION ALL SELECT :b', ['b' => 2, 'a' => 1])
        ->filter(col('x')->gt(0))
        ->mutate(['y' => col('x')->add(10)]);

    expect($t->showQuery())->toBe('SELECT "x", "y" FROM (SELECT "x", ("x" + ?) AS "y", "__phibble_row" FROM (SELECT * FROM (SELECT *, ROW_NUMBER() OVER () AS "__phibble_row" FROM (SELECT ? AS x UNION ALL SELECT ?) AS phibble_source) AS q1 WHERE ("x" > ?)) AS q2) AS q3 ORDER BY ("__phibble_row" IS NULL), "__phibble_row"')
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

it('uses every aggregate inside grouped mutate like DataFrame', function (Closure $connect) {
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

// Reported by phpolars: windows without arrange() must follow the source row
// order, also when a later entry falls back to memory.
it('runs windows in source row order without arrange', function (Closure $connect) {
    $pdo = $connect();
    $sales = DataFrame::fromColumns([
        'id' => [1, 2, 3, 4, 5, 6, 7, 8],
        'store' => [16, 3, 16, 3, 16, 3, 16, 3],
        'day' => [4, 2, 1, 1, 3, 4, 2, 3],
        'units' => [4, null, 2, 5, 7, 1, null, 3],
    ]);
    $pipeline = fn ($t) => $t->groupBy(['store'])->mutate([
        'prev' => lag(col('units'), order_by: col('day')),
        'running' => Phibble\Expr\cumsum(col('units')),
        'rn' => Phibble\Expr\row_number(),
        'r' => Phibble\Expr\round(col('units')), // falls back to memory
        'run2' => Phibble\Expr\cumsum(col('units')),
    ]);

    $memory = $pipeline($sales);
    $lazy = $pipeline(sqlFrom($pdo, $sales));

    expect($lazy->collect()->ungroup()->toRows())->toBe($memory->ungroup()->toRows());
})->with('connections');

it('keeps row order through select and distinct after arrange', function (Closure $connect) {
    $sql = sqlFrom($connect(), exprFrame());

    expect($sql->arrange([desc(col('x'))])->select(['g'])->collect()->column('g'))
        ->toBe(exprFrame()->arrange([desc(col('x'))])->select(['g'])->column('g'))
        ->and($sql->arrange([desc(col('i'))])->distinct(['g'])->collect()->column('g'))
        ->toBe(exprFrame()->arrange([desc(col('i'))])->distinct(['g'])->column('g'))
        ->and($sql->distinct(['g'])->collect()->column('g'))->toBe(['a', 'b', null]);
})->with('connections');

// The lubridate and stringr reference cases through SQL: translated or
// evaluated in memory, the results must match R.
it('matches lubridate and stringr in SQL', function (Closure $connect) {
    $pdo = $connect();
    $failures = [];
    $native = [];
    foreach (lubridateStringrCases() as $case => [$frame, $expr]) {
        $source = $frame();
        if ($source->columns() === ['v'] || in_array($case, ['force_tz', 'with_tz', 'floor_week'], true)) {
            continue; // text-to-date parsing and time zones are covered in memory
        }
        $lazy = sqlFrom($pdo, $source->mutate(['i' => range(1, $source->nrow())]))->arrange(['i'])->mutate(['out' => $expr]);
        [$got] = quietly(fn () => $lazy->collect()->column('out'));
        if ($lazy instanceof SqlTable) {
            $native[] = $case;
        }
        if (lsFormat($got) !== lsFormat(lsExpected($case))) {
            $failures[$case] = ['got' => lsFormat($got), 'want' => lsExpected($case), 'native' => $lazy instanceof SqlTable];
        }
    }

    expect($failures)->toBe([])
        ->and($native)->toContain('year', 'month', 'wday', 'difftime_hours', 'str_count_fixed', 'str_trunc');
})->with('connections');

// Stored tables use the physical row id (SQLite rowid, PostgreSQL ctid) as
// the source order. The PostgreSQL table is TEMP and disappears with the session.
it('keeps source order on stored tables with physical row ids', function (Closure $connect) {
    $pdo = $connect();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        test()->markTestSkipped('MySQL tables use ROW_NUMBER(), covered by the other tests.');
    }
    $suffix = substr(md5((string) mt_rand()), 0, 6);
    $temp = $driver === 'pgsql' ? 'TEMP ' : '';
    $pdo->exec("CREATE {$temp}TABLE ev_{$suffix} (g TEXT, x INTEGER)");
    $pdo->exec("CREATE {$temp}TABLE lk_{$suffix} (g TEXT, w INTEGER)");
    $rows = [['b', 5], ['a', 3], ['b', null], ['a', 1], ['c', 9], ['a', 4]];
    foreach ($rows as [$g, $x]) {
        $pdo->prepare("INSERT INTO ev_{$suffix} VALUES (?, ?)")->execute([$g, $x]);
    }
    foreach ([['a', 10], ['b', 20], ['a', 30]] as [$g, $w]) {
        $pdo->prepare("INSERT INTO lk_{$suffix} VALUES (?, ?)")->execute([$g, $w]);
    }
    $frame = DataFrame::fromColumns(['g' => array_column($rows, 0), 'x' => array_column($rows, 1)]);
    $lookup = DataFrame::fromColumns(['g' => ['a', 'b', 'a'], 'w' => [10, 20, 30]]);
    $sql = SqlTable::table($pdo, "ev_{$suffix}");
    $sqlLookup = SqlTable::table($pdo, "lk_{$suffix}");

    $pipeline = fn ($t) => $t->groupBy(['g'])->mutate(['run' => Phibble\Expr\cumsum(col('x')), 'rn' => Phibble\Expr\row_number()]);
    [$memoryJoin] = quietly(fn () => $frame->join($lookup, ['g'], 'left'));
    [$sqlJoin] = quietly(fn () => $sql->join($sqlLookup, ['g'], 'left'));

    expect($sql->showQuery())->toContain($driver === 'pgsql' ? 'ctid' : 'rowid')
        ->and($sql->collect()->toRows())->toBe($frame->toRows())
        ->and($pipeline($sql)->collect()->ungroup()->toRows())->toBe($pipeline($frame)->ungroup()->toRows())
        ->and($sql->distinct(['g'])->collect()->column('g'))->toBe(['b', 'a', 'c'])
        ->and($sqlJoin->collect()->toRows())->toBe($memoryJoin->toRows());
})->with('connections');

// R compares strings in C (byte) order and case-sensitively. PostgreSQL's
// locale collations and MySQL's default (case-insensitive) collation don't.
it('compares, sorts, groups and joins text in byte order like R', function (Closure $connect) {
    $pdo = $connect();
    $frame = DataFrame::fromColumns(['s' => ['b', 'B', 'a', 'A', '  pad', 'b'], 'v' => [1, 2, 3, 4, 5, 6]]);
    $sql = sqlFrom($pdo, $frame);
    $other = DataFrame::fromColumns(['s' => ['a', 'B'], 'w' => [10, 20]]);

    expect($sql->arrange(['s', 'v'])->collect()->column('s'))->toBe($frame->arrange(['s', 'v'])->column('s'))
        ->and($sql->filter(col('s')->eq('a'))->collect()->column('v'))->toBe([3])
        ->and($sql->filter(col('s')->lt('a'))->arrange(['v'])->collect()->column('v'))->toBe([2, 4, 5])
        ->and($sql->groupBy(['s'])->summarise(['n' => n()])->collect()->toRows())
        ->toBe($frame->groupBy(['s'])->summarise(['n' => n()])->toRows())
        ->and($sql->summarise(['m' => Phibble\Expr\max(col('s')), 'lo' => Phibble\Expr\min(col('s'))])->collect()->toRows())
        ->toBe([['m' => 'b', 'lo' => '  pad']])
        ->and($sql->distinct(['s'])->collect()->column('s'))->toBe(['b', 'B', 'a', 'A', '  pad'])
        ->and($sql->join(sqlFrom($pdo, $other), ['s'], 'inner')->arrange(['v'])->collect()->column('v'))->toBe([2, 3])
        ->and($sql->groupBy(['s'])->mutate(['k' => n()])->arrange(['v'])->collect()->column('k'))->toBe([2, 1, 1, 1, 1, 2]);
})->with('connections');
