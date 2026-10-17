<?php

declare(strict_types=1);

namespace Phibble\Polars;

use DateTimeInterface;
use InvalidArgumentException;
use Phibble\Exception\ColumnNotFound;
use Phibble\Expr\Call;
use Phibble\Expr\Col;
use Phibble\Expr\Desc;
use Phibble\Expr\Expr as PhibbleExpr;
use Phibble\Expr\Functions;
use Phibble\Expr\Lit;
use Phibble\Expr\Clock;
use Phibble\Expr\Pattern;
use Phibble\Expr\Period;
use Phibble\Expr\Temporal;
use Phpolars\Expr;

use function Phpolars\{concat_list, coalesce, col, concat_str, int_range, len, lit, max_horizontal, min_horizontal, struct_, when, all_horizontal};

/**
 * Translates Phibble\Expr trees into Polars expressions with dplyr's
 * semantics: NA propagates, aggregates default to na_rm = FALSE, and inside
 * a grouped mutate or filter, aggregates and window functions run per group.
 *
 * @internal
 */
final class Translator
{
    /**
     * Arguments that must hold no NA, checked before the verb runs: R's
     * quantile() raises an error on NA unless na.rm is TRUE.
     *
     * @var list<Expr>
     */
    public array $naChecks = [];

    /**
     * @param array<string, string> $schema Column name => Polars data type name.
     * @param list<string> $partition Group keys for mutate/filter; empty when ungrouped.
     */
    /**
     * @param array<string, array{levels: list<string>, ordered: bool}> $factors
     *     Factor columns; they order and rank by level, as in dplyr.
     */
    public function __construct(
        private readonly array $schema,
        private readonly array $partition = [],
        private readonly array $factors = [],
    ) {}

    /** An expression to order or rank by: a factor column by its level position. */
    private function sortable(PhibbleExpr $e, bool $inGroup): Expr
    {
        if ($e instanceof Col && isset($this->factors[$e->name])) {
            return col($e->name)->replaceStrict(array_flip($this->factors[$e->name]['levels']), default: lit(null), returnDtype: 'Int64');
        }

        return $this->node($e, $inGroup);
    }

    /** A row-level expression, as mutate() and filter() evaluate it. */
    public function row(PhibbleExpr $e): Expr
    {
        return $this->node($e, false);
    }

    /** An expression evaluated once per group, as summarise() needs. */
    public function aggregate(PhibbleExpr $e): Expr
    {
        return $this->node($e, true);
    }

    /** True when the expression yields one value per group. */
    public static function isScalar(PhibbleExpr $e): bool
    {
        return match (true) {
            $e instanceof Lit => true,
            $e instanceof Col, $e instanceof Desc => false,
            $e instanceof Call => match (Functions::kind($e->fn)) {
                'agg' => true,
                'window' => false,
                default => array_all($e->args, self::isScalar(...)),
            },
        };
    }

    /**
     * @param bool $inGroup True inside summarise(), or inside the arguments of an
     *     aggregate or window that will itself run per group.
     */
    private function node(PhibbleExpr $e, bool $inGroup): Expr
    {
        return match (true) {
            $e instanceof Col => $this->col($e->name),
            $e instanceof Lit => self::lit($e),
            $e instanceof Desc => throw new InvalidArgumentException('desc() is only allowed in arrange(), ranking functions and order_by.'),
            $e instanceof Call => $this->call($e, $inGroup),
        };
    }

    private function col(string $name): Expr
    {
        if (! array_key_exists($name, $this->schema)) {
            throw ColumnNotFound::named($name, array_keys($this->schema));
        }

        return col($name);
    }

    private static function lit(Lit $l): Expr
    {
        $v = $l->value;
        if ($v instanceof DateTimeInterface) {
            return $l->dtype === 'date' ? lit($v)->cast('Date') : lit($v);
        }

        return $v === null ? lit(null)->cast('Boolean') : lit($v);
    }

