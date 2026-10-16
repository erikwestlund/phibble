<?php

declare(strict_types=1);

namespace Phibble\Expr;

use InvalidArgumentException;

// Helpers in this namespace shadow these built-ins (see helpers.php).
use function max;
use function str_pad;
use function str_replace;

/**
 * stringr's basics, value by value. Patterns are regular expressions
 * unless wrapped in fixed(), as in stringr; matching is by Unicode code
 * point. NA in gives NA out.
 *
 * @internal
 */
final class Strings
{
    public static function detect(string $s, Pattern $p, string $anchor = ''): ?bool
    {
        if (self::emptyFixed($p)) {
            return null;
        }
        if ($p->fixed && ! $p->ignoreCase) {
            return match ($anchor) {
                'start' => str_starts_with($s, $p->pattern),
                'end' => str_ends_with($s, $p->pattern),
                default => str_contains($s, $p->pattern),
            };
        }

        return self::match($p->regex($anchor === 'start' ? '^' : '', $anchor === 'end' ? '\z' : ''), $s) === 1;
    }

    public static function count(string $s, Pattern $p): ?int
    {
        if (self::emptyFixed($p)) {
            return null;
        }
        if ($p->fixed && ! $p->ignoreCase) {
            return substr_count($s, $p->pattern);
        }

        return (int) self::guard(preg_match_all($p->regex(), $s));
    }

    public static function extract(string $s, Pattern $p): ?string
    {
        return self::guard(preg_match($p->regex(), $s, $m)) === 1 ? $m[0] : null;
    }

    /** @return list<string> */
    public static function extractAll(string $s, Pattern $p): array
    {
        self::guard(preg_match_all($p->regex(), $s, $m));

        return $m[0];
    }

    /** @return list<string> */
    public static function split(string $s, Pattern $p): array
    {
        if ($p->fixed && ! $p->ignoreCase) {
            return $p->pattern === '' ? mb_str_split($s) : explode($p->pattern, $s);
        }

        return self::guard(preg_split($p->regex(), $s));
    }

    /** The i-th piece (1-based; negative counts from the end); NA when out of range. */
    public static function splitPiece(string $s, Pattern $p, int $i): ?string
    {
        $pieces = self::split($s, $p);
        $index = $i > 0 ? $i - 1 : count($pieces) + $i;

        return $i !== 0 && $index >= 0 && $index < count($pieces) ? $pieces[$index] : null;
    }

    /** str_replace() / str_replace_all(); \1 in the replacement refers to a group, as in stringr. */
    public static function replace(string $s, Pattern $p, ?string $replacement, bool $all): ?string
    {
        if (self::emptyFixed($p)) {
            return null;
        }
        if ($replacement === null) {
            return self::detect($s, $p) ? null : $s;
        }
        if ($p->fixed && ! $p->ignoreCase) {
            if ($all) {
                return str_replace($p->pattern, $replacement, $s);
            }
            $pos = strpos($s, $p->pattern);

            return $pos === false ? $s : substr_replace($s, $replacement, $pos, strlen($p->pattern));
        }
        // Substitute \1-style group references ourselves, so "$" stays literal.
        return self::guard(preg_replace_callback(
            $p->regex(),
            fn (array $m) => (string) preg_replace_callback('/\\\\(\d)/', fn (array $g) => $m[(int) $g[1]] ?? '', $replacement),
            $s,
            $all ? -1 : 1,
        ));
    }

    public static function pad(string $s, int $width, string $side, string $pad): string
    {
        $missing = $width - mb_strlen($s);
        if ($missing <= 0) {
            return $s;
        }

        return match ($side) {
            'left' => str_repeat($pad, $missing).$s,
            'right' => $s.str_repeat($pad, $missing),
            'both' => str_repeat($pad, intdiv($missing, 2)).$s.str_repeat($pad, $missing - intdiv($missing, 2)),
            default => throw new InvalidArgumentException("str_pad() side must be left, right or both, got '{$side}'."),
        };
    }

    public static function truncate(string $s, int $width, string $side, string $ellipsis): string
    {
        $length = mb_strlen($s);
        if ($length <= $width) {
            return $s;
        }
        $keep = max(0, $width - mb_strlen($ellipsis));

        return match ($side) {
            'right' => mb_substr($s, 0, $keep).$ellipsis,
            'left' => $ellipsis.mb_substr($s, $length - $keep),
            'center' => mb_substr($s, 0, (int) ceil($keep / 2)).$ellipsis.mb_substr($s, $length - intdiv($keep, 2)),
            default => throw new InvalidArgumentException("str_trunc() side must be right, left or center, got '{$side}'."),
        };
    }

    /** Title case as stringr (ICU) does: a word starts after a non-letter, but not after an apostrophe. */
    public static function title(string $s): string
    {
        return (string) preg_replace_callback(
            "/(?<![\\p{L}\\p{N}'’])\\p{L}/u",
            fn (array $m) => mb_strtoupper($m[0]),
            mb_strtolower($s),
        );
    }

    public static function squish(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** stringr gives NA for an empty fixed() pattern ("empty search patterns are not supported"). */
    private static function emptyFixed(Pattern $p): bool
    {
        return $p->fixed && $p->pattern === '';
    }

    private static function match(string $regex, string $s): int
    {
        return (int) self::guard(preg_match($regex, $s));
    }

    private static function guard(mixed $result): mixed
    {
        if ($result === false || $result === null) {
            throw new InvalidArgumentException('Invalid regular expression: '.preg_last_error_msg().'.');
        }

        return $result;
    }
}
