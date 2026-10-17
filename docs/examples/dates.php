<?php

$visits = DataFrame::fromCsvString(<<<'CSV'
    day,checked_in
    2024-01-02,2024-01-02T09:15:00Z
    2024-01-03,2024-01-03 14:40:00
    CSV);

echo $visits, "\n";
echo $visits->cast(['day' => 'float', 'checked_in' => 'float']);