    private function call(Call $c, bool $inGroup): Expr
    {
        $kind = Functions::kind($c->fn);
        if ($kind === 'agg' || $kind === 'window') {
            $out = $kind === 'agg' ? $this->agg($c) : $this->window($c);
            if ($inGroup) {
                return $out;
            }
            $order = $c->option('order_by');
            if ($kind === 'window' && $order !== null && in_array($c->fn, ['lag', 'lead'], true)) {
                [$by, $desc] = $this->orderKey($order);

                return $out->over($this->partition ?: lit(1), orderBy: $by, descending: $desc, nullsLast: true);
            }

            return $this->partition === [] ? $out : $out->over($this->partition);
        }
        $a = fn (int $i) => $this->node($c->args[$i], $inGroup);
        $args = fn () => array_map(fn (PhibbleExpr $x) => $this->node($x, $inGroup), $c->args);

        return match ($c->fn) {
            '+', '-' => $this->plusMinus($c, $inGroup),
            '*' => $a(0)->mul($a(1)),
            '/' => $a(0)->truediv($a(1)),
            '%%' => $a(0)->mod($a(1)),
            '^' => $a(0)->cast('Float64')->pow($a(1)),
            'neg' => $a(0)->neg(),
            '==' => $a(0)->eq($a(1)),
            '!=' => $a(0)->ne($a(1)),
            '<' => $a(0)->lt($a(1)),
            '<=' => $a(0)->le($a(1)),
            '>' => $a(0)->gt($a(1)),
            '>=' => $a(0)->ge($a(1)),
            '&' => $a(0)->and($a(1)),
            '|' => $a(0)->or($a(1)),
            '!' => $a(0)->not(),
            'is_na' => $this->type($c->args[0]) === 'float' ? $a(0)->isNull()->or($a(0)->isNan()) : $a(0)->isNull(),
            'if_else' => when($a(0)->isNull())->then($this->optional($c, 'missing', $inGroup) ?? lit(null))
                ->when($a(0))->then($a(1))->otherwise($a(2)),
            'case_when' => $this->caseWhen($c, $inGroup),
            'coalesce' => coalesce(...$args()),
            'in' => $a(0)->isIn($this->setValues($c->args[1]), nullsEqual: true),
            'between' => $a(0)->isBetween($a(1), $a(2)),
            'na_if' => when($a(0)->eq($a(1)))->then(lit(null))->otherwise($a(0)),
            'near' => $a(0)->sub($a(1))->abs()->lt($this->optional($c, 'tol', $inGroup) ?? lit(sqrt(PHP_FLOAT_EPSILON))),
            'abs' => $a(0)->abs(),
            'sqrt' => $a(0)->cast('Float64')->sqrt(),
            'exp' => $a(0)->cast('Float64')->exp(),
            'log' => $a(0)->cast('Float64')->log((float) ($this->literal($c, 'base') ?? M_E)),
            'log10' => $a(0)->cast('Float64')->log10(),
            'round' => $this->round($c, $a(0)),
            'floor' => $a(0)->cast('Float64')->floor(),
            'ceiling' => $a(0)->cast('Float64')->ceil(),
            'as_int' => $this->type($c->args[0]) === 'string'
                ? $a(0)->str()->stripChars()->cast('Float64', strict: false)->cast('Int64', strict: false)
                : $a(0)->cast('Int64', strict: false),
            'as_float' => $a(0)->cast('Float64', strict: false),
            'as_string' => $this->asString($c->args[0], $a(0)),
            'as_bool' => $this->type($c->args[0]) === 'string'
                ? $a(0)->replaceStrict(['TRUE' => true, 'true' => true, 'T' => true, 'True' => true, 'FALSE' => false, 'false' => false, 'F' => false, 'False' => false], default: lit(null)->cast('Boolean'), returnDtype: 'Boolean')
                : $a(0)->cast('Boolean', strict: false),
            'str_lower' => $a(0)->str()->toLowercase(),
            'str_upper' => $a(0)->str()->toUppercase(),
            'str_length' => $a(0)->str()->lenChars()->cast('Int64'),
            'str_trim' => match ($this->literal($c, 'side') ?? 'both') {
                'left' => $a(0)->str()->stripCharsStart(),
                'right' => $a(0)->str()->stripCharsEnd(),
                default => $a(0)->str()->stripChars(),
            },
            'str_sub' => $this->strSub($a(0), $a(1), isset($c->args[2]) ? $a(2) : lit(-1)),
            'str_detect', 'str_starts', 'str_ends' => $this->negate($c, $this->detect($a(0), $c->args[1], $c->fn)),
            'str_replace', 'str_replace_all' => $this->replace($a(0), $c->args[1], $c->args[2], $c->fn === 'str_replace_all'),
            'fixed', 'regex' => throw new Unsupported("{$c->fn}() is only supported as a pattern argument."),
            'str_count' => $this->count($a(0), $c->args[1]),
            'str_extract' => $this->extract($a(0), $c->args[1]),
            'str_extract_all' => $this->listOrNa($a(0), $this->extractAll($a(0), $c->args[1])),
            'str_split' => $this->listOrNa($a(0), $this->split($a(0), $c->args[1])),
            'str_split_i' => $this->splitPiece($a(0), $c->args[1], $c->args[2]),
            'str_pad' => $this->pad($c, $a(0)),
            'str_trunc' => $this->truncate($c, $a(0)),
            // stringr's title case follows ICU word breaks (no capital after an
            // apostrophe); Polars has no title-casing that matches it.
            'str_to_title' => throw new Unsupported('str_to_title() needs ICU word breaks.'),
            'str_to_upper' => $a(0)->str()->toUppercase(),
            'str_to_lower' => $a(0)->str()->toLowercase(),
            'str_squish' => $a(0)->str()->stripChars()->str()->replaceAll('\\s+', ' '),
            'ymd', 'dmy', 'mdy' => $this->parseDate($c, $a(0)),
            'ymd_hms', 'dmy_hms', 'mdy_hms' => $this->parseDateTime($c, $a(0)),
            'parse_date_time' => $this->parseOrders($c, $a(0)),
            'year' => $a(0)->dt()->year()->cast('Int64'),
            'month' => $a(0)->dt()->month()->cast('Int64'),
            'day' => $a(0)->dt()->day()->cast('Int64'),
            'yday' => $a(0)->dt()->ordinalDay()->cast('Int64'),
            'quarter' => $a(0)->dt()->quarter()->cast('Int64'),
            'hour' => $a(0)->dt()->hour()->cast('Int64'),
            'minute' => $a(0)->dt()->minute()->cast('Int64'),
            'second' => $a(0)->dt()->second()->cast('Float64')->add($a(0)->dt()->microsecond()->cast('Float64')->truediv(1e6)),
            // R: ((w - week_start %% 7 + 7) %% 7) + 1, with w = 0 for Sunday.
            'wday' => $a(0)->dt()->weekday()->cast('Int64')->mod(7)
                ->sub((int) ($this->literal($c, 'week_start') ?? 7) % 7)->add(7)->mod(7)->add(1),
            'floor_date', 'ceiling_date', 'round_date' => $this->roundDate($c, $a(0)),
            'seconds', 'minutes', 'hours', 'days', 'weeks', 'months', 'years' => throw new Unsupported('A period is only supported added to or subtracted from a date.'),
            'difftime' => $this->difference($c->args[0], $c->args[1], (string) ($this->literal($c, 'units') ?? 'days'), $inGroup),
            'with_tz' => $this->zoned($c->args[0], $a(0))->dt()->convertTimeZone((string) $this->scalarArg($c->args[1])),
            'force_tz' => $this->naive($c->args[0], $a(0))->dt()->replaceTimeZone((string) $this->scalarArg($c->args[1]), ambiguous: 'earliest', nonExistent: 'null'),
            'today' => $this->today($c),
            'now' => $this->now($c),
            'paste0' => $this->paste($c->args, '', $inGroup),
            'paste' => $this->paste($c->args, (string) ($this->literal($c, 'sep') ?? ' '), $inGroup),
            default => throw new InvalidArgumentException("Unknown function '{$c->fn}'."),
        };
    }

