<?php

declare(strict_types=1);

use Phibble\Column\Infer;
use Phibble\Frame;
use Phibble\Type;

it('combines PHP types the way R c() does', function () {
    $frame = Frame::fromColumns([
        'bi' => [true, 2],
        'if' => [1, 2.5],
        'sn' => ['a', 1],
        'dt' => [new DateTimeImmutable('2024-01-02 10:00'), null],
        'l' => [[1, 2], null],
        'ln' => [1, [2]],
    ]);

    expect($frame->types())->toBe([
        'bi' => Type::Int, 'if' => Type::Float, 'sn' => Type::String,
        'dt' => Type::DateTime, 'l' => Type::List, 'ln' => Type::List,
    ])
        ->and($frame->column('bi'))->toBe([1, 2])
        ->and($frame->column('sn'))->toBe(['a', '1']);
});

it('casts to int like as.integer()', function () {
    // as.integer(c(2.7, -2.7, "3.9", "x", TRUE)) is 2 -2 3 NA 1 in R.
    [$frame, $warnings] = quietly(fn () => Frame::fromColumns(['x' => [2.7, -2.7, '3.9', 'x', true]], types: ['x' => 'int']));

    expect($frame->column('x'))->toBe([2, -2, 3, null, 1])
        ->and($warnings)->toHaveCount(1);
});

it('casts to bool like as.logical()', function () {
    $text = ['TRUE', 'true', 'T', 'True', 'yes', '1', 'FALSE', 'F', 'false', 'False', '0'];
    [$frame] = quietly(fn () => Frame::fromColumns(['s' => $text, 'n' => array_fill(0, 11, 0)], types: ['s' => 'bool']));
    $numbers = Frame::fromColumns(['n' => [0, 2, -1.5]])->cast(['n' => 'bool']);

    expect($frame->column('s'))->toBe([true, true, true, true, null, null, false, false, false, false, null])
        ->and($numbers->column('n'))->toBe([false, true, true]);
});

it('formats doubles like as.character()', function () {
    $values = [1.0, 0.1 + 0.2, 1e15, 1e16, 123456.7, 1 / 3, 1e-5, 100000.0, 0.001, -2.5, INF, NAN];

    expect(array_map(Infer::formatFloat(...), $values))->toBe([
        '1', '0.3', '1e+15', '1e+16', '123456.7', '0.333333333333333', '1e-05', '1e+05', '0.001', '-2.5', 'Inf', 'NaN',
    ])
        ->and(Frame::fromColumns(['b' => [true, null]])->cast(['b' => 'string'])->column('b'))->toBe(['TRUE', null]);
});

it('converts dates and datetimes to numbers like R', function () {
    $frame = Frame::fromCsvString("d,t\n2024-01-02,2024-01-02 10:00:00\n")->cast(['d' => 'float', 't' => 'float']);

    expect($frame->column('d'))->toBe([19724.0])
        ->and($frame->column('t'))->toBe([1704189600.0]);
});

it('turns numbers into dates and datetimes like R', function () {
    $frame = Frame::fromColumns(['d' => [19000], 't' => [1704189600]], types: ['d' => 'date', 't' => 'datetime']);

    expect($frame->column('d')[0]->format('Y-m-d'))->toBe('2022-01-08')
        ->and($frame->column('t')[0]->format('Y-m-d H:i:s'))->toBe('2024-01-02 10:00:00');
});

it('converts factors like R', function () {
    // factor(c("10", "9", "100")) has levels "10" "100" "9"; as.integer() gives codes 1 3 2.
    $frame = Frame::fromColumns(['f' => ['10', '9', '100']], types: ['f' => 'factor']);

    expect($frame->levels('f'))->toBe(['10', '100', '9'])
        ->and($frame->cast(['f' => 'int'])->column('f'))->toBe([1, 3, 2])
        ->and($frame->cast(['f' => 'string'])->column('f'))->toBe(['10', '9', '100'])
        ->and(Frame::fromColumns(['f' => [1.5, 2.0, 10]])->asFactor('f')->levels('f'))->toBe(['1.5', '2', '10'])
        ->and(Frame::fromColumns(['f' => [true, false]])->asFactor('f')->levels('f'))->toBe(['FALSE', 'TRUE']);
});

it('keeps date and datetime apart when parsing text', function () {
    $frame = Frame::fromCsvString("d,t,m\n2024-01-02,2024-01-02T10:00:00Z,2024-01-02\n2024-03-04,2024-03-04 11:30,2024-03-04 08:00\n");

    expect($frame->types())->toBe(['d' => Type::Date, 't' => Type::DateTime, 'm' => Type::DateTime])
        ->and($frame->column('d')[0]->getTimezone()->getName())->toBe('UTC');
});

it('builds ordered factors', function () {
    $frame = Frame::fromColumns(['o' => ['lo', 'hi', 'mid']])->asOrdered('o', ['lo', 'mid', 'hi']);

    expect($frame->isOrdered('o'))->toBeTrue()
        ->and($frame->type('o'))->toBe(Type::Factor)
        ->and($frame->arrange(['o'])->column('o'))->toBe(['lo', 'mid', 'hi'])
        ->and($frame->asFactor('o')->isOrdered('o'))->toBeFalse();
});

it('matches forcats', function () {
    $f = Frame::fromColumns(['f' => ['b', 'a', 'c', 'a', 'b', 'a'], 'x' => [5, 1, 9, 2, 4, 3]])->asFactor('f');

    expect($f->fctRelevel('f', ['c'])->levels('f'))->toBe(['c', 'a', 'b'])
        ->and($f->fctRelevel('f', ['a'], after: 1)->levels('f'))->toBe(['b', 'a', 'c'])
        ->and($f->fctRelevel('f', ['a'], after: -1)->levels('f'))->toBe(['b', 'c', 'a'])
        ->and($f->fctRev('f')->levels('f'))->toBe(['c', 'b', 'a'])
        ->and($f->fctInorder('f')->levels('f'))->toBe(['b', 'a', 'c'])
        ->and($f->fctInfreq('f')->levels('f'))->toBe(['a', 'b', 'c'])
        ->and($f->fctReorder('f', 'x')->levels('f'))->toBe(['a', 'b', 'c'])
        ->and($f->fctReorder('f', 'x', descending: true)->levels('f'))->toBe(['c', 'b', 'a'])
        ->and($f->fctRecode('f', ['x' => 'a', 'y' => 'b'])->levels('f'))->toBe(['x', 'y', 'c'])
        ->and($f->fctCollapse('f', ['ab' => ['a', 'b']])->levels('f'))->toBe(['ab', 'c'])
        ->and($f->fctCollapse('f', ['ab' => ['a', 'b']])->column('f'))->toBe(['ab', 'ab', 'c', 'ab', 'ab', 'ab'])
        ->and($f->fctLevels('f', ['a', 'b', 'z'])->fctDrop()->levels('f'))->toBe(['a', 'b'])
        ->and($f->fctLevels('f', ['a', 'b', 'z'])->column('f'))->toBe(['b', 'a', null, 'a', 'b', 'a']);
});

it('stores list-columns', function () {
    $inner = Frame::fromColumns(['a' => [1, 2]]);
    $frame = Frame::fromColumns(['id' => [1, 2], 'data' => [$inner, [1, 2, 3]]]);

    expect($frame->type('data'))->toBe(Type::List)
        ->and($frame->column('data')[0])->toBe($inner)
        ->and($frame->distinct(['data'])->nrow())->toBe(2);
});
