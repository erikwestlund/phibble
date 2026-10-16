<?php

declare(strict_types=1);

use Phibble\Frame;
use Phibble\Io\CsvWriter;
use Phibble\NameRepair;
use Phibble\Type;

require_once __DIR__.'/Support/IlluminateCollection.php';
require_once __DIR__.'/Support/NativeCsvEmulator.php';

const IO_FIXTURES = __DIR__.'/fixtures/io';

/** @return array<string, array{0: array<string, mixed>}> */
function readrCases(): array
{
    $cases = json_decode((string) file_get_contents(IO_FIXTURES.'/cases.json'), true);

    return array_combine(array_column($cases, 'name'), array_map(fn ($c) => [$c], $cases));
}

/** A value as the R script wrote it, for comparison. */
function asExpected(mixed $v, Type $type): mixed
{
    return match (true) {
        $v === null => null,
        $type === Type::Date => $v->format('Y-m-d'),
        $type === Type::DateTime => $v->format('Y-m-d\TH:i:s.u\Z'),
        is_float($v) && is_nan($v) => 'NaN',
        is_float($v) && is_infinite($v) => $v > 0 ? 'Inf' : '-Inf',
        default => $v,
    };
}

// ---------------------------------------------------------------------
// Reading, checked against readr 2.x (tests/r/io.R writes the expectations)
// ---------------------------------------------------------------------

/*
 * The readr cases run against each CSV reader: the PHP reader, the native
 * reader path with a PHP emulation of stats_read_csv(), and the php-stats
 * extension's stats_read_csv() when it is loaded.
 */
dataset('csv readers', [
    'php' => ['php'],
    'native (emulated)' => ['emulated'],
    'native (php-stats)' => ['extension'],
]);

function withCsvReader(string $reader, Closure $fn): mixed
{
    if ($reader === 'extension' && ! function_exists('stats_read_csv')) {
        test()->markTestSkipped('stats_read_csv() is not available (php-stats extension not loaded).');
    }
    Phibble\Io\NativeCsv::enable($reader !== 'php');
    Phibble\Io\NativeCsv::using($reader === 'emulated' ? emulatedStatsReadCsv(...) : null);
    try {
        return $fn();
    } finally {
        Phibble\Io\NativeCsv::enable(true);
        Phibble\Io\NativeCsv::using(null);
    }
}

it('reads CSV like readr', function (array $case, string $reader) {
    $expected = json_decode((string) file_get_contents(IO_FIXTURES."/expected/{$case['name']}.json"), true);
    [$frame, $warnings] = withCsvReader($reader, fn () => quietly(fn () => Frame::fromCsv(IO_FIXTURES.'/'.$case['file'], ...$case['php'])));

    expect($frame->columns())->toBe($expected['names'])
        ->and(count($warnings) > 0)->toBe($expected['problems'] > 0);

    foreach ($expected['names'] as $name) {
        $type = $frame->type($name);
        $rType = $expected['types'][$name];
        $values = array_map(fn ($v) => asExpected($v, $type), $frame->column($name));
        $want = $expected['values'][$name];

        if ($rType === 'float' && $type === Type::Int) {
            // phibble's one deliberate difference: whole numbers guess as int.
            expect(array_map(fn ($v) => $v === null ? null : (float) $v, $values))
                ->toBe(array_map(fn ($v) => $v === null ? null : (float) $v, $want), "column {$name}");

            continue;
        }

        expect($type->value)->toBe($rType, "type of column {$name}");
        if ($rType === 'float') {
            $values = array_map(fn ($v) => is_float($v) ? $v : $v, $values);
            foreach ($want as $i => $w) {
                if (is_string($w) && is_numeric($w)) {
                    expect($values[$i])->toBe((float) $w, "{$name}[{$i}]");
                } else {
                    expect($values[$i])->toBe($w, "{$name}[{$i}]");
                }
            }
        } else {
            expect($values)->toBe($want, "values of column {$name}");
        }
        if ($rType === 'factor') {
            expect($frame->levels($name))->toBe($expected['levels'][$name]);
        }
    }
})->with(readrCases())->with('csv readers');

