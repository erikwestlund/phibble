<?php

declare(strict_types=1);

use Phibble\CalendarDate;
use Phibble\Column\ArrayColumn;
use Phibble\Frame;
use Phibble\Type;

use function Phibble\Expr\{col, first, if_else, lag, max, min, n};

/*
 * Regression tests for the independent review of the core and IO code
 * before v0.1.0. Expected values were checked against R (dplyr 1.2.1,
 * vctrs, tidyr 1.3.2, readr 2.2).
 */

it('1: joins floats exactly, still matching 1 with 1.0', function () {
    $x = Frame::fromColumns(['k' => [0.1 + 0.2, 1e17], 'a' => [1, 2]]);
    $y = Frame::fromColumns(['k' => [0.3, 1e17 + 16], 'b' => [3, 4]]);

    expect($x->join($y, ['k'])->nrow())->toBe(0)
        ->and(Frame::fromColumns(['k' => [1]])->join(Frame::fromColumns(['k' => [1.0], 'w' => [5]]), ['k'])->column('w'))->toBe([5]);
});

it('2: concat combines factor levels and ordered factors like vctrs', function () {
    $a = Frame::fromColumns(['f' => ['b']])->asFactor('f', ['b', 'a']);
    $b = Frame::fromColumns(['f' => ['c']])->asFactor('f', ['c']);
    $o1 = Frame::fromColumns(['f' => ['lo']])->asOrdered('f', ['lo', 'hi']);
    $o2 = Frame::fromColumns(['f' => ['hi']])->asOrdered('f', ['lo', 'hi']);

    expect(Frame::concat([$a, $b])->levels('f'))->toBe(['b', 'a', 'c'])
        ->and(Frame::concat([$o1, $o2])->isOrdered('f'))->toBeTrue()
        ->and(Frame::concat([$a, Frame::fromColumns(['f' => ['z']])])->type('f'))->toBe(Type::String)
        ->and(fn () => Frame::concat([$o1, Frame::fromColumns(['f' => ['x']])->asOrdered('f', ['x'])]))
        ->toThrow(InvalidArgumentException::class, "Can't combine ordered factors");
});

it('3: expressions that pass a factor through keep it a factor', function () {
    $f = Frame::fromColumns(['f' => ['b', 'a']])->asFactor('f', ['b', 'a']);
    $out = $f->mutate(['f2' => col('f'), 'l' => lag(col('f')), 'i' => if_else(col('f')->eq('a'), col('f'), col('f'))]);

    expect($out->type('f2'))->toBe(Type::Factor)
        ->and($out->levels('l'))->toBe(['b', 'a'])
        ->and($out->type('i'))->toBe(Type::Factor)
        ->and($f->summarise(['f1' => first(col('f'))])->type('f1'))->toBe(Type::Factor)
        ->and($f->mutate(['u' => Phibble\Expr\str_to_upper(col('f'))])->type('u'))->toBe(Type::String);
});

it('4: arranges integers above 2^53 exactly', function () {
    expect(Frame::fromColumns(['x' => [9007199254740993, 9007199254740992]])->arrange(['x'])->column('x'))
        ->toBe([9007199254740992, 9007199254740993]);
});

it('5: sorts and groups NaN after numbers and before NA', function () {
    $n = Frame::fromColumns(['x' => [3.0, NAN, 1.0, null, 2.0]]);
    $show = fn (array $v) => array_map(fn ($x) => is_float($x) && is_nan($x) ? 'NaN' : $x, $v);
    [$counts, $warnings] = quietly(fn () => $n->groupBy(['x'])->summarise(['n' => n()]));

    expect($show($n->arrange(['x'])->column('x')))->toBe([1.0, 2.0, 3.0, 'NaN', null])
        ->and($show($n->arrange([['x', 'desc']])->column('x')))->toBe([3.0, 2.0, 1.0, 'NaN', null])
        ->and($show($counts->column('x')))->toBe([1.0, 2.0, 3.0, 'NaN', null])
        ->and($warnings)->toBe([]);
});

