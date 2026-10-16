<?php

declare(strict_types=1);

namespace Phibble\Io;

use Closure;
use InvalidArgumentException;
use Phibble\Type;

/**
 * Options for reading delimited text, after readr::read_delim().
 *
 * readr name     → option
 * col_types      → types: compact string ("icdlDTfn_?"), or name => spec
 *                  (".default" for the rest), or a list of specs by position.
 *                  A spec is a Type, a type name or readr letter, or
 *                  ['factor', 'levels' => [...], 'ordered' => bool].
 * col_select     → select: names to keep, in this order.
 * col_names      → colNames: true (header row), false (X1, X2, ...) or a list.
 * na, skip, n_max, quote, comment, trim_ws, skip_empty_rows, guess_max,
 * escape_double, escape_backslash, name_repair, locale(decimal_mark,
 * grouping_mark) → the camelCase equivalents.
 */
final readonly class CsvOptions
{
    /**
     * @param string|array<int|string, Type|string|array<mixed>> $types
     * @param list<string> $na
     * @param bool|list<string> $colNames
     * @param list<string>|null $select
     * @param string|Closure(list<string>): list<string> $nameRepair
     */
    public function __construct(
        public string|array $types = [],
        public string $delimiter = ',',
        public array $na = ['', 'NA'],
        public bool|array $colNames = true,
        public ?array $select = null,
        public int $skip = 0,
        public ?int $nMax = null,
        public string $quote = '"',
        public bool $escapeDouble = true,
        public bool $escapeBackslash = false,
        public string $comment = '',
        public bool $trimWs = true,
        public bool $skipEmptyRows = true,
        public int $guessMax = 1000,
        public string|Closure $nameRepair = 'unique',
        public string $decimalMark = '.',
        public ?string $groupingMark = null,
    ) {
        if (strlen($delimiter) !== 1) {
            throw new InvalidArgumentException('The delimiter must be a single character.');
        }
        if (strlen($quote) > 1) {
            throw new InvalidArgumentException('The quote must be a single character, or empty for none.');
        }
        if ($skip < 0 || ($nMax !== null && $nMax < 0) || $guessMax < 1) {
            throw new InvalidArgumentException('skip and nMax must be zero or more, and guessMax at least 1.');
        }
    }
}