    // Strings: patterns are regular expressions unless wrapped in fixed().

    /**
     * A pattern argument as [pattern, literal]. A plain string is a regex;
     * fixed() is literal; regex(ignore_case: true) adds (?i).
     *
     * @return array{0: string, 1: bool}
     */
    private function pattern(PhibbleExpr $node): array
    {
        [$text, $fixed, $ignoreCase] = match (true) {
            $node instanceof Lit && is_string($node->value) => [$node->value, false, false],
            $node instanceof Call && $node->fn === 'fixed' => [(string) $this->scalarArg($node->args[0]), true, false],
            $node instanceof Call && $node->fn === 'regex' => [(string) $this->scalarArg($node->args[0]), false, (bool) ($this->literal($node, 'ignore_case') ?? false)],
            default => throw new Unsupported('Patterns must be literal strings, fixed() or regex().'),
        };
        // phibble's own check, so invalid patterns raise its error message.
        (new Pattern($text, $fixed, $ignoreCase))->regex();
        if ($fixed && ! $ignoreCase) {
            return [$text, true];
        }
        $body = $fixed ? preg_quote($text) : $text;
        // Rust's regex engine has no lookaround, backreferences, atomic groups
        // or possessive quantifiers; PCRE patterns using them run on a DataFrame.
        if (! $fixed && preg_match('/\(\?<?[=!]|\(\?>|\\[1-9]|\\[gkK]|[*+?}]\+/', $body)) {
            throw new Unsupported("The pattern '{$text}' needs PCRE features Polars lacks.");
        }

        return [($ignoreCase ? '(?i)' : '').$body, false];
    }

    private function detect(Expr $s, PhibbleExpr $node, string $fn): Expr
    {
        [$p, $literal] = $this->pattern($node);
        if ($literal) {
            return match ($fn) {
                'str_starts' => $s->str()->startsWith($p),
                'str_ends' => $s->str()->endsWith($p),
                default => $s->str()->contains($p, literal: true),
            };
        }

        return $s->str()->contains(match ($fn) {
            'str_starts' => "^(?:{$p})",
            'str_ends' => "(?:{$p})\\z",
            default => $p,
        });
    }

    private function count(Expr $s, PhibbleExpr $node): Expr
    {
        [$p, $literal] = $this->pattern($node);

        return $literal && $p === '' ? when($s->isNull())->then(lit(null))->otherwise(lit(0)) : $s->str()->countMatches($p, literal: $literal)->cast('Int64');
    }

    private function extract(Expr $s, PhibbleExpr $node): Expr
    {
        [$p, $literal] = $this->pattern($node);

        return $s->str()->extract($literal ? preg_quote($p) : $p, 0);
    }

    private function extractAll(Expr $s, PhibbleExpr $node): Expr
    {
        [$p, $literal] = $this->pattern($node);

        return $s->str()->extractAll($literal ? preg_quote($p) : $p);
    }

    private function split(Expr $s, PhibbleExpr $node): Expr
    {
        [$p, $literal] = $this->pattern($node);
        if ($p === '') {
            throw new Unsupported('Splitting into characters runs on a DataFrame.');
        }

        return $s->str()->split($p, literal: $literal);
    }

    /** stringr gives NA for an NA string as a one-element list: [NA]. */
    private function listOrNa(Expr $s, Expr $list): Expr
    {
        return when($s->isNull())->then(concat_list(lit(null)->cast('String')))->otherwise($list);
    }

    private function splitPiece(Expr $s, PhibbleExpr $pattern, PhibbleExpr $index): Expr
    {
        $i = (int) $this->scalarArg($index);
        if ($i === 0) {
            return lit(null)->cast('String');
        }

        return $this->split($s, $pattern)->list()->get($i > 0 ? $i - 1 : $i, nullOnOob: true);
    }

    /** str_replace(): \1 refers to a group; a NA replacement makes matched strings NA. */
    private function replace(Expr $s, PhibbleExpr $node, PhibbleExpr $with, bool $all): Expr
    {
        [$p, $literal] = $this->pattern($node);
        if (! $with instanceof Lit) {
            throw new Unsupported('Replacements must be literal strings.');
        }
        if ($with->value === null) {
            return when($this->detect($s, $node, 'str_detect'))->then(lit(null)->cast('String'))->otherwise($s);
        }
        $r = (string) $with->value;
        if ($literal && $p === '') {
            return $s;
        }
        if (! $literal) {
            // Polars expands $1; stringr expands \1 and keeps $ literal.
            $r = (string) preg_replace_callback('/\\\\(\d)/', fn (array $m) => '${'.$m[1].'}', str_replace('$', '$$', $r));
        }

        return $all
            ? $s->str()->replaceAll($p, $r, literal: $literal)
            : $s->str()->replace($p, $r, literal: $literal, n: 1);
    }

    private function pad(Call $c, Expr $s): Expr
    {
        $width = (int) $this->scalarArg($c->args[1]);
        $pad = (string) ($this->literal($c, 'pad') ?? ' ');
        if (mb_strlen($pad) !== 1) {
            throw new Unsupported('str_pad() with a multi-character pad runs on a DataFrame.');
        }

        return match ($this->literal($c, 'side') ?? 'left') {
            'left' => $s->str()->padStart($width, $pad),
            'right' => $s->str()->padEnd($width, $pad),
            'both' => $s->str()->padStart(max_horizontal($s->str()->lenChars()->cast('Int64'), lit($width)->sub($s->str()->lenChars()->cast('Int64'))->floordiv(2)->add($s->str()->lenChars()->cast('Int64'))), $pad)
                ->str()->padEnd($width, $pad),
            default => throw new InvalidArgumentException('str_pad() side must be left, right or both.'),
        };
    }