it('writes CSV like readr', function () {
    $expected = json_decode((string) file_get_contents(IO_FIXTURES.'/expected/write.json'), true);
    $frame = Frame::fromCsv(IO_FIXTURES.'/write.csv');

    expect($frame->toCsv())->toBe($expected['csv'])
        ->and($frame->toCsv(quote: 'all'))->toBe($expected['csv_all'])
        ->and($frame->toCsv(na: ''))->toBe($expected['csv_na']);
});

it('formats doubles like write_csv', function () {
    // Expected strings are readr::format_csv() output.
    foreach ([[1e5, '100000'], [1e15, '1e15'], [1.5e15, '15e14'], [1.5e14, '150000000000000'],
        [0.1, '0.1'], [0.001, '0.001'], [0.0005, '5e-4'], [1.2e-4, '1.2e-4'], [1e-5, '1e-5'],
        [1234567e9, '1234567e9'], [1e21, '1e21'], [-1e15, '-1e15'], [4.35, '4.35'],
        [0.1 + 0.2, '0.30000000000000004'], [2 ** 53, '9007199254740992'], [5e-324, '5e-324'],
        [1.2345678901234569e19, '1234567890123457e4'], [-0.001, '-0.001'], [0.0, '0'],
        [INF, 'Inf'], [-INF, '-Inf'], [NAN, null]] as [$x, $text]) {
        expect(CsvWriter::formatDouble($x))->toBe($text, var_export($x, true));
    }
});

// ---------------------------------------------------------------------
// Reading details that R fixtures don't cover
// ---------------------------------------------------------------------

it('keeps the original fromCsv and fromCsvString signatures working', function () {
    $frame = Frame::fromCsvString("zip;n\n02134;-\n10001;4\n", ['zip' => 'string'], ';', ['-']);

    expect($frame->column('zip'))->toBe(['02134', '10001'])
        ->and($frame->column('n'))->toBe([null, 4]);
});

it('reads from streams and gzip files', function () {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, "a,b\n1,x\n");
    rewind($stream);
    $fromStream = Frame::fromCsv($stream);
    fclose($stream);

    $path = tempnam(sys_get_temp_dir(), 'phibble').'.csv.gz';
    file_put_contents($path, gzencode("a,b\n1,x\n2,y\n"));
    $fromGzip = Frame::fromCsv($path);
    unlink($path);

    expect($fromStream->toRows())->toBe([['a' => 1, 'b' => 'x']])
        ->and($fromGzip->nrow())->toBe(2);
});

it('drops trailing comments and trims what is left', function () {
    // readr leaves "2 " here and so reads y as text; phibble trims after the comment.
    $frame = Frame::fromCsvString("x,y\n1,2 # trailing\n3,4\n", comment: '#');

    expect($frame->column('y'))->toBe([2, 4]);
});

it('honours backslash escapes and unquoted files', function () {
    $escaped = Frame::fromCsvString("a,b\n\"say \\\"hi\\\"\",2\n", escapeBackslash: true, escapeDouble: false);
    $unquoted = Frame::fromCsvString("a,b\n\"x\",1\n", quote: '');

    expect($escaped->column('a'))->toBe(['say "hi"'])
        ->and($unquoted->column('a'))->toBe(['"x"']);
});

it('reads values with grouping and decimal marks', function () {
    $frame = Frame::fromCsvString("a;b\n1.234,5;7\n", delimiter: ';', decimalMark: ',');

    expect($frame->column('a'))->toBe([1234.5]);
});

