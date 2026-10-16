<?php

$pdo = new PDO('sqlite::memory:');
$visits = tbl($pdo, "SELECT 1 AS clinic_id, 120 AS bp UNION ALL SELECT 2, 135 UNION ALL SELECT NULL, 128");
$clinics = tbl($pdo, "SELECT 1 AS id, 'north' AS name UNION ALL SELECT NULL, 'unassigned'");

$joined = $visits->join($clinics, [['clinic_id', 'id']], 'left');

echo $joined->showQuery(), "\n\n";
echo $joined->arrange(['bp'])->collect();