    private function truncate(Call $c, Expr $s): Expr
    {
        $width = (int) $this->scalarArg($c->args[1]);
        $ellipsis = (string) ($this->literal($c, 'ellipsis') ?? '...');
        $keep = max(0, $width - mb_strlen($ellipsis));
        $n = $s->str()->lenChars()->cast('Int64');
        $cut = match ($this->literal($c, 'side') ?? 'right') {
            'right' => concat_str([$s->str()->slice(0, $keep), lit($ellipsis)]),
            'left' => concat_str([lit($ellipsis), $s->str()->slice($n->sub($keep), $keep)]),
            'center' => concat_str([$s->str()->slice(0, (int) ceil($keep / 2)), lit($ellipsis), $s->str()->slice($n->sub(intdiv($keep, 2)), intdiv($keep, 2))]),
            default => throw new InvalidArgumentException('str_trunc() side must be right, left or center.'),
        };

        return when($n->le($width))->then($s)->otherwise($cut);
    }

    // Dates and times, with lubridate's semantics.

    /** ymd(), dmy(), mdy(): digits run together or separated by non-digits. */
    private function parseDate(Call $c, Expr $text): Expr
    {
        if ($this->literal($c, 'tz') !== null) {
            throw new Unsupported('Parsing a date into a time zone runs on a DataFrame.');
        }
        [$regex, $letters] = self::dateRegex($c->fn);

        return $this->assemble($text->str()->stripChars(), $regex, $letters, false);
    }

    private function parseDateTime(Call $c, Expr $text): Expr
    {
        if ($this->literal($c, 'tz') !== null) {
            throw new Unsupported('Parsing a datetime into a time zone runs on a DataFrame.');
        }
        $order = substr($c->fn, 0, 3);
        [$date, $letters] = self::dateRegex($order);
        $clock = '(?:(\\d{2})(\\d{2})(\\d{2}(?:\\.\\d+)?)|(\\d{1,2})\\D+(\\d{1,2})\\D+(\\d{1,2}(?:\\.\\d+)?))';
        // Date and clock are separated by non-digits unless the text is all digits.
        $text = $text->str()->stripChars();
        $digits = $this->assemble($text, '^'.substr($date, 1, -1).$clock.'$', [...$letters, 'H' => [7, 10], 'M' => [8, 11], 'S' => [9, 12]], true);
        $spaced = $this->assemble($text, '^'.substr($date, 1, -1).'\\D+'.$clock.'$', [...$letters, 'H' => [7, 10], 'M' => [8, 11], 'S' => [9, 12]], true);

        return when($text->str()->contains('^\\d+$'))->then($digits)->otherwise($spaced);
    }

    /** @return array{0: string, 1: array<string, list<int>>} */
    private static function dateRegex(string $order): array
    {
        $regex = [
            'ymd' => '^(?:(\\d{4})(\\d{2})(\\d{2})|(\\d{4})\\D+(\\d{1,2})\\D+(\\d{1,2}))$',
            'dmy' => '^(?:(\\d{2})(\\d{2})(\\d{4})|(\\d{1,2})\\D+(\\d{1,2})\\D+(\\d{4}))$',
            'mdy' => '^(?:(\\d{2})(\\d{2})(\\d{4})|(\\d{1,2})\\D+(\\d{1,2})\\D+(\\d{4}))$',
        ][$order];
        $letters = match ($order) {
            'ymd' => ['Y' => [1, 4], 'm' => [2, 5], 'd' => [3, 6]],
            'dmy' => ['d' => [1, 4], 'm' => [2, 5], 'Y' => [3, 6]],
            'mdy' => ['m' => [1, 4], 'd' => [2, 5], 'Y' => [3, 6]],
        };

        return [$regex, $letters];
    }

    /** parse_date_time(x, orders): the first order that parses wins. */
    private function parseOrders(Call $c, Expr $text): Expr
    {
        if ($this->literal($c, 'tz') !== null) {
            throw new Unsupported('Parsing a datetime into a time zone runs on a DataFrame.');
        }
        $orders = (array) $this->scalarArg($c->args[1]);
        $parsed = [];
        foreach ($orders as $order) {
            $letters = str_split(str_replace([' ', '-', '/', ':', '_'], '', (string) $order));
            $regex = '';
            $groups = [];
            foreach ($letters as $i => $letter) {
                $regex .= ($i === 0 ? '' : '\\D*').match ($letter) {
                    'Y' => '(\\d{4})',
                    'y' => '(\\d{2})',
                    'm', 'd', 'H', 'M' => '(\\d{1,2})',
                    'S' => '(\\d{1,2}(?:\\.\\d+)?)',
                    default => throw new InvalidArgumentException("parse_date_time() does not support the order letter '{$letter}'."),
                };
                $groups[$letter] = [$i + 1];
            }
            $parsed[] = $this->assemble($text, '^\\s*'.$regex.'\\s*$', $groups, true);
        }

        return coalesce(...$parsed);
    }

    /**
     * Build a Date or Datetime from regex groups. Each letter takes the first
     * non-null of its groups; invalid calendar dates and clock values are NA.
     *
     * @param array<string, list<int>> $letters
     */
    private function assemble(Expr $text, string $regex, array $letters, bool $time): Expr
    {
        $part = function (string $letter, string $default) use ($text, $regex, $letters): Expr {
            if (! isset($letters[$letter])) {
                return lit($default);
            }

            return coalesce(...array_map(fn (int $g) => $text->str()->extract($regex, $g), $letters[$letter]));
        };
        $year = $part('Y', '0');
        if (isset($letters['y'])) {
            $yy = $part('y', '0')->cast('Int64', strict: false);
            $year = when($yy->le(68))->then($yy->add(2000))->otherwise($yy->add(1900))->cast('String');
        }
        $two = fn (Expr $e) => $e->str()->zfill(2);
        $pieces = [$year, lit('-'), $two($part('m', '1')), lit('-'), $two($part('d', '1'))];
        if (! $time) {
            return concat_str($pieces)->str()->toDate('%Y-%m-%d', strict: false);
        }
        // Seconds may carry a fraction; pad the whole part to two digits.
        $seconds = $part('S', '0');
        $seconds = when($seconds->str()->contains('^\\d(\\.|$)'))->then(concat_str([lit('0'), $seconds]))->otherwise($seconds);
        $pieces = [...$pieces, lit(' '), $two($part('H', '0')), lit(':'), $two($part('M', '0')), lit(':'), $seconds];

        return concat_str($pieces)->str()->toDatetime('%Y-%m-%d %H:%M:%S%.f', timeUnit: 'us', strict: false);
    }