it('accepts col_types as name specs, positions and factor levels', function () {
    $csv = "g,x,y\nb,1,2\na,3,4\n";

    $named = Frame::fromCsvString($csv, ['g' => ['factor', 'levels' => ['b', 'a'], 'ordered' => true], 'x' => 'double']);
    $positional = Frame::fromCsvString($csv, ['f', 'skip', Type::Float]);

    expect($named->levels('g'))->toBe(['b', 'a'])
        ->and($named->isOrdered('g'))->toBeTrue()
        ->and($named->column('x'))->toBe([1.0, 3.0])
        ->and($named->type('y'))->toBe(Type::Int)
        ->and($positional->columns())->toBe(['g', 'y'])
        ->and($positional->column('y'))->toBe([2.0, 4.0]);
});

it('rejects col_types that do not fit the data', function () {
    Frame::fromCsvString("a,b\n1,2\n", 'iii');
})->throws(InvalidArgumentException::class, '3 letters; the data has 2 columns');

it('rejects col_select names that do not exist', function () {
    Frame::fromCsvString("a,b\n1,2\n", select: ['c']);
})->throws(InvalidArgumentException::class, "unknown column 'c'");

it('reads empty input and header-only files', function () {
    $empty = Frame::fromCsvString('');
    $header = Frame::fromCsvString("a,b\n");

    expect($empty->ncol())->toBe(0)
        ->and($header->columns())->toBe(['a', 'b'])
        ->and($header->nrow())->toBe(0)
        ->and($header->type('a'))->toBe(Type::Bool);
});

it('repairs names like vctrs', function () {
    $names = ['a', 'a', '', 'b...5', 'a...1', null, '..3', '1x', '_y', 'if', 'a b', 'TRUE', '.', '..', '...', '.2a', 'é', 'x-y', 'NULL', '...1'];

    expect(NameRepair::repair($names, 'unique'))->toBe([
        'a...1', 'a...2', '...3', 'b', 'a...5', '...6', '...7', '1x', '_y', 'if', 'a b', 'TRUE', '.', '..', '...15', '.2a', 'é', 'x-y', 'NULL', '...20',
    ])
        ->and(NameRepair::repair($names, 'universal'))->toBe([
            'a...1', 'a...2', '...3', 'b', 'a...5', '...6', '...7', '..1x', '._y', '.if', 'a.b', '.TRUE', '.', '..', '...15', '..2a', 'é', 'x.y', '.NULL', '...20',
        ])
        ->and(NameRepair::repair(['a b', 'a.b'], 'universal'))->toBe(['a.b...1', 'a.b...2'])
        ->and(NameRepair::repair(['9', '.9', '_', '$', 'Inf', 'in', 'ab_', '...'], 'universal'))
        ->toBe(['...9...1', '...9...2', '._', '.', '.Inf', '.in', 'ab_', '...8'])
        ->and(NameRepair::repair(['a...1...2', 'x..2', '..a', 'a....3', 'a..3', '...a', 'a...0', 'a...01', '....', '....1'], 'unique'))
        ->toBe(['a...1', 'x..2', '..a', 'a.', 'a..3', '...a', 'a...7', 'a...8', '....', '.'])
        ->and(NameRepair::repair(['a', ''], 'minimal'))->toBe(['a', ''])
        ->and(NameRepair::repair(['a', 'b'], fn ($n) => array_map(strtoupper(...), $n)))->toBe(['A', 'B']);
});

it('checks unique names', function (array $names, string $message) {
    expect(fn () => NameRepair::repair($names, 'check_unique'))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'duplicates' => [['a', 'a'], '"a" is duplicated at locations 1 and 2'],
    'empty' => [['a', ''], 'Empty name found at location 2'],
    'dots' => [['a', '..1'], 'Names can\'t be of the form `...` or `..j`'],
]);

// ---------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------

