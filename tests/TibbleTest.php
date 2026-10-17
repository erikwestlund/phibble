<?php

declare(strict_types=1);

use Phibble\Exception\ColumnNotFound;
use Phibble\DataFrame;
use Phibble\Type;

use function Phibble\as_tibble;
use function Phibble\enframe;
use function Phibble\tibble;
use function Phibble\tribble;

it('builds tibbles with recycling and computed columns', function () {
    $frame = tibble(x: [1, 2, 3], y: 'a', z: fn (DataFrame $t) => array_map(fn ($x) => $x * 2, $t['x']));

    expect($frame->toColumns())->toBe(['x' => [1, 2, 3], 'y' => ['a', 'a', 'a'], 'z' => [2, 4, 6]])
        ->and(tibble(x: 1, y: 'a')->nrow())->toBe(1)
        ->and(tibble(a: 1, b: [1, 2])->column('a'))->toBe([1, 1])
        ->and(tibble(a: 1, b: [])->nrow())->toBe(0);
});

it('rejects incompatible sizes with tibble\'s message', function () {
    tibble(x: [1, 2, 3], y: [1, 2]);
})->throws(InvalidArgumentException::class, "Tibble columns must have compatible sizes.\n• Size 3: Existing data.\n• Size 2: Column `y`.\nℹ Only values of size one are recycled.");

it('splices unnamed tables', function () {
    $frame = DataFrame::tibble(['x' => [1, 2], DataFrame::tibble(['a' => [3, 4], 'b' => [5, 6]])]);

    expect($frame->columns())->toBe(['x', 'a', 'b']);
});

it('builds tribbles row by row', function () {
    $rows = tribble(['x', 'y'], [1, 'a'], [2, 'b']);
    $flat = tribble(['~x', '~y'], 1, 'a', 2, 'b');

    expect($rows->toRows())->toBe([['x' => 1, 'y' => 'a'], ['x' => 2, 'y' => 'b']])
        ->and($flat->toRows())->toBe($rows->toRows())
        ->and(fn () => tribble(['x', 'y'], 1, 'a', 2))
        ->toThrow(InvalidArgumentException::class, "Data must be rectangular.\n• Found 2 columns.\n• Found 3 cells.\nℹ 3 is not an integer multiple of 2.");
});

it('converts many things with as_tibble', function () {
    $collection = new class
    {
        public function all(): array
        {
            return [['a' => 1], ['a' => 2]];
        }
    };

    expect(as_tibble([['a' => 1, 'b' => 'x']])->columns())->toBe(['a', 'b'])
        ->and(as_tibble(['a' => [1, 2]])->column('a'))->toBe([1, 2])
        ->and(as_tibble($collection)->column('a'))->toBe([1, 2])
        ->and(as_tibble(new ArrayIterator([(object) ['a' => 5]]))->column('a'))->toBe([5])
        ->and(as_tibble(penguins()))->toBeInstanceOf(DataFrame::class);
});

it('adds rows like add_row', function () {
    $frame = tibble(x: [1, 2, 3], y: ['a', 'b', 'c']);

    expect($frame->addRow(['x' => 9], before: 1)->toRows())->toBe([
        ['x' => 1, 'y' => 'a'], ['x' => 9, 'y' => null], ['x' => 2, 'y' => 'b'], ['x' => 3, 'y' => 'c'],
    ])
        ->and($frame->addRow()->column('x'))->toBe([1, 2, 3, null])
        ->and($frame->addRow([['x' => 4], ['x' => 5]], after: 0)->column('x'))->toBe([1, 4, 5, 2, 3])
        ->and($frame->addRow()->types())->toBe($frame->types())
        ->and(fn () => $frame->addRow(['z' => 1]))
        ->toThrow(InvalidArgumentException::class, "New rows can't add columns.");
});

it('keeps factor levels when adding rows', function () {
    $frame = tibble(g: ['b', 'a'])->fctLevels('g', ['b', 'a']);

    expect($frame->addRow(['g' => 'a'])->levels('g'))->toBe(['b', 'a'])
        ->and($frame->addRow(['g' => 'c'])->levels('g'))->toBe(['b', 'a', 'c']);
});

it('adds columns like add_column', function () {
    $frame = tibble(x: [1, 2, 3], y: ['a', 'b', 'c']);

    expect($frame->addColumn(['z' => 0], after: 'x')->columns())->toBe(['x', 'z', 'y'])
        ->and($frame->addColumn(['z' => 0], before: 0)->columns())->toBe(['z', 'x', 'y'])
        ->and($frame->addColumn(['z' => [7, 8, 9]])->column('z'))->toBe([7, 8, 9])
        ->and(fn () => $frame->addColumn(['x' => 1]))
        ->toThrow(InvalidArgumentException::class, 'Column name `x` must not be duplicated.');
});

it('enframes and deframes', function () {
    expect(enframe(['a' => 1.0, 'b' => 2.0])->toRows())->toBe([['name' => 'a', 'value' => 1.0], ['name' => 'b', 'value' => 2.0]])
        ->and(enframe([5.0, 6.0])->types())->toBe(['name' => Type::Int, 'value' => Type::Float])
        ->and(enframe([5.0, 6.0])->column('name'))->toBe([1, 2])
        ->and(tibble(n: ['a', 'b'], v: [1, 2])->deframe())->toBe(['a' => 1, 'b' => 2])
        ->and(tibble(v: [1, 2])->deframe())->toBe([1, 2]);
});

it('reads columns strictly by property and key', function () {
    $frame = tibble(price: [1.5, 2.5]);

    expect($frame->price)->toBe([1.5, 2.5])
        ->and($frame['price'])->toBe([1.5, 2.5])
        ->and(isset($frame->price))->toBeTrue()
        ->and(isset($frame->pri))->toBeFalse()
        ->and(fn () => $frame->pri)->toThrow(ColumnNotFound::class)
        ->and(fn () => $frame['price'] = [1])->toThrow(LogicException::class);
});

it('repairs names on request', function () {
    expect(fn () => DataFrame::tribble(['a', 'a'], 1, 2))->toThrow(InvalidArgumentException::class)
        ->and(as_tibble([['' => 1]], nameRepair: 'unique')->columns())->toBe(['...1']);
});
