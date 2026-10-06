<?php

declare(strict_types=1);

namespace Maidemde\TypovigilSitepackage\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Renders the metro / network-plan graphic as inline SVG (no JS).
 * JSON: { nodes: { id: { label, col, row } }, edges: [{ from, to, color }] }
 * col/row are grid coordinates (no auto-layout). Layout is pure arithmetic:
 * the font is monospace, so label width = characters * CHAR_W. The SVG is
 * emitted at 1 viewBox unit = 1px, so every graphic has the same text size.
 * Edges are right-angled paths drawn in via CSS (pathLength="1").
 *
 * Two SVGs are emitted: a horizontal one (--h) and a vertical one (--v,
 * stations stacked, labels on the right) for phones; CSS shows one of them.
 *
 * Usage: <tv:metro json="{data.metro_json}" />
 */
final class MetroViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    private const ROW_H = 80;
    private const MIN_COL_W = 120;
    private const COL_GAP = 28;
    private const PAD = 40;
    private const R = 5;
    private const LANE = 14;
    private const LINE_H = 18;
    private const CHAR_W = 8.4;
    private const TARGET_W = 860; // horizontal: every graphic gets this width, column gaps stretch to fit
    private const SLOT_H = 58; // vertical: minimum height per station
    private const LANE_W = 34; // vertical: distance between the vertical tracks

    /** @var array<string, array{label?: string, col?: int, row?: int}> */
    private array $nodes = [];
    /** @var list<array{from: string, to: string, color?: string}> */
    private array $edges = [];

    public function initializeArguments(): void
    {
        $this->registerArgument('json', 'string', 'Metro JSON', true);
    }

    public function render(): string
    {
        $data = json_decode((string)$this->arguments['json'], true);
        if (!isset($data['nodes'], $data['edges']) || !is_array($data['nodes']) || !is_array($data['edges'])) {
            return '';
        }
        $this->nodes = $data['nodes'];
        $this->edges = $data['edges'];

        return $this->draw($this->horizontalPositions(), false) . $this->draw($this->verticalPositions(), true);
    }

    /** @return list<string> */
    private function lines(string $id): array
    {
        return explode("\n", (string)($this->nodes[$id]['label'] ?? $id));
    }

    private function labelWidth(string $id): float
    {
        return max(array_map('mb_strlen', $this->lines($id))) * self::CHAR_W;
    }

    private function count(string $key, string $id): int
    {
        return count(array_filter($this->edges, static fn ($e) => ($e[$key] ?? null) === $id));
    }

    /** @return array<string, array{0: float, 1: float}> node id => [x, y] */
    private function horizontalPositions(): array
    {
        // Column x: half the widest label of each neighbouring column + gap
        $half = [];
        foreach ($this->nodes as $id => $n) {
            $c = $n['col'] ?? 0;
            $half[$c] = max($half[$c] ?? 0, $this->labelWidth((string)$id) / 2);
        }
        $colX = [0 => self::PAD];
        for ($c = 1; $c <= max(array_keys($half)); $c++) {
            $gap = ($half[$c - 1] ?? 0) + ($half[$c] ?? 0) + self::COL_GAP;
            $colX[$c] = $colX[$c - 1] + max(self::MIN_COL_W, $gap);
        }

        // Stretch column gaps so every graphic spans TARGET_W (text size stays 1:1).
        // Label overhang left of the first / right of the last column:
        $last = max(array_keys($colX));
        $overhang = [0 => self::R, $last => self::R];
        foreach ($this->nodes as $id => $n) {
            $c = $n['col'] ?? 0;
            if ($c !== 0 && $c !== $last) {
                continue;
            }
            $outer = $c === 0 ? $this->count('to', (string)$id) === 0 : $this->count('from', (string)$id) === 0;
            $w = $this->labelWidth((string)$id);
            $overhang[$c] = max($overhang[$c], $outer ? self::R + 8 + $w : $w / 2);
        }
        $span = $colX[$last] - $colX[0];
        $f = $span > 0 ? max(1, (self::TARGET_W - 16 - $overhang[0] - $overhang[$last] - $span) / $span + 1) : 1;

        $pos = [];
        foreach ($this->nodes as $id => $n) {
            $x = $colX[0] + ($colX[$n['col'] ?? 0] - $colX[0]) * $f;
            $pos[$id] = [$x, self::PAD + ($n['row'] ?? 0) * self::ROW_H];
        }

        return $pos;
    }

    /**
     * Phones: every station gets its own row (ordered by col, then row); the
     * original rows become narrow vertical tracks.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private function verticalPositions(): array
    {
        $tracks = array_values(array_unique(array_map(static fn ($n) => $n['row'] ?? 0, $this->nodes)));
        sort($tracks);
        $ids = array_keys($this->nodes);
        usort($ids, fn ($a, $b) => [$this->nodes[$a]['col'] ?? 0, $this->nodes[$a]['row'] ?? 0] <=> [$this->nodes[$b]['col'] ?? 0, $this->nodes[$b]['row'] ?? 0]);

        $pos = [];
        $y = self::PAD;
        foreach ($ids as $id) {
            $pos[$id] = [self::PAD + array_search($this->nodes[$id]['row'] ?? 0, $tracks, true) * self::LANE_W, $y];
            $y += max(self::SLOT_H, count($this->lines((string)$id)) * self::LINE_H + 14);
        }

        return $pos;
    }

    /** @param array<string, array{0: float, 1: float}> $pos */
    private function draw(array $pos, bool $vertical): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $labelX = $vertical ? max(array_map(static fn ($p) => $p[0], $pos)) + self::R + 16 : 0;

        $minX = $minY = PHP_INT_MAX;
        $maxX = $maxY = 0;
        $svgEdges = '';
        $lane = [];
        $aria = [];
        foreach ($this->edges as $i => $e) {
            if (!isset($this->nodes[$e['from']], $this->nodes[$e['to']])) {
                continue;
            }
            $a = $this->nodes[$e['from']];
            $b = $this->nodes[$e['to']];
            $aria[] = ($a['label'] ?? $e['from']) . ' führt zu ' . ($b['label'] ?? $e['to']);
            [$ax, $ay] = $pos[$e['from']];
            [$bx, $by] = $pos[$e['to']];

            // Right-angled path: half way along the main axis, step across, then the rest.
            // Parallel edges between the same columns are shifted apart by LANE.
            if (($vertical ? $ax === $bx : $ay === $by)) {
                $d = "M $ax $ay L $bx $by";
            } else {
                $key = ($a['col'] ?? 0) . '-' . ($b['col'] ?? 0);
                $k = $lane[$key] = ($lane[$key] ?? -1) + 1;
                $shift = (int)ceil($k / 2) * self::LANE * ($k % 2 ? -1 : 1);
                $d = $vertical
                    ? 'M ' . $ax . ' ' . $ay . ' L ' . $ax . ' ' . ($mid = $ay + ($by - $ay) / 2 + $shift) . ' L ' . $bx . ' ' . $mid . ' L ' . $bx . ' ' . $by
                    : 'M ' . $ax . ' ' . $ay . ' L ' . ($mid = $ax + ($bx - $ax) / 2 + $shift) . ' ' . $ay . ' L ' . $mid . ' ' . $by . ' L ' . $bx . ' ' . $by;
            }
            $color = $h((string)($e['color'] ?? '#1c8a7d'));
            $delay = round($i * 0.12, 2);
            $svgEdges .= "<path d=\"$d\" pathLength=\"1\" fill=\"none\" stroke=\"$color\" stroke-width=\"5\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"tv-metro__line\" style=\"animation-delay:{$delay}s\"/>";
        }

        $out = '';
        $i = 0;
        foreach ($this->nodes as $id => $n) {
            [$x, $y] = $pos[$id];
            $l = $this->lines((string)$id);
            $w = $this->labelWidth((string)$id);
            $extra = (count($l) - 1) * self::LINE_H;
            // Horizontal: start label left, end label right, others centred above.
            // Vertical: all labels in one column right of the tracks.
            $side = $vertical ? 1 : ($this->count('to', (string)$id) === 0 ? -1 : ($this->count('from', (string)$id) === 0 ? 1 : 0));
            $lx = $vertical ? $labelX : $x + $side * (self::R + 8);
            $ly = $side ? $y + 4 - $extra / 2 : $y - self::R - 12 - $extra;
            $minX = min($minX, $x - self::R, $side < 0 ? $lx - $w : ($side ? $x : $lx - $w / 2));
            $maxX = max($maxX, $x + self::R, $side > 0 ? $lx + $w : ($side ? $x : $lx + $w / 2));
            $minY = min($minY, $y - self::R, $ly - 14);
            $maxY = max($maxY, $y + self::R, $ly + $extra + 4);
            $anchor = $side < 0 ? 'end' : ($side ? 'start' : 'middle');
            $tspans = '';
            foreach ($l as $li => $text) {
                $tspans .= '<tspan x="' . $lx . '" y="' . ($ly + $li * self::LINE_H) . '">' . $h($text) . '</tspan>';
            }
            $out .= "<g class=\"tv-metro__station\" style=\"--i:$i\"><circle r=\"" . self::R . "\" cx=\"$x\" cy=\"$y\" class=\"tv-metro__dot\"/>"
                . "<text class=\"tv-metro__label\" text-anchor=\"$anchor\">$tspans</text></g>";
            $i++;
        }

        $m = 8;
        $w = $maxX - $minX + 2 * $m;
        $vb = ($minX - $m) . ' ' . ($minY - $m) . " $w " . ($maxY - $minY + 2 * $m);
        $mod = $vertical ? 'v' : 'h';

        return "<svg class=\"tv-metro__svg tv-metro__svg--$mod\" viewBox=\"$vb\" style=\"width:{$w}px\" role=\"img\" aria-label=\""
            . $h('Netzplan des Projektaufbaus: ' . implode('; ', $aria)) . "\">$svgEdges$out</svg>";
    }
}
