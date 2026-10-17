<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Phibble\DataFrame;
use Phibble\Laravel\PhibbleServiceProvider;
use Phibble\Sql\SqlTable;

use function Phibble\Expr\{col, mean, n};

final class LaravelTestPatient extends Model
{
    use SoftDeletes;

    protected $table = 'patients';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function () {
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Capsule::schema()->create('patients', function ($table) {
        $table->increments('id');
        $table->string('clinic');
        $table->integer('age');
        $table->float('bp')->nullable();
        $table->softDeletes();
    });
    foreach ([['a', 34, 120.0], ['a', 61, 140.0], ['b', 45, null], ['b', 52, 130.0], ['b', 29, 110.0]] as [$clinic, $age, $bp]) {
        LaravelTestPatient::create(['clinic' => $clinic, 'age' => $age, 'bp' => $bp]);
    }
    LaravelTestPatient::where('age', 29)->delete();
    PhibbleServiceProvider::registerMacros();
});

it('runs Eloquent queries lazily in the database with phibble()', function () {
    $table = LaravelTestPatient::query()->where('age', '>', 30)->phibble();
    $summary = $table->groupBy(['clinic'])->summarise(['n' => n(), 'bp' => mean(col('bp'), na_rm: true)]);

    expect($table)->toBeInstanceOf(SqlTable::class)
        ->and($summary)->toBeInstanceOf(SqlTable::class)
        ->and($summary->showQuery())->toContain('"deleted_at" is null')
        ->and($summary->collect()->toRows())->toBe([
            ['clinic' => 'a', 'n' => 2, 'bp' => 130.0],
            ['clinic' => 'b', 'n' => 2, 'bp' => 130.0],
        ]);
});

it('applies global scopes such as soft deletes', function () {
    expect(LaravelTestPatient::query()->phibble()->nrow())->toBe(4)
        ->and(LaravelTestPatient::withTrashed()->phibble()->nrow())->toBe(5)
        ->and(DataFrame::fromQuery(LaravelTestPatient::query())->nrow())->toBe(4);
});

it('adds toDataFrame() to builders and collections', function () {
    $frame = Capsule::table('patients')->whereNull('deleted_at')->orderBy('id')->toDataFrame();

    expect($frame)->toBeInstanceOf(DataFrame::class)
        ->and($frame->column('age'))->toBe([34, 61, 45, 52])
        ->and(LaravelTestPatient::query()->orderBy('id')->toDataFrame()->column('clinic'))->toBe(['a', 'a', 'b', 'b'])
        ->and(LaravelTestPatient::query()->orderBy('id')->get()->toDataFrame()->column('age'))->toBe([34, 61, 45, 52])
        ->and((new Collection([['x' => 1], ['x' => 2]]))->toDataFrame()->column('x'))->toBe([1, 2]);
});

it('works on the base query builder', function () {
    $table = Capsule::table('patients')->select(['clinic', 'age'])->phibble();

    expect($table->columns())->toBe(['clinic', 'age'])
        ->and($table->filter(col('age')->ge(50))->nrow())->toBe(2);
});