    /** floor_date(), ceiling_date(), round_date() with lubridate's units. */
    private function roundDate(Call $c, Expr $x): Expr
    {
        [$n, $unit] = self::timeUnit((string) $this->scalarArg($c->args[1]));
        $isDate = $this->type($c->args[0]) === 'date';
        $weekStart = (int) ($this->literal($c, 'week_start') ?? 7);
        if ($n > 1 && ! (in_array($unit, ['second', 'minute'], true) && 60 % $n === 0) && ! ($unit === 'hour' && 24 % $n === 0)) {
            throw new Unsupported("Rounding to {$n} {$unit}s runs on a DataFrame.");
        }
        if ($isDate && in_array($unit, ['second', 'minute', 'hour'], true)) {
            throw new Unsupported('Rounding a date to a time of day runs on a DataFrame.');
        }
        $every = $n.['second' => 's', 'minute' => 'm', 'hour' => 'h', 'day' => 'd', 'week' => 'w', 'month' => 'mo', 'quarter' => 'q', 'year' => 'y'][$unit];
        if ($unit === 'week') {
            // Polars weeks start on Monday; shift so they start on week_start.
            $shift = ($weekStart % 7 + 6) % 7;
            $floor = $x->dt()->offsetBy("-{$shift}d")->dt()->truncate('1w')->dt()->offsetBy("{$shift}d");
        } else {
            $floor = $x->dt()->truncate($every);
        }
        if ($c->fn === 'floor_date') {
            return $floor;
        }
        $next = $floor->dt()->offsetBy($every);
        // Dates always move past a boundary (lubridate's change_on_boundary for Date).
        $ceiling = $isDate ? $next : when($floor->eq($x))->then($floor)->otherwise($next);
        if ($c->fn === 'ceiling_date') {
            return $ceiling;
        }

        return when($ceiling->sub($x)->le($x->sub($floor)))->then($ceiling)->otherwise($floor);
    }

    /** @return array{0: int, 1: string} */
    private static function timeUnit(string $unit): array
    {
        if (! preg_match('/^\s*(\d+)?\s*([a-z]+?)s?\s*$/i', $unit, $m)) {
            throw new InvalidArgumentException("Unknown time unit '{$unit}'.");
        }
        $name = match (strtolower($m[2])) {
            'sec' => 'second',
            'min' => 'minute',
            'bimonth' => 'month',
            default => strtolower($m[2]),
        };
        if (! in_array($name, ['second', 'minute', 'hour', 'day', 'week', 'month', 'quarter', 'year'], true)) {
            throw new InvalidArgumentException("Unknown time unit '{$unit}'.");
        }
        $n = $m[1] === '' ? 1 : (int) $m[1];

        return [strtolower($m[2]) === 'bimonth' ? 2 * $n : max(1, $n), $name];
    }

    /** + and -: numbers, a date or datetime with a period, or the difference of two dates. */
    private function plusMinus(Call $c, bool $inGroup): Expr
    {
        [$l, $r] = $c->args;
        $period = self::period($r) ?? ($c->fn === '+' ? self::period($l) : null);
        if ($period !== null) {
            $base = self::period($r) !== null ? $l : $r;
            [$amount, $unit] = $period;

            return $this->shift($this->node($base, $inGroup), $base, $c->fn === '-' ? -$amount : $amount, $unit);
        }
        $lt = $this->type($l);
        $rt = $this->type($r);
        if ($c->fn === '-' && in_array($lt, ['date', 'datetime'], true) && in_array($rt, ['date', 'datetime'], true)) {
            return $this->difference($l, $r, $lt === 'date' && $rt === 'date' ? 'days' : 'secs', $inGroup);
        }
        $a = $this->node($l, $inGroup);
        $b = $this->node($r, $inGroup);

        return $c->fn === '+' ? $a->add($b) : $a->sub($b);
    }

    /** @return array{0: int|float, 1: string}|null A literal period: days(3), 2 * months(1). */
    private static function period(PhibbleExpr $e): ?array
    {
        if (! $e instanceof Call) {
            return null;
        }
        if (in_array($e->fn, Period::UNITS, true)) {
            if (! $e->args[0] instanceof Lit || ! is_numeric($e->args[0]->value)) {
                throw new Unsupported('Periods with computed lengths run on a DataFrame.');
            }

            return [$e->args[0]->value + 0, $e->fn];
        }
        if ($e->fn === '*') {
            foreach ([[0, 1], [1, 0]] as [$p, $k]) {
                $inner = self::period($e->args[$p]);
                if ($inner !== null && $e->args[$k] instanceof Lit && is_numeric($e->args[$k]->value)) {
                    return [$inner[0] * ($e->args[$k]->value + 0), $inner[1]];
                }
            }
        }

        return null;
    }

    /** A date plus a period. Months and years that land on an invalid date are NA, as in lubridate. */
    private function shift(Expr $x, PhibbleExpr $node, int|float $amount, string $unit): Expr
    {
        $isDate = $this->type($node) === 'date';
        if (in_array($unit, ['months', 'years'], true)) {
            $months = (int) $amount * ($unit === 'years' ? 12 : 1);
            $moved = $x->dt()->offsetBy("{$months}mo");

            return when($moved->dt()->day()->eq($x->dt()->day()))->then($moved)->otherwise(lit(null));
        }
        if ($unit === 'days' || $unit === 'weeks') {
            $days = (int) $amount * ($unit === 'weeks' ? 7 : 1);

            return $x->dt()->offsetBy("{$days}d");
        }
        if ($isDate) {
            throw new Unsupported('Adding a clock period to a date runs on a DataFrame.');
        }
        $micros = (int) round($amount * ['hours' => 3_600_000_000, 'minutes' => 60_000_000, 'seconds' => 1_000_000][$unit]);

        return $x->dt()->offsetBy("{$micros}us");
    }