it('writes, appends and round-trips CSV files', function () {
    $frame = Frame::fromColumns([
        'x' => [1.5, null],
        'g' => ['a', 'b'],
        'd' => [new DateTimeImmutable('2024-01-02 10:00:00', new DateTimeZone('America/New_York')), null],
    ])->asFactor('g');
    $path = tempnam(sys_get_temp_dir(), 'phibble');
    $frame->writeCsv($path);
    $frame->writeCsv($path, append: true);

    expect(file_get_contents($path))->toBe("x,g,d\n1.5,a,2024-01-02T15:00:00Z\nNA,b,NA\n1.5,a,2024-01-02T15:00:00Z\nNA,b,NA\n")
        ->and(Frame::fromCsv($path)->nrow())->toBe(4);
    unlink($path);
});

it('writes with other delimiters, quoting, escapes and line endings', function () {
    $frame = Frame::fromColumns(['s' => ['a;b', 'say "x"', 'ok']]);

    expect($frame->toCsv(delimiter: ';'))->toBe("s\n\"a;b\"\n\"say \"\"x\"\"\"\nok\n")
        ->and($frame->toCsv(quote: 'none'))->toBe("s\na;b\nsay \"\"x\"\"\nok\n")
        ->and($frame->toCsv(escape: 'backslash'))->toBe("s\na;b\n\"say \\\"x\\\"\"\nok\n")
        ->and($frame->toCsv(colNames: false, eol: "\r\n"))->toBe("a;b\r\n\"say \"\"x\"\"\"\r\nok\r\n");
});

it('refuses to write list-columns to CSV', function () {
    Frame::fromColumns(['l' => [[1, 2]]])->toCsv();
})->throws(InvalidArgumentException::class, "Column 'l' is a list-column");

// ---------------------------------------------------------------------
// JSON
// ---------------------------------------------------------------------

it('writes JSON records and columns like jsonlite', function () {
    // jsonlite::toJSON(df, dataframe = "rows"/"columns", na = "null", Date = "ISO8601")
    $frame = Frame::fromColumns([
        'n' => [1.5, null], 'i' => [1, null], 'l' => [true, null], 's' => ['a', null], 'f' => ['x', null],
        'd' => [new DateTimeImmutable('2024-01-02'), null],
    ], types: ['f' => 'factor', 'd' => 'date']);

    expect($frame->toJson())->toBe(
        '[{"n":1.5,"i":1,"l":true,"s":"a","f":"x","d":"2024-01-02"},{"n":null,"i":null,"l":null,"s":null,"f":null,"d":null}]'
    )
        ->and($frame->toJson('columns'))->toBe(
            '{"n":[1.5,null],"i":[1,null],"l":[true,null],"s":["a",null],"f":["x",null],"d":["2024-01-02",null]}'
        );
});

it('writes datetimes in UTC, non-finite numbers as null and nested frames as JSON', function () {
    $frame = Frame::fromColumns([
        't' => [new DateTimeImmutable('2024-01-02 10:00:00.25+02:00')],
        'x' => [NAN],
        'nested' => [Frame::fromColumns(['a' => [1, 2]])],
    ]);

    expect($frame->toJson())->toBe('[{"t":"2024-01-02T08:00:00.25Z","x":null,"nested":[{"a":1},{"a":2}]}]')
        ->and($frame->toJson('columns'))->toBe('{"t":["2024-01-02T08:00:00.25Z"],"x":[null],"nested":[{"a":[1,2]}]}')
        ->and(Frame::fromColumns([])->toJson('columns'))->toBe('{}')
        ->and(Frame::fromColumns([])->toJson())->toBe('[]');
});

it('reads JSON records and columns', function () {
    $records = Frame::fromJson('[{"a":1,"b":"x"},{"a":2.5,"c":[1,2]}]');
    $columns = Frame::fromJson('{"a":[1,2],"b":["2024-01-02",null]}', types: ['b' => 'date']);

    expect($records->columns())->toBe(['a', 'b', 'c'])
        ->and($records->column('a'))->toBe([1.0, 2.5])
        ->and($records->column('b'))->toBe(['x', null])
        ->and($records->type('c'))->toBe(Type::List)
        ->and($columns->type('b'))->toBe(Type::Date)
        ->and($columns->column('a'))->toBe([1, 2])
        ->and(Frame::fromJson('[]')->nrow())->toBe(0);
});

