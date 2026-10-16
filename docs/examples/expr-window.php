<?php

$sales = tribble(
    ['store', 'week', 'units'],
    ['a', 1, 10], ['a', 2, 14], ['a', 3, 9],
    ['b', 1, 7], ['b', 2, 7], ['b', 3, 12],
);

echo $sales
    ->groupBy(['store'])
    ->mutate([
        'prev' => lag(col('units')),
        'change' => col('units')->sub(lag(col('units'))),
        'running' => cumsum(col('units')),
        'rank' => min_rank(desc(col('units'))),
        'tile' => ntile(col('units'), n: 2),
    ]);
