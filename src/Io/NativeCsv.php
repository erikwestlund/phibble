<?php

declare(strict_types=1);

namespace Phibble\Io;

use Closure;
use InvalidArgumentException;
use Phibble\Column\ArrayColumn;
use Phibble\Column\Column;
use Phibble\Column\TemporalColumn;
use Phibble\Frame;
use Phibble\NameRepair;
use Phibble\Type;

/**
 * Reads CSV files with the php-stats extension's stats_read_csv() when it
 * is loaded, which parses and types columns natively. Name repair,
 * col_select, factor and number types, and the problems warning stay in
 * PHP, so results match the PHP reader.
 *
 * Set PHIBBLE_NATIVE_CSV=0 or call NativeCsv::enable(false) to always use
 * the PHP reader.
 *
 * @internal
 */
final class NativeCsv
{
    private static bool $enabled = true;

    /** @var (Closure(string, array): array)|null A stand-in reader, for tests. */
    private static ?Closure $reader = null;

    public static function enable(bool $enabled = true): void
    {
        self::$enabled = $enabled;
    }

    /**
     * Use another implementation of stats_read_csv(), or null for the
     * extension's.
     *
     * @param (Closure(string, array): array)|null $reader
     */
    public static function using(?Closure $reader): void
    {
        self::$reader = $reader;
    }

    public static function available(): bool
    {
        return self::$enabled
            && getenv('PHIBBLE_NATIVE_CSV') !== '0'
            && (self::$reader !== null || function_exists('stats_read_csv'));
    }

    /** Whether these options can be handed to the native reader. */
    public static function supports(string $path, CsvOptions $o): bool
    {
        return strlen($o->delimiter) === 1
            && strlen($o->quote) <= 1
            && ! str_ends_with(strtolower($path), '.gz');
    }

    /**
     * @return array{0: Frame, 1: list<string>} The frame and its problems.
     */
    public static function read(string $path, CsvOptions $o): array
    {
        $base = [
            'delimiter' => $o->delimiter,
            'quote' => $o->quote,
            'escape_double' => $o->escapeDouble,
            'escape_backslash' => $o->escapeBackslash,
            'na' => array_values($o->na),
            'trim_ws' => $o->trimWs,
            'skip' => $o->skip,
            'comment' => $o->comment,
            'skip_empty_rows' => $o->skipEmptyRows,
            'col_names' => $o->colNames === true,
            'guess_max' => $o->guessMax,
            'decimal_mark' => $o->decimalMark,
            'grouping_mark' => $o->groupingMark,
        ];

        // Column names first (a header-only read), to resolve col_types by name.
        $probe = self::call($path, [...$base, 'n_max' => 0]);
        $names = self::names($probe['names'], $o);
        $specs = ColTypes::resolve($o->types, $names);

        $codes = [];
        foreach ($specs as $i => $spec) {
            $codes[$i] = match (true) {
                $spec === null => '?',
                $spec === 'skip' => '_',
                $spec['number'], $spec['type'] === Type::Factor => 'c',
                default => match ($spec['type']) {
                    Type::Bool => 'l',
                    Type::Int => 'i',
                    Type::Float => 'd',
                    Type::Date => 'D',
                    Type::DateTime => 'T',
                    default => 'c',
                },
            };
        }

        $result = self::call($path, [...$base, 'n_max' => $o->nMax, 'types' => $codes]);
        $problems = array_values($result['problems'] ?? []);
        $parser = new TextParser($o->decimalMark, $o->groupingMark);
        $data = [];
        foreach ($names as $i => $name) {
            $spec = $specs[$i];
            $native = $result['columns'][$i] ?? null;
            if ($spec === 'skip' || $native === null) {
                continue;
            }
            $values = $native['values'];
            $failed = (int) ($native['failed'] ?? 0);
            if ($spec !== null && ($spec['number'] || $spec['type'] === Type::Factor)) {
                // Requested as text; finish the parse in PHP.
                [$column, $failed] = $parser->parse($values, $spec['type'], [
                    'grouping' => false,
                    'number' => $spec['number'],
                    'levels' => $spec['levels'],
                    'ordered' => $spec['ordered'],
                ]);
                $typeName = $spec['type']->value;
            } else {
                $column = self::column($native['type'], $values);
                $typeName = $column->type()->value;
            }
            if ($failed > 0) {
                $problems[] = "column '{$name}': {$failed} value".($failed === 1 ? '' : 's')." could not be parsed as {$typeName}";
            }
            $data[$name] = $column;
        }

        if ($o->select !== null) {
            $selected = [];
            foreach ($o->select as $name) {
                $selected[$name] = $data[$name]
                    ?? throw new InvalidArgumentException("col_select names unknown column '{$name}'.");
            }
            $data = $selected;
        }

        return [Frame::fromColumns($data), $problems];
    }

    /** @param list<mixed> $values */
    private static function column(string $type, array $values): Column
    {
        return match ($type) {
            'bool' => new ArrayColumn(Type::Bool, $values),
            'int' => new ArrayColumn(Type::Int, $values),
            'float' => new ArrayColumn(Type::Float, $values),
            'string' => new ArrayColumn(Type::String, $values),
            'date' => new TemporalColumn(Type::Date, $values),
            'datetime' => new TemporalColumn(Type::DateTime, $values),
            default => throw new InvalidArgumentException("stats_read_csv() returned unknown column type '{$type}'."),
        };
    }

    /**
     * Names as the PHP reader builds them: given names, X1..Xn for the
     * rest, then name repair.
     *
     * @param list<string> $native
     * @return list<string>
     */
    private static function names(array $native, CsvOptions $o): array
    {
        $width = count($native);
        $names = match (true) {
            $o->colNames === true => array_map(fn (string $n) => $o->trimWs ? trim($n) : $n, $native),
            $o->colNames === false => [],
            default => array_values(array_map(strval(...), $o->colNames)),
        };
        for ($i = count($names); $i < $width; $i++) {
            $names[] = 'X'.($i + 1);
        }

        return NameRepair::repair($names, $o->nameRepair);
    }

    /** @return array{names: list<string>, columns: list<array{type: string, values: list<mixed>, failed: int}|null>, problems: list<string>} */
    private static function call(string $path, array $options): array
    {
        $reader = self::$reader ?? fn (string $path, array $options): array => \stats_read_csv($path, $options);

        return $reader($path, $options);
    }
}
