<?php

/*
 * Writes deterministic benchmark data to bench/data/:
 *
 * - events.csv: N rows (default 1,000,000): id, key (~N/100 distinct, 10k at 1M),
 *   g (one of 8 labels), x (float, ~2% NA), t (datetime)
 * - dims.csv: N/10 rows: key, label, w
 *
 * Usage: php bench/generate.php [rows]
 */

declare(strict_types=1);

$rows = (int) ($argv[1] ?? 1_000_000);
$dir = __DIR__.'/data';
@mkdir($dir);
mt_srand(42);

$keys = max(1, intdiv($rows, 100));
$labels = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta'];
$start = strtotime('2024-01-01 00:00:00 UTC');

$f = fopen("{$dir}/events.csv", 'w');
fwrite($f, "id,key,g,x,t\n");
for ($i = 1; $i <= $rows; $i++) {
    $x = mt_rand(1, 50) === 1 ? 'NA' : sprintf('%.4f', (mt_rand() / mt_getrandmax()) * 200 - 100);
    fwrite($f, $i.','.mt_rand(1, $keys).','.$labels[mt_rand(0, 7)].','.$x.','.gmdate('Y-m-d H:i:s', $start + $i * 37)."\n");
}
fclose($f);

$f = fopen("{$dir}/dims.csv", 'w');
fwrite($f, "key,label,w\n");
$dims = max(1, intdiv($rows, 10));
for ($k = 1; $k <= $dims; $k++) {
    fwrite($f, $k.',label'.$k.','.sprintf('%.3f', mt_rand() / mt_getrandmax())."\n");
}
fclose($f);

echo "Wrote {$rows} events ({$keys} keys) and {$dims} dims to {$dir}\n";