it('6: keeps years 0 to 100 as written', function () {
    $f = Frame::fromCsvString("d,t\n0050-06-15,0050-06-15 12:00:00\n0099-01-01,0099-01-01 00:00:00\n");

    expect(array_map(fn ($v) => $v->format('Y-m-d'), $f->column('d')))->toBe(['0050-06-15', '0099-01-01'])
        ->and(array_map(fn ($v) => $v->format('Y-m-d H:i'), $f->column('t')))->toBe(['0050-06-15 12:00', '0099-01-01 00:00'])
        ->and(Frame::fromColumns(['d' => [new CalendarDate('0050-06-15')]])->column('d')[0]->format('Y-m-d'))->toBe('0050-06-15');
});

it('7: min and max of a column with NaN are NaN, whatever the order', function () {
    foreach ([[1.0, NAN, 0.5], [NAN, 1.0]] as $x) {
        $out = Frame::fromColumns(['x' => $x])->summarise(['m' => min(col('x')), 'M' => max(col('x'))]);
        expect(is_nan($out->column('m')[0]))->toBeTrue()
            ->and(is_nan($out->column('M')[0]))->toBeTrue();
    }
});

it('8: unnest with keepEmpty keeps factor columns', function () {
    $in = Frame::fromColumns(['v' => ['a', 'b']])->asFactor('v', ['b', 'a']);
    $f = Frame::fromColumns(['id' => [1, 2], 'data' => new ArrayColumn(Type::List, [$in, null])]);
    $out = $f->unnest('data', keepEmpty: true);

    expect($out->type('v'))->toBe(Type::Factor)
        ->and($out->levels('v'))->toBe(['b', 'a'])
        ->and($out->column('v'))->toBe(['a', 'b', null]);
});

it('9: pivotLongerSpec combines a factor and text into text', function () {
    $out = Frame::fromColumns(['a' => ['x'], 'b' => ['y']])->asFactor('a')
        ->pivotLongerSpec([['.name' => 'a', '.value' => 'value', 'name' => 'a'], ['.name' => 'b', '.value' => 'value', 'name' => 'b']]);

    expect($out->type('value'))->toBe(Type::String)
        ->and($out->column('value'))->toBe(['x', 'y']);
});

it('10: casts negative seconds to datetimes and back exactly', function () {
    $d = Frame::fromColumns(['t' => [-1.5]])->cast(['t' => 'datetime']);

    expect($d->column('t')[0]->format('Y-m-d H:i:s.u'))->toBe('1969-12-31 23:59:58.500000')
        ->and($d->cast(['t' => 'float'])->column('t'))->toBe([-1.5]);
});

it('11: join keys take the common type and the grouping keeps drop', function () {
    $x = Frame::fromColumns(['k' => ['a', 'b'], 'v' => [1, 2]])->asFactor('k');
    $y = Frame::fromColumns(['k' => ['b', 'c'], 'w' => [3, 4]]);
    $gx = Frame::fromColumns(['k' => ['a'], 'f' => ['u']])->asFactor('f', ['u', 'v'])->groupBy(['f'], drop: false);

    expect($x->join($y, ['k'], 'full')->type('k'))->toBe(Type::String)
        ->and($x->join($y, ['k'], 'inner')->type('k'))->toBe(Type::String)
        ->and(Frame::fromColumns(['k' => [1, 2]])->join(Frame::fromColumns(['k' => [1.0, 3.5]]), ['k'])->type('k'))->toBe(Type::Float)
        ->and($gx->join(Frame::fromColumns(['k' => ['a']]), ['k'])->ngroups())->toBe(2);
});

it('12: multi-column keys and NA never collide with other values', function () {
    $g = Frame::fromColumns(['a' => ['x'], 'b' => ["y\x1Fs:z"]]);
    $h = Frame::fromColumns(['a' => ["x\x1Fs:y"], 'b' => ['z']]);

    expect(Frame::fromColumns(['a' => ["x\x1Fy", 'x'], 'b' => ['z', "y\x1Fz"]])->groupBy(['a', 'b'])->ngroups())->toBe(2)
        ->and($g->join($h, ['a', 'b'])->nrow())->toBe(0)
        ->and(Frame::fromColumns(['a' => ["\0NA", null]])->groupBy(['a'])->ngroups())->toBe(2);
});

