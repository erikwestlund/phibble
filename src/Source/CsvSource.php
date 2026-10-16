<?php

declare(strict_types=1);

namespace Phibble\Source;

use InvalidArgumentException;
use Phibble\Column\Infer;
use Phibble\Frame;
use Phibble\Type;
use RuntimeException;

/**
 * Reads CSV with a header row and infers column types from the text.
 */
final readonly class CsvSource implements Source
{
    /**
     * @param resource $handle
     * @param array<string, Type|string> $types
     * @param list<string> $na
     */
    private function __construct(
        private mixed $handle,
        private array $types,
        private string $delimiter,
        private array $na,
    ) {}

    /**
     * @param array<string, Type|string> $types
     * @param list<string> $na
     */
    public static function file(string $path, array $types = [], string $delimiter = ',', array $na = ['', 'NA']): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Cannot read CSV file '{$path}'.");
        }

        $handle = fopen($path, 'r') ?: throw new RuntimeException("Cannot open CSV file '{$path}'.");

        return new self($handle, $types, $delimiter, $na);
    }

    /**
     * @param array<string, Type|string> $types
     * @param list<string> $na
     */
    public static function string(string $csv, array $types = [], string $delimiter = ',', array $na = ['', 'NA']): self
    {
        $handle = fopen('php://memory', 'r+') ?: throw new RuntimeException('Cannot open memory stream.');
        fwrite($handle, $csv);
        rewind($handle);

        return new self($handle, $types, $delimiter, $na);
    }

    public function read(): Frame
    {
        $header = $this->next();
        if ($header === null) {
            return Frame::fromColumns([]);
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn (?string $h) => trim((string) $h), $header);
        if (count(array_unique($header)) !== count($header)) {
            throw new InvalidArgumentException('CSV header has duplicate column names.');
        }

        $columns = array_fill_keys($header, []);
        $line = 1;
        while (($record = $this->next()) !== null) {
            $line++;
            if ($record === [null]) {
                continue; // blank line
            }
            if (count($record) !== count($header)) {
                throw new InvalidArgumentException(
                    "CSV line {$line} has ".count($record).' fields; the header has '.count($header).'.'
                );
            }
            foreach ($header as $i => $name) {
                $columns[$name][] = $record[$i];
            }
        }
        fclose($this->handle);

        foreach (array_keys($this->types) as $name) {
            if (! array_key_exists($name, $columns)) {
                throw new InvalidArgumentException("Type given for unknown CSV column '{$name}'.");
            }
        }

        $parsed = [];
        foreach ($columns as $name => $values) {
            $parsed[$name] = Infer::parse($values, $this->types[$name] ?? null, $this->na, (string) $name);
        }

        return Frame::fromColumns($parsed);
    }

    /** @return list<?string>|null */
    private function next(): ?array
    {
        $record = fgetcsv($this->handle, null, $this->delimiter, '"', '');

        return $record === false ? null : $record;
    }
}
