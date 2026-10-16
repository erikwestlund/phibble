<?php

$penguins = Frame::fromCsv('penguins.csv');

$counts = $penguins
    |> (fn (Frame $f) => $f->groupBy(['island']))
    |> (fn (Frame $f) => $f->summarise(['n' => n()]))
    |> (fn (Frame $f) => $f->arrange([desc(col('n'))]));

echo $counts;
