<?php

declare(strict_types=1);

namespace Phibble;

use Closure;
use InvalidArgumentException;

/**
 * Column name repair, a port of vctrs::vec_as_names() as used by tibble
 * and readr. Modes:
 *
 * - minimal: names are only made non-null.
 * - unique: empty, `...`, `..j` and duplicated names get a `...n` suffix,
 *   where n is the 1-based position. Existing `...n` suffixes are stripped
 *   first, so repair is idempotent.
 * - check_unique: throw unless the names are already unique and non-empty.
 * - universal: unique, and syntactic in R (letters, digits, `.` and `_`,
 *   not starting with a digit or `_`, not a reserved word).
 *
 * A closure receives the names and returns the repaired list.
 */
final class NameRepair
{
    private const array RESERVED = [
        'if', 'else', 'repeat', 'while', 'function', 'for', 'next', 'break',
        'TRUE', 'FALSE', 'NULL', 'Inf', 'NaN', 'NA', 'NA_integer_', 'NA_real_',
        'NA_character_', 'NA_complex_', 'in',
    ];

    /**
     * @param list<string|null> $names
     * @param string|Closure(list<string>): list<string> $mode
     * @return list<string>
     */
    public static function repair(array $names, string|Closure $mode = 'check_unique'): array
    {
        $names = array_map(fn ($n) => $n === null ? '' : (string) $n, array_values($names));

        if ($mode instanceof Closure) {
            $repaired = array_values(array_map(strval(...), $mode($names)));
            if (count($repaired) !== count($names)) {
                throw new InvalidArgumentException('A name repair function must return one name per column.');
            }

            return $repaired;
        }

        return match ($mode) {
            'minimal' => $names,
            'unique' => self::unique($names),
            'check_unique' => self::checkUnique($names),
            'universal' => self::universal($names),
            'unique_quiet' => self::unique($names),
            'universal_quiet' => self::universal($names),
            default => throw new InvalidArgumentException(
                "Unknown name repair '{$mode}'. Use minimal, unique, check_unique or universal."
            ),
        };
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function unique(array $names): array
    {
        $naked = array_map(function (string $name): string {
            if ($name === '...' || preg_match('/^\.\.\d+$/', $name)) {
                return '';
            }

            return (string) preg_replace('/(\.\.\.\d+)+$/', '', $name);
        }, $names);

        return self::appendPositions($naked, array_map(fn (string $n) => $n === '', $naked));
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function universal(array $names): array
    {
        $new = [];
        $empty = [];
        foreach ($names as $i => $name) {
            $name = (string) preg_replace('/^(\.\.[1-9]\d*)$/', '.$1', $name);
            $name = (string) preg_replace('/(\.\.\.[1-9]\d*)+$/', '', $name);
            $empty[$i] = $name === '' || $name === '...';
            $new[$i] = $empty[$i] ? '' : self::syntactic($name);
        }

        return self::appendPositions($new, $empty);
    }

    /**
     * Add `...n` to empty names and to every copy of a duplicated name.
     *
     * @param list<string> $names
     * @param list<bool> $needsSuffix
     * @return list<string>
     */
    private static function appendPositions(array $names, array $needsSuffix): array
    {
        $counts = array_count_values(array_map(fn (string $n) => "\0".$n, $names));
        foreach ($names as $i => $name) {
            if ($needsSuffix[$i] || $counts["\0".$name] > 1) {
                $names[$i] = $name.'...'.($i + 1);
            }
        }

        return $names;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function checkUnique(array $names): array
    {
        foreach ($names as $i => $name) {
            if ($name === '') {
                throw new InvalidArgumentException('Names can\'t be empty. Empty name found at location '.($i + 1).'.');
            }
            if ($name === '...' || preg_match('/^\.\.\d+$/', $name)) {
                throw new InvalidArgumentException("Names can't be of the form `...` or `..j`. Found \"{$name}\" at location ".($i + 1).'.');
            }
        }
        $seen = [];
        foreach ($names as $i => $name) {
            $seen[$name][] = $i + 1;
        }
        foreach ($seen as $name => $locations) {
            if (count($locations) > 1) {
                throw new InvalidArgumentException(
                    "Names must be unique. \"{$name}\" is duplicated at locations ".implode(' and ', $locations).'.'
                );
            }
        }

        return $names;
    }

    /** vctrs:::make_syntactic() for one non-empty name. */
    private static function syntactic(string $name): string
    {
        if ($name === '') {
            $name = '.';
        }
        if ($name === '...') {
            $name = '....';
        }
        $name = (string) preg_replace('/^_/', '._', $name);

        $new = self::makeNames($name);
        if (str_starts_with($new, 'X') && ! str_starts_with($name, 'X')) {
            $new = substr($new, 1);
        }
        if ($new === $name.'.') {
            $new = '.'.substr($new, 0, -1);
        }

        preg_match('/^(\.{0,2})(\d*)(.*)$/su', $new, $m);
        [, $dots, $numbers, $rest] = $m;
        if ($numbers !== '') {
            $dots = $rest === '' ? '...' : '..';
        }

        return $dots.$numbers.$rest;
    }

    /** R's make.names() for one name. */
    private static function makeNames(string $name): string
    {
        $new = (string) preg_replace('/[^\p{L}\p{N}._]/u', '.', $name);
        if (! preg_match('/^(\p{L}|\.(?!\d))/u', $new)) {
            $new = 'X'.$new;
        }
        if (in_array($new, self::RESERVED, true)) {
            $new .= '.';
        }

        return $new;
    }
}
