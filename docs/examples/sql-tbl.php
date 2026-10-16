<?php

// Load penguins.csv into an in-memory SQLite database.
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE penguins (species TEXT, island TEXT, bill_len REAL, bill_dep REAL,
    flipper_len INTEGER, body_mass INTEGER, sex TEXT, year INTEGER)');
$insert = $pdo->prepare('INSERT INTO penguins VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach (Frame::fromCsv('penguins.csv')->toRows() as $row) {
    $insert->execute(array_values($row));
}

$penguins = tbl($pdo, 'penguins');

$summary = $penguins
    ->filter(col('year')->eq(2008))
    ->groupBy(['species'])
    ->summarise(['n' => n(), 'mass' => mean(col('body_mass'), na_rm: true)]);

echo get_class($summary), "\n\n";
echo $summary->showQuery(), "\n\n";
echo json_encode($summary->bindings()), "\n\n";
echo $summary->collect();
