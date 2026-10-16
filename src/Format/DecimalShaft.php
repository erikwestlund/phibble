<?php

declare(strict_types=1);

namespace Phibble\Format;

/**
 * Numbers formatted the way pillar does: three significant figures,
 * aligned on the decimal point, with trailing zeros left as spaces.
 * Columns too wide for decimal notation switch to scientific notation.
 * This is a port of pillar's split_decimal() and assemble_decimal().
 *
 * @internal
 */
final readonly class DecimalShaft implements Shaft
{
    private const int SIGFIG = 3;

    private const int MAX_DEC_WIDTH = 13;

    private const float EPS_2 = 4.440892098500626e-16;

    /**
     * @param array<string, mixed>|null $dec
     * @param array<string, mixed> $sci
     */
    private function __construct(
        private ?array $dec,
        private array $sci,
    ) {}

    /** @param list<int|float|null> $values */
    public static function fromValues(array $values, bool $isInteger = false): self
    {
        $dec = self::split($values, false, $isInteger);
        $sci = self::split($values, true, $isInteger);

        return new self($dec['width'] > self::MAX_DEC_WIDTH ? null : $dec, $sci);
    }

    public function width(): int
    {
        return ($this->dec ?? $this->sci)['width'];
    }

    public function minWidth(): int
    {
        return $this->dec === null ? $this->sci['width'] : min($this->dec['width'], $this->sci['width']);
    }

    public function align(): string
    {
        return 'right';
    }

    public function format(int $width): array
    {
        $fmt = ($this->dec === null || $width < $this->dec['width']) ? $this->sci : $this->dec;
        $rows = self::assemble($fmt);
        if ($rows === []) {
            return [];
        }
        $used = max(array_map(Pillar::extent(...), $rows));
        $pad = str_repeat(' ', max($width - $used, 0));

        return array_map(fn (string $row) => Pillar::align($pad.$row, $width, 'right'), $rows);
    }

    /**
     * @param list<int|float|null> $values
     * @return array<string, mixed>
     */
    private static function split(array $values, bool $scientific, bool $isInteger): array
    {
        $n = count($values);
        $num = [];
        $neg = [];
        $abs = [];
        $mnt = [];
        $exp = [];
        foreach ($values as $i => $x) {
            $num[$i] = $x !== null && is_finite((float) $x);
            $neg[$i] = $x !== null && ! is_nan((float) $x) && $x < 0;
            $abs[$i] = $x === null ? null : abs($x);
            $mnt[$i] = $abs[$i];
            $exp[$i] = null;
        }

        if ($scientific) {
            $offset = log1p(-5 * 10 ** (-self::SIGFIG - 1)) / M_LN10;
            foreach ($mnt as $i => $m) {
                if ($m !== null && $m != 0 && is_finite((float) $m)) {
                    $exp[$i] = (int) floor(log10($m) - $offset);
                    $mnt[$i] = 10 ** (log10($m) - $exp[$i]);
                } elseif ($m !== null) {
                    $mnt[$i] = (float) $m;
                }
            }
            $isInteger = false;
        }

        $rhsDigits = self::rhsDigits($mnt, $isInteger);
        $lhs = [];
        $rhs = [];
        $dec = [];
        $lhsStr = [];
        foreach ($mnt as $i => $m) {
            if ($m === null) {
                $lhs[$i] = null;
                $rhs[$i] = null;
                $dec[$i] = false;
                $lhsStr[$i] = 'NA';

                continue;
            }
            $m = (float) $m;
            $minSigfig = $m != 0 && is_finite($m) ? (int) floor(log10($m)) + 1 : null;
            $rounded = self::signif($m, max(self::SIGFIG, $minSigfig ?? self::SIGFIG));
            $lhs[$i] = is_finite($rounded) ? ($rounded < 0 ? ceil($rounded) : floor($rounded)) : $rounded;
            $rhs[$i] = is_finite($rounded) ? $rounded - $lhs[$i] : NAN;
            $dec[$i] = $num[$i];
            $scale = $exp[$i] === null ? 1.0 : 10 ** $exp[$i];
            if ($m == 0 || ($rhs[$i] == 0 && self::withinTolerance($lhs[$i] * ($scientific ? $scale : 1.0), (float) $abs[$i]))) {
                $dec[$i] = false;
            }
            $lhsStr[$i] = match (true) {
                is_nan($lhs[$i]) => 'NaN',
                is_infinite($lhs[$i]) => 'Inf',
                default => sprintf('%.0f', $lhs[$i]),
            };
        }

        $expShown = array_values(array_filter($exp, fn ($e) => $e !== null));
        $expDigits = 0;
        if ($expShown !== []) {
            $expDigits = (array_any($expShown, fn ($e) => $e < 0) ? 1 : 0)
                + max(0, ...array_map(fn ($e) => 2 + (int) floor(log10(abs($e) + 0.5)), $expShown));
        }
        $lhsWidth = 0;
        foreach ($lhsStr as $i => $s) {
            $lhsWidth = max($lhsWidth, ($neg[$i] ? 1 : 0) + strlen($s));
        }
        $width = $n === 0 ? 0 : $lhsWidth + (in_array(true, $dec, true) ? 1 : 0) + max(0, ...($rhsDigits ?: [0])) + $expDigits;

        return [
            'num' => $num, 'neg' => $neg, 'lhs' => $lhsStr, 'rhs' => $rhs, 'rhsDigits' => $rhsDigits,
            'dec' => $dec, 'exp' => $exp, 'width' => $width,
        ];
    }

    /**
     * pillar's compute_rhs_digits(): digits after the decimal point needed
     * for three significant figures, minus trailing zeros.
     *
     * @param list<int|float|null> $x
     * @return list<int>
     */
    private static function rhsDigits(array $x, bool $isInteger): array
    {
        $digits = array_fill(0, count($x), 0);
        $present = array_filter($x, fn ($v) => $v !== null && ! is_nan((float) $v));
        if ($isInteger || array_all($present, fn ($v) => is_infinite((float) $v) || (float) $v == floor((float) $v))) {
            return $digits;
        }

        $check = [];
        foreach ($x as $i => $v) {
            $e = $v !== null && $v != 0 && is_finite((float) $v) ? (int) floor(log10((float) $v)) : null;
            if ($e !== null && $e <= self::SIGFIG) {
                $digits[$i] = self::SIGFIG - 1 - $e;
            }
            $check[$i] = $digits[$i] > 0;
        }
        foreach ($x as $i => $v) {
            while ($check[$i]) {
                $val = (float) $v * 10 ** ($digits[$i] - 1);
                if (self::withinTolerance($val, self::roundHalfEven($val))) {
                    $digits[$i]--;
                    $check[$i] = $digits[$i] !== 0;
                } else {
                    $check[$i] = false;
                }
            }
        }

        return $digits;
    }

    /**
     * @param array<string, mixed> $s
     * @return list<string>
     */
    private static function assemble(array $s): array
    {
        $anyDec = in_array(true, $s['dec'], true);
        $maxRhs = max(0, ...($s['rhsDigits'] ?: [0]));
        $exp = self::superNum($s['exp']);
        $rows = [];
        foreach ($s['lhs'] as $i => $lhs) {
            $row = ($s['neg'][$i] ? '-' : '').$lhs;
            $row .= $anyDec ? ($s['dec'][$i] ? '.' : ' ') : '';
            $rhs = '';
            if ($s['dec'][$i]) {
                $digits = $s['rhsDigits'][$i];
                $number = sprintf('%.0f', abs(self::roundHalfEven($s['rhs'][$i] * 10 ** $digits)));
                $number = $number === '0' ? '' : $number;
                $rhs = str_repeat('0', max(0, $digits - strlen($number))).$number;
            }
            $rows[] = $row.Pillar::align($rhs, $maxRhs).$exp[$i];
        }

        return $rows;
    }

    /**
     * pillar's supernum() for plain text: "e" plus the exponent,
     * right-justified, with a sign column when any exponent is negative.
     *
     * @param list<?int> $exp
     * @return list<string>
     */
    private static function superNum(array $exp): array
    {
        $present = array_filter($exp, fn ($e) => $e !== null);
        if ($present === []) {
            return array_fill(0, count($exp), '');
        }
        $anyNeg = array_any($present, fn ($e) => $e < 0);
        $digitWidth = max(array_map(fn ($e) => strlen((string) abs($e)), $present));
        $out = [];
        foreach ($exp as $e) {
            $sign = $anyNeg ? ($e === null ? ' ' : ($e < 0 ? '-' : '+')) : '';
            $digits = $e === null ? '' : (string) abs($e);
            $out[] = ($e === null ? ' ' : 'e').$sign.str_pad($digits, $digitWidth, ' ', STR_PAD_LEFT);
        }

        return $out;
    }

    /** R's signif(), which multiplies by a power of ten and rounds half to even. */
    private static function signif(float $x, int $digits): float
    {
        if (! is_finite($x) || $x == 0.0) {
            return $x;
        }
        $digits = max(1, min($digits, 22));
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x);
        $e10 = $digits - 1 - (int) floor(log10($x));
        if ($e10 > 0) {
            $p10 = 1.0;
            if ($e10 > 308) {
                $p10 = 10.0 ** ($e10 - 308);
                $e10 = 308;
            }
            $pow10 = 10.0 ** $e10;

            return $sign * (self::roundHalfEven($x * $pow10 * $p10) / $pow10) / $p10;
        }
        $pow10 = 10.0 ** (-$e10);

        return $sign * (self::roundHalfEven($x / $pow10) * $pow10);
    }

    private static function roundHalfEven(float $x): float
    {
        if (! is_finite($x)) {
            return $x;
        }
        $floor = floor($x);
        $diff = $x - $floor;
        if ($diff > 0.5) {
            return $floor + 1;
        }
        if ($diff < 0.5) {
            return $floor;
        }

        return fmod($floor, 2.0) == 0.0 ? $floor : $floor + 1;
    }

    private static function withinTolerance(float $x, float $y): bool
    {
        if (is_nan($x) || is_nan($y)) {
            return false;
        }
        $l2x = self::roundHalfEven(log($x, 2));
        $l2y = self::roundHalfEven(log($y, 2));
        if ($l2x !== $l2y && ! (is_infinite($l2x) && $l2x === $l2y)) {
            return false;
        }
        if ($x == $y) {
            return true;
        }

        return abs(($x - $y) * 2 ** -$l2x) <= self::EPS_2;
    }
}