    /** difftime() and date - date: a float in the given units. */
    private function difference(PhibbleExpr $l, PhibbleExpr $r, string $units, bool $inGroup): Expr
    {
        $seconds = ['secs' => 1, 'mins' => 60, 'hours' => 3600, 'days' => 86400, 'weeks' => 604800][$units]
            ?? throw new InvalidArgumentException("difftime() units must be secs, mins, hours, days or weeks, got '{$units}'.");
        $asInstant = fn (PhibbleExpr $n) => $this->type($n) === 'date'
            ? $this->node($n, $inGroup)->cast('Datetime(us)')
            : $this->naiveUtc($n, $this->node($n, $inGroup));
        $micros = $asInstant($l)->sub($asInstant($r))->dt()->totalMicroseconds();

        return $micros->cast('Float64')->truediv($seconds * 1e6);
    }

    /** A datetime as a naive UTC instant, so differences ignore zones. */
    private function naiveUtc(PhibbleExpr $node, Expr $x): Expr
    {
        return $this->zoneOf($node) === null ? $x : $x->dt()->convertTimeZone('UTC')->dt()->replaceTimeZone(null);
    }

    /** A datetime with a time zone attached (naive values are UTC). */
    private function zoned(PhibbleExpr $node, Expr $x): Expr
    {
        return $this->zoneOf($node) === null ? $x->dt()->replaceTimeZone('UTC') : $x;
    }

    /** A datetime's wall clock without its zone. */
    private function naive(PhibbleExpr $node, Expr $x): Expr
    {
        return $this->zoneOf($node) === null ? $x : $x->dt()->replaceTimeZone(null);
    }

    /** The column's time zone, or null for naive (UTC) datetimes and computed values. */
    private function zoneOf(PhibbleExpr $node): ?string
    {
        if ($node instanceof Col && preg_match('/^Datetime\(\w+, (.+)\)$/', $this->schema[$node->name] ?? '', $m)) {
            return $m[1];
        }
        if ($node instanceof Call && in_array($node->fn, ['with_tz', 'force_tz'], true)) {
            return (string) $this->scalarArg($node->args[1]);
        }

        return null;
    }

    private function today(Call $c): Expr
    {
        $day = Temporal::today($this->literal($c, 'tz'));

        return lit(intdiv($day->getTimestamp(), 86400))->cast('Date');
    }

    private function now(Call $c): Expr
    {
        $now = Clock::now();
        $micros = $now->getTimestamp() * 1_000_000 + (int) $now->format('u');
        $tz = $this->literal($c, 'tz');
        $utc = lit($micros)->cast('Datetime(us)');

        return $tz === null ? $utc : $utc->dt()->replaceTimeZone('UTC')->dt()->convertTimeZone((string) $tz);
    }

    // Aggregates: arguments are evaluated within the group.

    private function agg(Call $c): Expr
    {
        $fn = $c->fn;
        if ($fn === 'n') {
            return len()->cast('Int64');
        }
        $naRm = (bool) ($this->literal($c, 'na_rm') ?? false);
        if ($fn === 'n_distinct') {
            $args = array_map(fn (PhibbleExpr $x) => $this->node($x, true), $c->args);
            $x = count($args) === 1 ? $args[0] : struct_(...$args);
            if ($naRm) {
                $x = $x->filter(all_horizontal(...array_map(fn (Expr $e) => $e->isNotNull(), $args)));
            }

            return $x->nUnique()->cast('Int64');
        }
        $x = $this->node($c->args[0], true);
        if (in_array($fn, ['first', 'last', 'nth'], true)) {
            return $this->nth($c, $x, $naRm);
        }
        $type = $this->type($c->args[0]);
        $value = match ($fn) {
            'sum' => $type === 'bool' ? $x->cast('Int64')->sum() : $x->sum(),
            'mean' => $x->cast('Float64')->mean(),
            'median' => $x->cast('Float64')->median(),
            'min' => $x->min(),
            'max' => $x->max(),
            'sd' => $x->cast('Float64')->std(),
            'var' => $x->cast('Float64')->var(),
            'quantile' => $x->cast('Float64')->quantile((float) $this->scalarArg($c->args[1]), 'linear'),
            'iqr' => $x->cast('Float64')->quantile(0.75, 'linear')->sub($x->cast('Float64')->quantile(0.25, 'linear')),
            'any' => $x->any(ignoreNulls: $naRm),
            'all' => $x->all(ignoreNulls: $naRm),
        };
        if (! $naRm && in_array($fn, ['quantile', 'iqr'], true)) {
            $this->naChecks[] = $x;
        }
        if ($naRm || in_array($fn, ['any', 'all'], true)) {
            return $value;
        }

        // dplyr's na.rm = FALSE: any NA makes the result NA.
        return when($x->nullCount()->gt(0))->then(lit(null))->otherwise($value);
    }

    private function nth(Call $c, Expr $x, bool $naRm): Expr
    {
        $order = $c->option('order_by');
        if ($order !== null) {
            [$by, $desc] = $this->orderKey($order, true);
            $x = $x->sortBy($by, descending: $desc, nullsLast: true);
        }
        if ($naRm) {
            $x = $x->dropNulls();
        }
        $n = match ($c->fn) {
            'first' => 1,
            'last' => -1,
            'nth' => (int) $this->scalarArg($c->args[1]),
        };
        $default = $this->optional($c, 'default', true);
        if ($n === 0) {
            return $default ?? lit(null);
        }
        $value = $x->get($n > 0 ? $n - 1 : $n, nullOnOob: true);

        return $default === null ? $value : when($x->len()->ge(abs($n)))->then($value)->otherwise($default);
    }

    // Window functions: one value per row, computed over the group in row order.