it('round-trips JSON', function () {
    $frame = Frame::fromColumns(['a' => [1, null, 3], 's' => ['x', 'y', null]]);

    expect(Frame::fromJson($frame->toJson())->toRows())->toBe($frame->toRows())
        ->and(Frame::fromJson($frame->toJson('columns'))->toRows())->toBe($frame->toRows());
});

it('rejects JSON that is not a table', function (string $json) {
    expect(fn () => Frame::fromJson($json))->toThrow(InvalidArgumentException::class);
})->with(['scalar' => ['5'], 'mixed object' => ['{"a":[1],"b":2}'], 'list of scalars' => ['[1,2]']]);

// ---------------------------------------------------------------------
// Laravel Collections
// ---------------------------------------------------------------------

it('reads collections of arrays, models and plain objects', function () {
    $model = new class
    {
        public function toArray(): array
        {
            return ['id' => 2, 'name' => 'Bo'];
        }
    };
    $plain = new stdClass();
    $plain->id = 3;
    $plain->name = 'Cy';

    $frame = Frame::fromCollection(new Illuminate\Support\Collection([['id' => 1, 'name' => 'Ann'], $model, $plain]));

    expect($frame->toRows())->toBe([
        ['id' => 1, 'name' => 'Ann'], ['id' => 2, 'name' => 'Bo'], ['id' => 3, 'name' => 'Cy'],
    ])
        ->and(Frame::fromCollection(new ArrayIterator([['a' => 1]]))->nrow())->toBe(1)
        ->and(Frame::fromCollection([['a' => '2024-01-02']], ['a' => 'date'])->type('a'))->toBe(Type::Date);
});

it('rejects collections of scalars', function () {
    Frame::fromCollection([1, 2]);
})->throws(InvalidArgumentException::class, 'each item must be an array or object');

it('returns a Laravel collection of rows', function () {
    $collection = Frame::fromColumns(['a' => [1, 2]])->toCollection();

    expect($collection)->toBeInstanceOf(Illuminate\Support\Collection::class)
        ->and($collection->all())->toBe([['a' => 1], ['a' => 2]]);
});

it('uses the native reader only when available, supported and not disabled', function () {
    $calls = 0;
    Phibble\Io\NativeCsv::using(function (string $path, array $options) use (&$calls) {
        $calls++;

        return emulatedStatsReadCsv($path, $options);
    });
    try {
        Frame::fromCsv(IO_FIXTURES.'/guess.csv');
        $native = $calls;

        putenv('PHIBBLE_NATIVE_CSV=0');
        Frame::fromCsv(IO_FIXTURES.'/guess.csv');
        putenv('PHIBBLE_NATIVE_CSV');

        Frame::fromCsv(IO_FIXTURES.'/guess.csv', delimiter: '::');
    } catch (Throwable) {
        // A two-byte delimiter may not parse this file; only the routing matters here.
    } finally {
        putenv('PHIBBLE_NATIVE_CSV');
        Phibble\Io\NativeCsv::using(null);
    }

    expect($native)->toBe(2) // a header probe and the read
        ->and($calls)->toBe(2);
});

it('reads a byte-order mark before a quoted header and CR line endings, as readr does', function () {
    $bom = Frame::fromCsvString("\xEF\xBB\xBF\"id\",\"x\"\n1,2\n");
    $cr = Frame::fromCsvString("a,b\r1,2\r3,4\r");

    expect($bom->columns())->toBe(['id', 'x'])
        ->and($cr->toColumns())->toBe(['a' => [1, 3], 'b' => [2, 4]]);
});
