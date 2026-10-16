<?php

declare(strict_types=1);

namespace Phibble\Format;

/**
 * Pre-formatted cells, as pillar's new_pillar_shaft_simple(): truncated with
 * an ellipsis (or swapped for a short form) when squeezed, NA shown as $na.
 *
 * @internal
 */
final readonly class SimpleShaft implements Shaft
{
    /**
     * @param list<?string> $cells Null cells are NA.
     * @param list<string>|null $short Short forms used when squeezed.
     */
    public function __construct(
        private array $cells,
        private int $width,
        private ?int $minWidth = null,
        private string $align = 'left',
        private string $na = 'NA',
        private int $naIndent = 0,
        private ?array $short = null,
    ) {}

    public function width(): int
    {
        return $this->width;
    }

    public function minWidth(): int
    {
        return $this->minWidth ?? $this->width;
    }

    public function align(): string
    {
        return $this->align;
    }

    public function format(int $width): array
    {
        $out = [];
        foreach ($this->cells as $i => $cell) {
            if ($cell === null) {
                $out[] = Pillar::align(str_repeat(' ', $this->naIndent).$this->na, $width, $this->align);

                continue;
            }
            if ($width < $this->width) {
                $cell = $this->short === null
                    ? Pillar::truncate($cell, $width)
                    : (Pillar::extent($cell) > $width ? $this->short[$i] : $cell);
            }
            $out[] = Pillar::align($cell, $width, $this->align);
        }

        return $out;
    }
}