    private function window(Call $c): Expr
    {
        $fn = $c->fn;
        $arg = fn (int $i) => $this->node($c->args[$i], true);
        if ($fn === 'lag' || $fn === 'lead') {
            $n = (int) ($this->literal($c, 'n') ?? 1);
            $default = $this->optional($c, 'default', true);

            return $arg(0)->shift($fn === 'lag' ? $n : -$n, fillValue: $default);
        }
        if (in_array($fn, Functions::RANKING, true)) {
            return $this->rank($c);
        }
        if ($fn === 'consecutive_id') {
            $args = array_map(fn (PhibbleExpr $x) => $this->node($x, true), $c->args);
            $key = count($args) === 1 ? $args[0] : struct_(...$args);
            $changed = int_range(len())->eq(0)->or($key->neMissing($key->shift()));

            return $changed->cast('Int64')->cumSum();
        }
        $x = $arg(0);
        $type = $this->type($c->args[0]);
        $seenNull = $x->isNull()->cast('Int64')->cumSum()->gt(0);

        return match ($fn) {
            'cumsum' => when($seenNull)->then(lit(null))->otherwise(($type === 'bool' ? $x->cast('Int64') : $x)->cumSum()),
            'cumprod' => when($seenNull)->then(lit(null))->otherwise(($type === 'bool' ? $x->cast('Int64') : $x)->cumProd()),
            'cummin' => when($seenNull)->then(lit(null))->otherwise($x->cumMin()),
            'cummax' => when($seenNull)->then(lit(null))->otherwise($x->cumMax()),
            'cummean' => when($seenNull)->then(lit(null))->otherwise($x->cast('Float64')->cumSum()->truediv(int_range(len())->add(1))),
            'cumall' => when($x->fillNull(true)->not()->cast('Int64')->cumSum()->gt(0))->then(lit(false))
                ->when($seenNull)->then(lit(null))->otherwise(lit(true)),
            'cumany' => when($x->fillNull(false)->cast('Int64')->cumSum()->gt(0))->then(lit(true))
                ->when($seenNull)->then(lit(null))->otherwise(lit(false)),
        };
    }

    private function rank(Call $c): Expr
    {
        $fn = $c->fn;
        if ($c->args === []) {
            $rowNumber = int_range(len())->add(1);
            $x = null;
            $desc = false;
        } else {
            $first = $c->args[0];
            $desc = $first instanceof Desc;
            $x = $this->sortable($desc ? $first->expr : $first, true);
            $rowNumber = $x->rank('ordinal', descending: $desc)->cast('Int64');
        }
        if ($fn === 'row_number') {
            return $rowNumber;
        }
        $m = $x === null ? len()->cast('Int64') : $x->count()->cast('Int64');
        if ($fn === 'ntile') {
            $bins = lit((int) floor((float) $this->literal($c, 'n')));
            $larger = $m->mod($bins);
            $largerSize = $m->add($bins)->sub(1)->floordiv($bins);
            $smallerSize = $m->floordiv($bins);
            $threshold = $largerSize->mul($larger);

            return when($rowNumber->le($threshold))
                ->then($rowNumber->add($largerSize)->sub(1)->floordiv($largerSize))
                ->otherwise($rowNumber->sub($threshold)->add($smallerSize)->sub(1)->floordiv(max_horizontal(lit(1), $smallerSize))->add($larger));
        }
        if ($x === null) {
            throw new InvalidArgumentException("{$fn}() needs a column to rank.");
        }

        return match ($fn) {
            'min_rank' => $x->rank('min', descending: $desc)->cast('Int64'),
            'dense_rank' => $x->rank('dense', descending: $desc)->cast('Int64'),
            'percent_rank' => $x->rank('min', descending: $desc)->cast('Float64')->sub(1)->truediv($m->sub(1)),
            'cume_dist' => $x->rank('max', descending: $desc)->cast('Float64')->truediv($m),
        };
    }

    // Helpers.

    private function caseWhen(Call $c, bool $inGroup): Expr
    {
        $chain = null;
        for ($k = 0; $k < count($c->args); $k += 2) {
            $cond = $this->node($c->args[$k], $inGroup);
            $value = $this->node($c->args[$k + 1], $inGroup);
            $chain = $chain === null ? when($cond)->then($value) : $chain->when($cond)->then($value);
        }

        return $chain->otherwise($this->optional($c, 'default', $inGroup) ?? lit(null));
    }

    /** R's round(): ties to even at the stored double; negative digits round to tens. */
    private function round(Call $c, Expr $x): Expr
    {
        $digits = (int) ($this->literal($c, 'digits') ?? 0);
        if ($this->type($c->args[0]) === 'int' && $digits >= 0) {
            return $x;
        }
        // Of the two candidates at this many digits, take the one nearer the
        // stored double; an exact tie goes to the even digit.
        $x = $x->cast('Float64');
        $scaled = $digits >= 0 ? $x->mul(10 ** $digits) : $x->truediv(10 ** -$digits);
        $unscale = fn (Expr $e) => $digits >= 0 ? $e->truediv(10 ** $digits) : $e->mul(10 ** -$digits);
        $lo = $unscale($scaled->floor());
        $hi = $unscale($scaled->ceil());
        $dLo = $x->sub($lo);
        $dHi = $hi->sub($x);

        return when($x->isNan()->or($x->isInfinite()))->then($x)
            ->when($dLo->lt($dHi))->then($lo)
            ->when($dLo->gt($dHi))->then($hi)
            ->when($scaled->floor()->mod(2)->eq(0))->then($lo)
            ->otherwise($hi);
    }

    /** str_sub(): 1-based, inclusive end, negative positions count from the end. */
    private function strSub(Expr $s, Expr $start, Expr $end): Expr
    {
        $n = $s->str()->lenChars()->cast('Int64');
        $from = when($start->lt(0))->then($n->add($start)->add(1))->otherwise($start);
        $from = max_horizontal(lit(1), $from);
        $to = when($end->lt(0))->then($n->add($end)->add(1))->otherwise(min_horizontal($end, $n));
        $length = max_horizontal(lit(0), $to->sub($from)->add(1));

        return $s->str()->slice($from->sub(1), $length);
    }

    private function negate(Call $c, Expr $e): Expr
    {
        return ($this->literal($c, 'negate') ?? false) ? $e->not() : $e;
    }

