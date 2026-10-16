<?php

declare(strict_types=1);

namespace Phibble\Expr;

/**
 * A string pattern: a regular expression (stringr's default, PCRE here)
 * or fixed text, from fixed('...') or regex('...', ignore_case: true).
 *
 * @internal
 */
final readonly class Pattern
{
    public function __construct(
        public string $pattern,
        public bool $fixed = false,
        public bool $ignoreCase = false,
    ) {}

    public static function of(mixed $value): self
    {
        return $value instanceof self ? $value : new self((string) $value);
    }

    /** The PCRE form, matching by Unicode code point. */
    public function regex(string $prefix = '', string $suffix = ''): string
    {
        $body = $this->fixed ? preg_quote($this->pattern, "\x01") : $this->pattern;

        $regex = "\x01{$prefix}(?:{$body}){$suffix}\x01u".($this->ignoreCase ? 'i' : '');

        return self::checked($regex);
    }

    /** Compile a regex once, quietly; an invalid one throws instead of warning. */
    private static function checked(string $regex): string
    {
        static $valid = [];
        if (! isset($valid[$regex])) {
            set_error_handler(fn () => true);
            try {
                $ok = preg_match($regex, '') !== false;
            } finally {
                restore_error_handler();
            }
            if (! $ok) {
                throw new \InvalidArgumentException('Invalid regular expression: '.preg_last_error_msg().'.');
            }
            $valid[$regex] = true;
        }

        return $regex;
    }
}
