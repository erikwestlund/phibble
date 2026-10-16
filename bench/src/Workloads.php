<?php

declare(strict_types=1);

namespace Phibble\Bench;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Frame;

use function Phibble\Expr\{col, cumsum, lag, mean, n, row_number, sum};

/**
 * Benchmark workloads. Each receives the events and dims tables and must
 * force full evaluation (here by counting the collected rows), so lazy
 * backends do the work inside the timer. Only Table methods are used, so
 * every backend runs the same code.
 */
final class Workloads
{
    /** @return array<string, Closure(Table, Table): int> */
    public static function all(): array
    {
        return [
            'group_summarise' => fn (Table $e) => $e
                ->groupBy(['key'])
                ->summarise(['n' => n(), 'mean_x' => mean(col('x'), na_rm: true), 'sum_x' => sum(col('x'), na_rm: true)])
                ->collect()->nrow(),
            'group_summarise_closure' => fn (Table $e) => $e
                ->groupBy(['key'])
                ->summarise([
                    'n' => fn (Table $g) => $g->nrow(),
                    'sum_x' => fn (Table $g) => array_sum(array_filter($g->column('x'), fn ($v) => $v !== null)),
                ])
                ->collect()->nrow(),
            'grouped_window' => fn (Table $e) => $e
                ->groupBy(['key'])
                ->arrange(['t'])
                ->mutate(['prev' => lag(col('x')), 'run' => cumsum(col('id')), 'rn' => row_number()])
                ->collect()->nrow(),
            'filter_expr' => fn (Table $e) => $e
                ->filter(col('x')->gt(0)->and(col('g')->isIn(['alpha', 'beta', 'gamma'])))
                ->collect()->nrow(),
            'mutate_expr' => fn (Table $e) => $e
                ->mutate(['y' => col('x')->mul(2)->add(col('id')), 'big' => col('x')->gt(50)])
                ->collect()->nrow(),
            'join' => fn (Table $e, Table $d) => $e->join($d, ['key'], 'left')->collect()->nrow(),
            'arrange' => fn (Table $e) => $e->arrange([['x', 'desc'], 'id'])->collect()->nrow(),
        ];
    }

    /** CSV reading, Frame only. @return array<string, Closure(string): int> */
    public static function csv(): array
    {
        return [
            'csv_guess' => fn (string $path) => Frame::fromCsv($path)->nrow(),
            'csv_typed' => fn (string $path) => Frame::fromCsv($path, types: 'iicdT')->nrow(),
        ];
    }
}
