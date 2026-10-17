<?php

declare(strict_types=1);

use Phibble\Contracts\Table;
use Phibble\Polars\PolarsTable;

/** A table as a PolarsTable. */
function pt(Table $table): PolarsTable
{
    return PolarsTable::fromTable($table);
}

/** Run $fn and fail if any verb fell back to an in-memory DataFrame. */
function native(Closure $fn): mixed
{
    $before = PolarsTable::$fallbacks;
    $result = $fn();
    expect(PolarsTable::$fallbacks)->toBe($before, 'the verb fell back to a phibble DataFrame');

    return $result;
}
