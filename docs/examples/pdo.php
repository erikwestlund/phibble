<?php

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE visits (id INTEGER, clinic TEXT, bp REAL, seen TEXT)');
$pdo->exec("INSERT INTO visits VALUES (1, 'north', 121.5, '2024-05-01'), (2, 'south', NULL, '2024-05-02'), (3, 'north', 133.0, '2024-05-02')");

$frame = Frame::fromPdo($pdo, 'SELECT * FROM visits WHERE clinic = ?', ['north']);

echo $frame;
