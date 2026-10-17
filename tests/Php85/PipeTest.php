<?php

declare(strict_types=1);

use Phibble\DataFrame;
use Phibble\Row;

// The pipe operator is PHP 8.5 syntax; phpunit.xml loads this directory only on 8.5.

it('works with the pipe operator', function () {
    $result = penguins()
        |> (fn (DataFrame $f) => $f->filter(fn (Row $r) => $r->mass !== null))
        |> (fn (DataFrame $f) => $f->arrange([['mass', 'desc']]))
        |> (fn (DataFrame $f) => $f->head(1));

    expect($result->column('mass'))->toBe([5400.0]);
});
