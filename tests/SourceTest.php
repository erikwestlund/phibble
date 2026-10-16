<?php

declare(strict_types=1);

use Phibble\Frame;
use Phibble\Type;

it('reads CSV and infers types from text', function () {
    $frame = Frame::fromCsvString(<<<'CSV'
        id,score,ok,day,name,stamp
        1,2.5,true,2024-01-02,Ann,2024-01-02 10:00:00
        2,NA,FALSE,2024-02-29,,2024-01-03T11:30:00Z
        3,1e3,,2024-03-01,"Smith, Bo",
        CSV);

    expect($frame->types())->toBe([
        'id' => Type::Int, 'score' => Type::Float, 'ok' => Type::Bool,
        'day' => Type::Date, 'name' => Type::String, 'stamp' => Type::Date,
    ])
        ->and($frame->column('score'))->toBe([2.5, null, 1000.0])
        ->and($frame->column('ok'))->toBe([true, false, null])
        ->and($frame->column('name'))->toBe(['Ann', null, 'Smith, Bo'])
        ->and($frame->column('day')[1]->format('Y-m-d'))->toBe('2024-02-29');
});

it('keeps invalid dates and mixed text as strings', function () {
    $frame = Frame::fromCsvString("d,code\n2024-02-30,1\n2024-01-01,x\n");

    expect($frame->types())->toBe(['d' => Type::String, 'code' => Type::String]);
});

it('reads CSV with explicit types, custom NA and delimiters', function () {
    $frame = Frame::fromCsvString("zip;n\n02134;-\n10001;4\n", types: ['zip' => 'string'], delimiter: ';', na: ['-']);

    expect($frame->column('zip'))->toBe(['02134', '10001'])
        ->and($frame->column('n'))->toBe([null, 4]);
});

it('reads CSV files and strips a BOM', function () {
    $path = tempnam(sys_get_temp_dir(), 'phibble');
    file_put_contents($path, "\xEF\xBB\xBFa,b\n1,x\n");

    expect(Frame::fromCsv($path)->columns())->toBe(['a', 'b']);
    unlink($path);
});

it('rejects CSV rows with the wrong number of fields', function () {
    Frame::fromCsvString("a,b\n1\n");
})->throws(InvalidArgumentException::class, 'CSV line 2 has 1 fields; the header has 2.');

function database(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('create table people (id integer, name text, height real, born text, active integer)');
    $pdo->exec("insert into people values (1, 'Ann', 1.62, '1990-05-01', 1), (2, 'Bo', null, '1985-11-30', 0), (3, 'Cy', 1.80, null, 1)");

    return $pdo;
}

it('reads from PDO with positional and named bindings', function () {
    $frame = Frame::fromPdo(database(), 'select * from people where id >= ? order by id', [2]);

    expect($frame->nrow())->toBe(2)
        ->and($frame->types())->toBe([
            'id' => Type::Int, 'name' => Type::String, 'height' => Type::Float,
            'born' => Type::Date, 'active' => Type::Int,
        ])
        ->and($frame->column('height'))->toBe([null, 1.8]);

    $named = Frame::fromPdo(database(), 'select name from people where name = :name', ['name' => 'Ann']);
    expect($named->column('name'))->toBe(['Ann']);
});

it('applies explicit types to PDO results', function () {
    $frame = Frame::fromPdo(database(), 'select active from people', types: ['active' => 'bool']);

    expect($frame->column('active'))->toBe([true, false, true]);
});

it('parses numeric strings from drivers that return text', function () {
    $pdo = database();
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

    $frame = Frame::fromPdo($pdo, 'select id, height from people');
    expect($frame->types())->toBe(['id' => Type::Int, 'height' => Type::Float]);
});

it('keeps column names for empty PDO results', function () {
    $frame = Frame::fromPdo(database(), 'select id, name from people where id > 99');

    expect($frame->columns())->toBe(['id', 'name'])
        ->and($frame->nrow())->toBe(0);
});

// Stand-ins with the same duck-typed surface as the real builders.
final class FakeLaravelConnection
{
    public function __construct(private PDO $pdo) {}

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function prepareBindings(array $bindings): array
    {
        return array_map(fn ($b) => is_bool($b) ? (int) $b : $b, $bindings);
    }
}

final class FakeLaravelBuilder
{
    public function __construct(private FakeLaravelConnection $connection) {}

    public function toSql(): string
    {
        return 'select name from people where active = ? order by id';
    }

    public function getBindings(): array
    {
        return [true];
    }

    public function getConnection(): FakeLaravelConnection
    {
        return $this->connection;
    }
}

final class FakeEloquentBuilder
{
    public function __construct(private FakeLaravelBuilder $query) {}

    public function getQuery(): FakeLaravelBuilder
    {
        return $this->query;
    }
}

final class FakeDoctrineResult
{
    public function __construct(private array $rows) {}

    public function fetchAllAssociative(): array
    {
        return $this->rows;
    }
}

final class FakeDoctrineBuilder
{
    public function __construct(private PDO $pdo) {}

    public function getSQL(): string
    {
        return 'select id from people where name = :name';
    }

    public function getParameters(): array
    {
        return ['name' => 'Bo'];
    }

    public function executeQuery(): FakeDoctrineResult
    {
        $statement = $this->pdo->prepare($this->getSQL());
        $statement->execute($this->getParameters());

        return new FakeDoctrineResult($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}

it('reads Laravel query builders and unwraps Eloquent builders', function () {
    $query = new FakeLaravelBuilder(new FakeLaravelConnection(database()));

    expect(Frame::fromQuery($query)->column('name'))->toBe(['Ann', 'Cy'])
        ->and(Frame::fromQuery(new FakeEloquentBuilder($query))->column('name'))->toBe(['Ann', 'Cy']);
});

it('reads Doctrine query builders through PDO or the builder itself', function () {
    $pdo = database();
    $builder = new FakeDoctrineBuilder($pdo);

    expect(Frame::fromQuery($builder, $pdo)->column('id'))->toBe([2])
        ->and(Frame::fromQuery($builder)->column('id'))->toBe([2]);
});

it('rejects unknown builders', function () {
    Frame::fromQuery(new stdClass());
})->throws(InvalidArgumentException::class, 'Unsupported query builder stdClass.');
