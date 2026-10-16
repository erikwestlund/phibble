<?php

declare(strict_types=1);

use Phibble\Expr\Expr;
use Phibble\Expr\Lit;
use Phibble\Frame;
use Phibble\Sql\SqlTable;

use function Phibble\Expr\{as_int, col, desc, exp, first, log, max, mean, min, n, near, sqrt, str_trim, sum, var_};

/*
 * Regression tests for the independent review of SqlTable and Expr before
 * v0.1.0. Each runs a pipeline on SqlTable and on the same rows collected
 * into a Frame, and requires the same result (or the same exception).
 */
function viaBoth(PDO $pdo, string $sql, Closure $pipeline): array
{
    $table = SqlTable::query($pdo, $sql);
    // Documented limits: MySQL stores no Inf or NaN, SQLite no NaN; R's
    // values come back as NA there.
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $special = fn ($v) => match (true) {
        ! is_float($v) || is_finite($v) => $v,
        $driver === 'mysql', $driver === 'sqlite' && is_nan($v) => null,
        default => $v,
    };
    $run = function (Closure $source) use ($pipeline, $special) {
        try {
            [$out] = quietly(fn () => $pipeline($source())->collect()->ungroup()->toRows());

            return array_map(fn (array $r) => array_map(fn ($v) => is_float($v = $special($v)) && is_nan($v) ? 'NaN' : (is_float($v) ? \round($v, 9) : $v), $r), $out);
        } catch (Throwable $e) {
            return get_class($e);
        }
    };

    return [$run(fn () => $table->collect()), $run(fn () => $table)];
}

dataset('review cases', function () {
    $num = 'SELECT 1 AS i, 4 AS x UNION ALL SELECT 2, 0 UNION ALL SELECT 3, -4';

    return [
        'arrange twice keeps earlier order for ties' => ["SELECT 1 AS i, 'b' AS g UNION ALL SELECT 2, 'a' UNION ALL SELECT 3, 'a' UNION ALL SELECT 4, 'b'",
            fn ($t) => $t->arrange([desc('g')])->arrange([col('i')->mod(2)])],
        'mutate overwriting a sort key keeps the row order' => [$num, fn ($t) => $t->arrange(['x'])->mutate(['x' => col('x')->neg()])],
        'float literal against an integer column' => [$num, fn ($t) => $t->filter(col('x')->gt(1.5))->mutate(['h' => col('x')->mul(0.5)])],
        'near with a float tolerance' => [$num, fn ($t) => $t->filter(near(col('x'), 4))],
        'nested aggregate' => [$num, fn ($t) => $t->mutate(['d' => mean(col('x')->sub(mean(col('x'))))])],
        'sqrt and log outside the domain' => [$num, fn ($t) => $t->mutate(['r' => sqrt(col('x')), 'l' => log(col('x'))])],
        'exp overflow' => ['SELECT 1000.0 AS x', fn ($t) => $t->mutate(['e' => exp(col('x'))])],
        'division by zero' => [$num, fn ($t) => $t->mutate(['r' => col('i')->div(col('x'))])],
        'as_int truncates' => ['SELECT 2.7 AS y UNION ALL SELECT -2.7', fn ($t) => $t->mutate(['z' => as_int(col('y'))])],
        'slice with a negative offset' => [$num, fn ($t) => $t->slice(-1, 1)],
        'slice with a negative length' => [$num, fn ($t) => $t->slice(0, -1)],
        'summarise of a non-aggregate' => [$num, fn ($t) => $t->summarise(['y' => col('x')])],
        'min, max and mean of no values' => [$num, fn ($t) => $t->filter(col('x')->gt(99))->summarise(['m' => min(col('x')), 'mx' => max(col('x')), 'mu' => mean(col('x'))])],
        'str_trim of tabs and newlines' => ['SELECT \'\' AS s', fn ($t) => $t->mutate(['s' => Phibble\Expr\paste0("\t", 'z', "\n")])->mutate(['r' => str_trim(col('s'))])],
        'brace in a column name' => ['SELECT 1 AS "a{}b"', fn ($t) => $t->filter(col('a{}b')->gt(0))],
    ];
});

it('matches Frame after the review fixes', function (string $sql, Closure $pipeline, Closure $connect) {
    [$frame, $sqlResult] = viaBoth($connect(), $sql, $pipeline);

    expect($sqlResult)->toBe($frame);
})->with('review cases')->with('connections');

it('aggregates boolean columns', function (Closure $connect) {
    $pdo = $connect();
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
        ? 'SELECT TRUE AS b, 1 AS g UNION ALL SELECT FALSE, 1 UNION ALL SELECT TRUE, 2'
        : 'SELECT 1 AS b, 1 AS g UNION ALL SELECT 0, 1 UNION ALL SELECT 1, 2';
    [$frame, $sqlResult] = viaBoth($pdo, $sql, fn ($t) => $t->arrange(['g'])->groupBy(['g'])->summarise([
        's' => sum(col('b')), 'm' => mean(col('b')), 'lo' => min(col('b')), 'hi' => max(col('b')), 'f' => first(col('b')),
    ]));

    expect($sqlResult)->toBe($frame);
})->with('connections');