    /** R's as.character(): 15 significant digits for doubles, TRUE/FALSE for logicals. */
    private function asString(PhibbleExpr $node, Expr $x): Expr
    {
        return match ($this->type($node)) {
            'float' => $x->roundSigFigs(15)->cast('String')->str()->replace('\.0$', ''),
            'bool' => when($x)->then(lit('TRUE'))->when($x->not())->then(lit('FALSE'))->otherwise(lit(null)),
            'date' => $x->dt()->strftime('%Y-%m-%d'),
            'datetime' => $x->dt()->strftime('%Y-%m-%d %H:%M:%S'),
            default => $x->cast('String', strict: false),
        };
    }

    /** @param list<PhibbleExpr> $args */
    private function paste(array $args, string $sep, bool $inGroup): Expr
    {
        $parts = array_map(fn (PhibbleExpr $a) => $this->asString($a, $this->node($a, $inGroup))->fillNull('NA'), $args);

        return concat_str($parts, separator: $sep);
    }

    /** @return array{0: Expr, 1: bool} An order_by key and whether it is descending. */
    private function orderKey(PhibbleExpr $order, bool $inGroup = true): array
    {
        $desc = $order instanceof Desc;

        return [$this->sortable($desc ? $order->expr : $order, $inGroup), $desc];
    }

    /** The values of a list literal, for in(). */
    private function setValues(PhibbleExpr $set): array|Expr
    {
        if ($set instanceof Lit) {
            return is_array($set->value) ? $set->value : [$set->value];
        }

        return $this->node($set, false)->implode();
    }

    private function optional(Call $c, string $name, bool $inGroup): ?Expr
    {
        $node = $c->option($name);

        return $node === null ? null : $this->node($node, $inGroup);
    }

    /** A named option that must be a literal, such as na_rm or digits. */
    private function literal(Call $c, string $name): mixed
    {
        $node = $c->option($name);
        if ($node === null) {
            return null;
        }

        return $this->scalarArg($node);
    }

    private function scalarArg(PhibbleExpr $node): mixed
    {
        if ($node instanceof Lit) {
            return $node->value;
        }
        if ($node instanceof Call && $node->fn === 'neg' && $node->args[0] instanceof Lit) {
            return -$node->args[0]->value;
        }

        throw new InvalidArgumentException('This argument must be a literal value for the Polars backend.');
    }

    /** The R-level type of an expression, as far as it can be told without data. */
    public function type(PhibbleExpr $e): string
    {
        if ($e instanceof Col) {
            $dtype = $this->schema[$e->name] ?? 'String';

            return match (true) {
                str_starts_with($dtype, 'Float') => 'float',
                str_starts_with($dtype, 'Int'), str_starts_with($dtype, 'UInt') => 'int',
                $dtype === 'Boolean', $dtype === 'Null' => 'bool',
                $dtype === 'Date' => 'date',
                str_starts_with($dtype, 'Datetime') => 'datetime',
                default => 'string',
            };
        }
        if ($e instanceof Lit) {
            return match (true) {
                is_float($e->value) => 'float',
                is_int($e->value) => 'int',
                is_bool($e->value) || $e->value === null => 'bool',
                $e->value instanceof DateTimeInterface => $e->dtype === 'date' ? 'date' : 'datetime',
                default => 'string',
            };
        }
        if ($e instanceof Desc) {
            return $this->type($e->expr);
        }
        $args = $e->args;
        $first = fn () => $args === [] ? 'int' : $this->type($args[0]);
        $numeric = function () use ($args): string {
            foreach ($args as $a) {
                if ($this->type($a) === 'float') {
                    return 'float';
                }
            }

            return 'int';
        };

        if (in_array($e->fn, ['+', '-'], true)) {
            $l = $this->type($args[0]);
            $r = $this->type($args[1]);
            if (in_array($l, ['date', 'datetime'], true) && $r === 'period') {
                return $l;
            }
            if ($e->fn === '+' && in_array($r, ['date', 'datetime'], true) && $l === 'period') {
                return $r;
            }
            if ($e->fn === '-' && in_array($l, ['date', 'datetime'], true) && in_array($r, ['date', 'datetime'], true)) {
                return 'float';
            }
        }

        return match ($e->fn) {
            'seconds', 'minutes', 'hours', 'days', 'weeks', 'months', 'years' => 'period',
            '*' => in_array('period', array_map($this->type(...), $args), true) ? 'period' : $numeric(),
            '+', '-', '%%' => $numeric(),
            'ymd', 'dmy', 'mdy', 'today' => 'date',
            'ymd_hms', 'dmy_hms', 'mdy_hms', 'parse_date_time', 'with_tz', 'force_tz', 'now' => 'datetime',
            'floor_date', 'ceiling_date', 'round_date' => $first(),
            'year', 'month', 'day', 'yday', 'quarter', 'hour', 'minute', 'wday', 'str_count' => 'int',
            'second', 'difftime' => 'float',
            'str_extract_all', 'str_split' => 'list',
            '/', '^', 'sqrt', 'exp', 'log', 'log10', 'floor', 'ceiling', 'mean', 'median', 'sd', 'var',
            'quantile', 'iqr', 'percent_rank', 'cume_dist', 'cummean', 'as_float' => 'float',
            'neg', 'abs', 'round', 'min', 'max', 'first', 'last', 'nth', 'lag', 'lead', 'cummin', 'cummax', 'coalesce', 'na_if' => $first(),
            '==', '!=', '<', '<=', '>', '>=', '&', '|', '!', 'is_na', 'in', 'between', 'near', 'str_detect', 'str_starts',
            'str_ends', 'any', 'all', 'cumall', 'cumany', 'as_bool' => 'bool',
            'if_else' => $this->type($args[1]),
            'case_when' => $this->type($args[1]),
            'n', 'n_distinct', 'row_number', 'min_rank', 'dense_rank', 'ntile', 'consecutive_id', 'str_length', 'as_int' => 'int',
            'sum', 'cumsum', 'cumprod' => $first() === 'float' ? 'float' : 'int',
            default => 'string',
        };
    }
}
