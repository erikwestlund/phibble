<?php

declare(strict_types=1);

namespace Phibble\Source;

use Generator;
use InvalidArgumentException;
use Phibble\Frame;
use Phibble\Io\ColTypes;
use Phibble\Io\CsvOptions;
use Phibble\Io\TextParser;
use Phibble\NameRepair;
use RuntimeException;

/**
 * Reads delimited text the way readr::read_delim() does: guesses column
 * types from a sample of rows, turns na strings into NA, repairs names, and
 * reports problems (unparseable values, ragged rows) as one warning while
 * still returning the data.
 */
final readonly class CsvSource implements Source
{
    /** @param resource $handle */
    private function __construct(
        private mixed $handle,
        private CsvOptions $options,
        private bool $close,
    ) {}

    /** Read a file; ".gz" files are decompressed. */
    public static function file(string $path, CsvOptions $options = new CsvOptions()): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Cannot read CSV file '{$path}'.");
        }
        $target = str_ends_with(strtolower($path), '.gz') ? 'compress.zlib://'.$path : $path;
        $handle = fopen($target, 'r') ?: throw new RuntimeException("Cannot open CSV file '{$path}'.");

        return new self($handle, $options, true);
    }

    public static function string(string $csv, CsvOptions $options = new CsvOptions()): self
    {
        $handle = fopen('php://memory', 'r+') ?: throw new RuntimeException('Cannot open memory stream.');
        fwrite($handle, $csv);
        rewind($handle);

        return new self($handle, $options, true);
    }

    /** @param resource $stream An open, readable stream. It is not closed. */
    public static function stream(mixed $stream, CsvOptions $options = new CsvOptions()): self
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Expected an open stream resource.');
        }

        return new self($stream, $options, false);
    }

    public function read(): Frame
    {
        $o = $this->options;
        $records = $this->records();
        $problems = [];

        // Header (or the first record, which sets the column count).
        $first = null;
        for (; $records->valid(); $records->next()) {
            if ($records->current() !== null) {
                $first = [$records->key(), $records->current()];
                $records->next();

                break;
            }
        }
        if ($first === null) {
            $this->finish();

            return Frame::fromColumns([]);
        }

        [$line, $record] = $first;
        $record[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $record[0]);
        $width = count($record);
        if ($o->colNames === true) {
            $names = array_map(fn (?string $h) => $o->trimWs ? trim((string) $h) : (string) $h, $record);
            $rows = [];
        } else {
            $names = $o->colNames === false ? [] : array_values(array_map(strval(...), $o->colNames));
            $rows = [[$line, $record]];
        }
        for ($i = count($names); $i < $width; $i++) {
            $names[] = 'X'.($i + 1);
        }
        $width = count($names);
        $names = NameRepair::repair($names, $o->nameRepair);

        // Data rows.
        $columns = array_fill(0, $width, []);
        $count = 0;
        $take = function (int $line, ?array $record) use (&$columns, &$count, &$problems, $width, $o): void {
            if ($record === null) {
                $record = array_fill(0, $width, null);
                $problems[] = "row {$line}: expected {$width} columns, got 0";
            } elseif (count($record) < $width) {
                $problems[] = "row {$line}: expected {$width} columns, got ".count($record);
                $record = array_pad($record, $width, null);
            } elseif (count($record) > $width) {
                $problems[] = "row {$line}: expected {$width} columns, got ".count($record);
                $extra = array_splice($record, $width - 1);
                $record[] = implode($o->delimiter, array_map(strval(...), $extra));
            }
            foreach ($record as $i => $value) {
                if ($value !== null) {
                    if ($o->trimWs) {
                        $value = trim($value, " \t");
                    }
                    if (in_array($value, $o->na, true)) {
                        $value = null;
                    }
                }
                $columns[$i][] = $value;
            }
            $count++;
        };

        foreach ($rows as [$l, $r]) {
            if ($o->nMax === null || $count < $o->nMax) {
                $take($l, $r);
            }
        }
        if ($o->nMax === null || $count < $o->nMax) {
            for (; $records->valid(); $records->next()) {
                $record = $records->current();
                if ($record === null && $o->skipEmptyRows) {
                    continue;
                }
                $take($records->key(), $record);
                if ($o->nMax !== null && $count >= $o->nMax) {
                    break;
                }
            }
        }
        $this->finish();

        // Types.
        $specs = ColTypes::resolve($o->types, $names);
        $parser = new TextParser($o->decimalMark, $o->groupingMark);
        $sample = self::sampleRows($count, $o->guessMax);
        $data = [];
        foreach ($names as $i => $name) {
            $spec = $specs[$i];
            if ($spec === 'skip') {
                continue;
            }
            $values = $columns[$i];
            if ($spec === null) {
                $type = $parser->guess(array_map(fn (int $row) => $values[$row], $sample));
                $spec = ['type' => $type, 'number' => false, 'levels' => null, 'ordered' => false];
                $grouping = true;
            } else {
                $grouping = false;
            }
            [$column, $failed] = $parser->parse($values, $spec['type'], [
                'grouping' => $grouping,
                'number' => $spec['number'],
                'levels' => $spec['levels'],
                'ordered' => $spec['ordered'],
            ]);
            if ($failed > 0) {
                $problems[] = "column '{$name}': {$failed} value".($failed === 1 ? '' : 's')." could not be parsed as {$spec['type']->value}";
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

        if ($problems !== []) {
            $shown = array_slice($problems, 0, 5);
            $more = count($problems) > 5 ? ' (and '.(count($problems) - 5).' more)' : '';
            trigger_error('One or more parsing issues: '.implode('; ', $shown).$more.'.', E_USER_WARNING);
        }

        return Frame::fromColumns($data);
    }

    /**
     * The rows readr (vroom) looks at to guess types: all of them when
     * there are at most $guessMax, otherwise $guessMax - 1 evenly spaced
     * rows plus the last.
     *
     * @return list<int>
     */
    private static function sampleRows(int $count, int $guessMax): array
    {
        if ($count <= $guessMax) {
            return $count === 0 ? [] : range(0, $count - 1);
        }
        $step = intdiv($count, $guessMax);
        $rows = [];
        for ($i = 0; $i < $guessMax - 1; $i++) {
            $rows[] = $i * $step;
        }
        $rows[] = $count - 1;

        return $rows;
    }

    /**
     * Records keyed by 1-based line number; null for an empty line.
     *
     * @return Generator<int, list<?string>|null>
     */
    private function records(): Generator
    {
        $o = $this->options;
        $line = 0;
        for ($i = 0; $i < $o->skip; $i++) {
            if (fgets($this->handle) === false) {
                return;
            }
            $line++;
        }

        if ($o->comment === '' && $o->escapeDouble && ! $o->escapeBackslash && $o->quote !== '') {
            yield from $this->fastRecords($line);
        } else {
            yield from $this->slowRecords($line);
        }
    }

    /** @return Generator<int, list<?string>|null> */
    private function fastRecords(int $line): Generator
    {
        $o = $this->options;
        $text = stream_get_contents($this->handle);
        // Without quotes or carriage returns, every line is a record and every
        // delimiter separates fields, so plain splitting gives fgetcsv's result.
        if (! str_contains($text, $o->quote) && ! str_contains($text, "\r")) {
            $lines = explode("\n", $text);
            unset($text);
            if (end($lines) === '') {
                array_pop($lines);
            }
            foreach ($lines as $content) {
                $line++;
                yield $line => ($content === '' ? null : explode($o->delimiter, $content));
            }

            return;
        }

        $memory = fopen('php://temp', 'r+');
        fwrite($memory, $text);
        unset($text);
        rewind($memory);
        while (($record = fgetcsv($memory, null, $o->delimiter, $o->quote, '')) !== false) {
            $line++;
            yield $line => ($record === [null] ? null : $record);
        }
        fclose($memory);
    }

    /**
     * A character-level reader for the cases fgetcsv() can't handle:
     * comments, backslash escapes and unquoted files.
     *
     * @return Generator<int, list<?string>|null>
     */
    private function slowRecords(int $line): Generator
    {
        $o = $this->options;
        $text = stream_get_contents($this->handle);
        if ($text === false) {
            return;
        }
        $text = str_replace("\r\n", "\n", $text);
        $length = strlen($text);
        $pos = 0;
        $delim = $o->delimiter;
        $quote = $o->quote;
        $comment = $o->comment;

        while ($pos < $length) {
            $line++;
            $fields = [];
            $field = '';
            $inQuotes = false;
            $sawContent = false;

            // A line that starts with the comment string is dropped entirely.
            if ($comment !== '' && substr_compare($text, $comment, $pos, strlen($comment)) === 0) {
                $end = strpos($text, "\n", $pos);
                $pos = $end === false ? $length : $end + 1;
                $line--;

                continue;
            }

            while ($pos < $length) {
                $c = $text[$pos];
                if ($inQuotes) {
                    if ($o->escapeBackslash && $c === '\\' && $pos + 1 < $length) {
                        $field .= $text[$pos + 1];
                        $pos += 2;

                        continue;
                    }
                    if ($c === $quote) {
                        if ($o->escapeDouble && $pos + 1 < $length && $text[$pos + 1] === $quote) {
                            $field .= $quote;
                            $pos += 2;

                            continue;
                        }
                        $inQuotes = false;
                        $pos++;

                        continue;
                    }
                    $field .= $c;
                    $pos++;

                    continue;
                }

                if ($c === "\n") {
                    $pos++;

                    break;
                }
                if ($comment !== '' && substr_compare($text, $comment, $pos, strlen($comment)) === 0) {
                    $end = strpos($text, "\n", $pos);
                    $pos = $end === false ? $length : $end + 1;

                    break;
                }
                if ($c === $delim) {
                    $fields[] = $field;
                    $field = '';
                    $sawContent = true;
                    $pos++;

                    continue;
                }
                if ($quote !== '' && $c === $quote && trim($field) === '') {
                    $inQuotes = true;
                    $field = '';
                    $sawContent = true;
                    $pos++;

                    continue;
                }
                if ($o->escapeBackslash && $c === '\\' && $pos + 1 < $length) {
                    $field .= $text[$pos + 1];
                    $pos += 2;

                    continue;
                }
                $field .= $c;
                $sawContent = true;
                $pos++;
            }

            if (! $sawContent && $field === '' && $fields === []) {
                yield $line => null;

                continue;
            }
            $fields[] = $field;
            yield $line => $fields;
        }
    }

    private function finish(): void
    {
        if ($this->close && is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