it('keeps text with trailing spaces apart and picks closest text in byte order', function (Closure $connect) {
    $pdo = $connect();
    $text = fn (string $v) => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "CAST('{$v}' AS CHAR)" : "'{$v}'";
    [$frame, $sqlResult] = viaBoth($pdo, "SELECT {$text('a')} AS s UNION ALL SELECT {$text('a ')}", fn ($t) => $t->groupBy(['s'])->summarise(['n' => n()]));
    expect($sqlResult)->toBe($frame);

    $x = SqlTable::query($pdo, "SELECT {$text('a')} AS s");
    $y = SqlTable::query($pdo, "SELECT {$text('B')} AS s2 UNION ALL SELECT {$text('a')}");
    $memory = $x->collect()->join($y->collect(), [['closest', 's', '>=', 's2']], 'left');
    expect($x->join($y, [['closest', 's', '>=', 's2']], 'left')->collect()->toRows())->toBe($memory->toRows());
})->with('connections');

it('does not break a PostgreSQL transaction when opening a view', function () {
    $dsn = getenv('PHIBBLE_TEST_PGSQL');
    if (! $dsn) {
        test()->markTestSkipped('PHIBBLE_TEST_PGSQL is not set.');
    }
    $pdo = new PDO($dsn, getenv('PHIBBLE_TEST_PGSQL_USER') ?: null, getenv('PHIBBLE_TEST_PGSQL_PASSWORD') ?: null);
    $pdo->beginTransaction();
    try {
        expect(SqlTable::table($pdo, 'information_schema.schemata')->nrow())->toBeGreaterThan(0)
            ->and((int) $pdo->query('SELECT 1')->fetchColumn())->toBe(1);
    } finally {
        $pdo->rollBack();
    }
});

it('parses named bindings around comments and MySQL escapes', function () {
    $pdo = new PDO('sqlite::memory:');
    expect(SqlTable::query($pdo, "SELECT :v AS v -- a comment with :w\n", ['v' => 1])->collect()->column('v'))->toBe([1])
        ->and(SqlTable::query($pdo, "SELECT /* :w */ :v AS v", ['v' => 2])->collect()->column('v'))->toBe([2]);

    $dsn = getenv('PHIBBLE_TEST_MYSQL');
    if ($dsn) {
        $mysql = new PDO($dsn, getenv('PHIBBLE_TEST_MYSQL_USER') ?: null, getenv('PHIBBLE_TEST_MYSQL_PASSWORD') ?: null);
        expect(SqlTable::query($mysql, "SELECT 'it\\'s :x' AS s, :v AS v", ['v' => 1])->collect()->toRows())
            ->toBe([['s' => "it's :x", 'v' => 1]]);
    }
});

it('compares strictly in in() and trims Unicode spaces, as R does', function () {
    $f = Frame::fromColumns(['s' => ['1.0', 'abc', "\u{00a0}x\u{3000}"]]);
    $out = $f->mutate(['in1' => col('s')->isIn(['1']), 'inT' => col('s')->isIn([true]), 't' => str_trim(col('s'))]);

    expect($out->column('in1'))->toBe([false, false, false])
        ->and($out->column('inT'))->toBe([false, false, false])
        ->and($out->column('t'))->toBe(['1.0', 'abc', 'x']);
});

it('keeps microseconds when serialising datetime literals', function () {
    $lit = new Lit(new DateTimeImmutable('2024-01-02 03:04:05.678', new DateTimeZone('UTC')));
    $back = Expr::fromArray(json_decode(json_encode($lit->toArray()), true));

    expect($back->value->format('H:i:s.u'))->toBe('03:04:05.678000');
});

it('ranks and orders factors by level, as dplyr does', function () {
    $f = Frame::fromColumns(['k' => ['lo', 'hi', 'mid'], 'v' => [1, 2, 3]])->asOrdered('k', ['lo', 'mid', 'hi']);
    $out = $f->mutate([
        'r' => Phibble\Expr\min_rank(col('k')),
        'rd' => Phibble\Expr\min_rank(desc(col('k'))),
        'rn' => Phibble\Expr\row_number(col('k')),
        'prev' => Phibble\Expr\lag(col('v'), order_by: col('k')),
    ])->summarise(['top' => Phibble\Expr\last(col('v'), order_by: col('k'))]);

    expect($f->mutate(['r' => Phibble\Expr\min_rank(col('k'))])->column('r'))->toBe([1, 3, 2])
        ->and($f->mutate(['rd' => Phibble\Expr\min_rank(desc(col('k')))])->column('rd'))->toBe([3, 1, 2])
        ->and($f->mutate(['prev' => Phibble\Expr\lag(col('v'), order_by: col('k'))])->column('prev'))->toBe([null, 3, 1])
        ->and($out->column('top'))->toBe([2]);
});

it('divides to a double, as R does', function () {
    $out = Frame::fromColumns(['x' => [1, 3]])->mutate(['s' => 4])->mutate(['q' => sum(col('x'))->div(col('s')), 'h' => col('x')->div(1)]);

    expect($out->column('q'))->toBe([1.0, 1.0])
        ->and($out->column('h'))->toBe([1.0, 3.0]);
});

it('gives NA for empty fixed patterns, as stringr does', function () {
    $out = Frame::fromColumns(['s' => ['abc']])->mutate([
        'c' => Phibble\Expr\str_count(col('s'), Phibble\Expr\fixed('')),
        'd' => Phibble\Expr\str_detect(col('s'), Phibble\Expr\fixed(''), negate: true),
        'r' => Phibble\Expr\str_replace_all(col('s'), Phibble\Expr\fixed(''), 'x'),
    ]);

    expect($out->toRows())->toBe([['s' => 'abc', 'c' => null, 'd' => null, 'r' => null]]);
});
