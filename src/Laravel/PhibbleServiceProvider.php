<?php

declare(strict_types=1);

namespace Phibble\Laravel;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\ServiceProvider;
use Phibble\DataFrame;
use Phibble\Sql\SqlTable;

/**
 * Laravel integration, registered by package discovery. It adds macros:
 *
 * - Collection::toDataFrame() and LazyCollection::toDataFrame(): an in-memory DataFrame.
 * - Query, Eloquent and relation builders get phibble(): a lazy SqlTable on
 *   the builder's connection, so verbs run in the database:
 *
 *       User::query()->where('active', true)->phibble()
 *           ->groupBy(['country'])
 *           ->summarise(['n' => n()]);
 *
 *   and toDataFrame(): run the query now and return a DataFrame.
 *
 * Eloquent global scopes (soft deletes, tenancy) are applied. Attribute
 * casts and accessors are not, because rows come straight from SQL.
 */
final class PhibbleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        self::registerMacros();
    }

    /** Register the macros; safe to call more than once. */
    public static function registerMacros(): void
    {
        foreach ([Collection::class, LazyCollection::class] as $class) {
            if (class_exists($class)) {
                $class::macro('toDataFrame', function (array $types = []): DataFrame {
                    /** @var Collection $this */
                    return DataFrame::fromCollection($this, $types);
                });
            }
        }

        foreach ([QueryBuilder::class, EloquentBuilder::class, Relation::class] as $class) {
            if (! class_exists($class)) {
                continue;
            }
            $class::macro('phibble', function (): SqlTable {
                return SqlTable::fromQuery($this);
            });
            $class::macro('toDataFrame', function (array $types = []): DataFrame {
                return DataFrame::fromQuery($this, types: $types);
            });
        }
    }
}