it('13: reads an empty integer field without a PHP warning', function () {
    [$f, $warnings] = quietly(fn () => Frame::fromCsvString("x,y\n1,\n2,3\n", types: 'ii', na: []));

    expect($f->column('y'))->toBe([null, 3])
        ->and(array_filter($warnings, fn ($w) => str_contains($w, 'offset')))->toBe([]);
});

it('14: slices without building every row index', function () {
    $f = Frame::fromColumns(['id' => range(1, 200000)]);
    $start = hrtime(true);
    for ($i = 0; $i < 2000; $i++) {
        $f->slice($i * 50, 1);
    }

    expect((hrtime(true) - $start) / 1e9)->toBeLessThan(0.5)
        ->and($f->slice(5, 3)->column('id'))->toBe([6, 7, 8])
        ->and($f->slice(-2)->column('id'))->toBe([199999, 200000]);
});

it('15: head and tail with a negative n drop rows, as in R', function () {
    $g = Frame::fromColumns(['x' => [1, 2, 3]]);

    expect($g->head(-1)->column('x'))->toBe([1, 2])
        ->and($g->tail(-1)->column('x'))->toBe([2, 3])
        ->and($g->head(-5)->nrow())->toBe(0);
});

it('16: bindCols keeps the first table\'s grouping', function () {
    $g = Frame::fromColumns(['g' => ['b', 'a', 'b'], 'x' => [1, 2, 3]])->groupBy(['g']);

    expect(Frame::bindCols([$g, Frame::fromColumns(['z' => [1, 2, 3]])])->groups())->toBe(['g']);
});

it('17: distinct treats -0.0 as 0.0', function () {
    expect(Frame::fromColumns(['x' => [0.0, -0.0]])->distinct()->nrow())->toBe(1);
});

it('18: keeps types R keeps', function () {
    $time = Frame::fromColumns(['t' => [new DateTimeImmutable('2024-01-01 10:00:00.5', new DateTimeZone('UTC'))]]);

    expect(Frame::tibble(['x' => [], 'y' => 1.5])->type('y'))->toBe(Type::Float)
        ->and(Frame::fromCsvString("x,y\n")->types())->toBe(['x' => Type::String, 'y' => Type::String])
        ->and($time->cast(['t' => 'string'])->column('t'))->toBe(['2024-01-01 10:00:00.5'])
        ->and(Frame::fromColumns(['d' => [new CalendarDate('1970-01-02'), 5]])->type('d'))->toBe(Type::Date)
        ->and(Frame::fromColumns(['d' => [new CalendarDate('1970-01-02'), 5]])->column('d')[1]->format('Y-m-d'))->toBe('1970-01-06');
});

it('19: skips whitespace-only lines, as readr does', function () {
    [$f, $warnings] = quietly(fn () => Frame::fromCsvString("x,y\na,1\n   \nb,2\n"));

    expect($f->column('x'))->toBe(['a', 'b'])
        ->and($warnings)->toBe([]);
});

it('20: reads JSON records and columns with numeric keys', function () {
    $frame = Frame::fromColumns(['0' => [1, 2], 'a' => ['x', 'y']]);

    expect(Frame::fromJson($frame->toJson())->toColumns())->toBe($frame->toColumns())
        ->and(Frame::fromJson($frame->toJson('columns'))->toColumns())->toBe($frame->toColumns());
});

it('21: formats tiny doubles without a PHP notice', function () {
    [$f, $warnings] = quietly(fn () => Frame::fromColumns(['x' => [1e-60]])->cast(['x' => 'string']));

    expect($f->column('x'))->toBe(['1e-60'])
        ->and($warnings)->toBe([]);
});

it('keeps drop: false through addRow and concat, and unnest of a rowwise table is plain', function () {
    $g = Frame::fromColumns(['f' => ['u']])->asFactor('f', ['u', 'v'])->groupBy(['f'], drop: false);
    $r = Frame::fromColumns(['id' => [1], 'd' => new ArrayColumn(Type::List, [Frame::fromColumns(['v' => [1, 2]])])])->rowwise();

    expect($g->addRow(['f' => 'u'])->dropsEmptyGroups())->toBeFalse()
        ->and(Frame::concat([$g, $g])->dropsEmptyGroups())->toBeFalse()
        ->and($r->unnest('d')->isRowwise())->toBeFalse()
        ->and($r->unnest('d')->groups())->toBe([]);
});
