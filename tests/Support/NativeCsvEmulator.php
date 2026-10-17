<?php

declare(strict_types=1);

use Phibble\Column\TemporalColumn;
use Phibble\DataFrame;
use Phibble\Io\NativeCsv;

/**
 * A PHP stand-in for the php-stats extension's stats_read_csv(), following
 * its contract (see src/Io/NativeCsv.php), built on phibble's PHP reader.
 * It lets the native code path be tested without the extension.
 */
function emulatedStatsReadCsv(string $path, array $options): array
{
    NativeCsv::enable(false);
    try {
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings) {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);
        $common = [
            'delimiter' => $options['delimiter'] ?? ',',
            'na' => $options['na'] ?? ['', 'NA'],
            'colNames' => $options['col_names'] ?? true,
            'skip' => $options['skip'] ?? 0,
            'quote' => $options['quote'] ?? '"',
            'escapeDouble' => $options['escape_double'] ?? true,
            'escapeBackslash' => $options['escape_backslash'] ?? false,
            'comment' => $options['comment'] ?? '',
            'trimWs' => $options['trim_ws'] ?? true,
            'skipEmptyRows' => $options['skip_empty_rows'] ?? true,
            'guessMax' => $options['guess_max'] ?? 1000,
            'nameRepair' => 'unique',
            'decimalMark' => $options['decimal_mark'] ?? '.',
            'groupingMark' => $options['grouping_mark'] ?? null,
        ];
        // The raw header, read as a row of text (a DataFrame can't hold duplicate names).
        $width = DataFrame::fromCsv($path, ...[...$common, 'colNames' => false, 'nMax' => 1])->ncol();
        $header = $width === 0 ? [] : array_values(DataFrame::fromCsv($path, ...[...$common, 'colNames' => false, 'nMax' => 1, 'types' => str_repeat('c', $width)])->toRows()[0] ?? []);
        $names = ($options['col_names'] ?? true)
            ? array_map(fn (?string $n) => $n ?? '', $header)
            : array_map(fn (int $i) => 'X'.($i + 1), array_keys($header));
        $codes = $options['types'] ?? [];
        $types = implode('', array_map(fn (int $i) => $codes[$i] ?? '?', array_keys($names)));
        $frame = DataFrame::fromCsv($path, ...[...$common, 'types' => $types === '' ? [] : $types, 'nMax' => $options['n_max'] ?? null]);
        $kept = $frame->columns();
    } finally {
        restore_error_handler();
        NativeCsv::enable(true);
    }

    // Recover failure counts and row problems from the PHP reader's warning.
    $failed = [];
    $problems = [];
    foreach ($warnings as $warning) {
        $body = preg_replace('/^One or more parsing issues: |\.$/', '', $warning);
        foreach (explode('; ', $body) as $entry) {
            if (preg_match("/^column '(.*)': (\\d+) values? could not be parsed/", $entry, $m)) {
                $failed[$m[1]] = (int) $m[2];
            } elseif (str_starts_with($entry, 'row ')) {
                $problems[] = $entry;
            }
        }
    }

    // The data frame has repaired names and no skipped columns; map back by position.
    $columns = [];
    $k = 0;
    foreach ($names as $i => $name) {
        if (($codes[$i] ?? '?') === '_') {
            $columns[] = null;

            continue;
        }
        $repaired = $kept[$k++];
        $storage = $frame->storage($repaired);
        $columns[] = [
            'type' => $storage->type()->value,
            'values' => $storage instanceof TemporalColumn ? $storage->ticks() : $storage->values(),
            'failed' => $failed[$repaired] ?? 0,
        ];
    }

    return ['names' => $names, 'columns' => $columns, 'problems' => $problems];
}
