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
 * Usage: <tv:metro json="{data.metro_json}" />
 */
final class MetroViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    private const ROW_H = 110;
    private const MIN_COL_W = 120;
    private const COL_GAP = 28;
    private const PAD = 40;
    private const R = 5;
    private const LANE = 14;
    private const LINE_H = 18;
    private const CHAR_W = 8.4;
    private const TARGET_W = 860; // every graphic gets this width; column gaps are stretched to fit

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
        $nodes = $data['nodes'];
        $edges = $data['edges'];
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $lines = static fn (string $id, array $n): array => explode("\n", (string)($n['label'] ?? $id));
        $width = fn (string $id, array $n): float => max(array_map('mb_strlen', $lines($id, $n))) * self::CHAR_W;

        // Column x: half the widest label of each neighbouring column + gap
        $half = [];
        foreach ($nodes as $id => $n) {
            $c = $n['col'] ?? 0;
            $half[$c] = max($half[$c] ?? 0, $width((string)$id, $n) / 2);
        }
        $colX = [0 => self::PAD];
        for ($c = 1; $c <= max(array_keys($half)); $c++) {
            $gap = ($half[$c - 1] ?? 0) + ($half[$c] ?? 0) + self::COL_GAP;
            $colX[$c] = $colX[$c - 1] + max(self::MIN_COL_W, $gap);
        }
        $count = static fn (string $key, string $id): int => count(array_filter($edges, static fn ($e) => ($e[$key] ?? null) === $id));

        // Stretch column gaps so every graphic spans TARGET_W (text size stays 1:1).
        // Label overhang left of the first / right of the last column:
        $last = max(array_keys($colX));
        $overhang = [0 => self::R, $last => self::R];
        foreach ($nodes as $id => $n) {
            $c = $n['col'] ?? 0;
            if ($c !== 0 && $c !== $last) {
                continue;
            }
            $outer = $c === 0 ? $count('to', (string)$id) === 0 : $count('from', (string)$id) === 0;
            $w = $width((string)$id, $n);
            $overhang[$c] = max($overhang[$c], $outer ? self::R + 8 + $w : $w / 2);
        }
        $span = $colX[$last] - $colX[0];
        $f = $span > 0 ? max(1, (self::TARGET_W - 16 - $overhang[0] - $overhang[$last] - $span) / $span + 1) : 1;
        foreach ($colX as $c => $x) {
            $colX[$c] = $colX[0] + ($x - $colX[0]) * $f;
        }
        $pos = static fn (array $n): array => [$colX[$n['col'] ?? 0], self::PAD + ($n['row'] ?? 0) * self::ROW_H];

        $minX = $minY = PHP_INT_MAX;
        $maxX = $maxY = 0;
        $out = '';
        $lane = [];
        $svgEdges = '';
        $aria = [];
        foreach ($edges as $i => $e) {
            if (!isset($nodes[$e['from']], $nodes[$e['to']])) {
                continue;
            }
            $a = $nodes[$e['from']];
            $b = $nodes[$e['to']];
            $aria[] = ($a['label'] ?? $e['from']) . ' führt zu ' . ($b['label'] ?? $e['to']);
            [$ax, $ay] = $pos($a);
            [$bx, $by] = $pos($b);
            if ($ay === $by) {
                $d = "M $ax $ay L $bx $by";
            } else {
                $key = ($a['col'] ?? 0) . '-' . ($b['col'] ?? 0);
                $k = $lane[$key] = ($lane[$key] ?? -1) + 1;
                $mid = $ax + ($bx - $ax) / 2 + (int)ceil($k / 2) * self::LANE * ($k % 2 ? -1 : 1);
                $d = "M $ax $ay L $mid $ay L $mid $by L $bx $by";
            }
            $color = $h((string)($e['color'] ?? '#1c8a7d'));
            $delay = round($i * 0.12, 2);
            $svgEdges .= "<path d=\"$d\" pathLength=\"1\" fill=\"none\" stroke=\"$color\" stroke-width=\"5\" stroke-linecap=\"round\" stroke-linejoin=\"round\" class=\"tv-metro__line\" style=\"animation-delay:{$delay}s\"/>";
        }

        $i = 0;
        foreach ($nodes as $id => $n) {
            [$x, $y] = $pos($n);
            $l = $lines((string)$id, $n);
            $side = $count('to', (string)$id) === 0 ? -1 : ($count('from', (string)$id) === 0 ? 1 : 0);
            $lx = $x + $side * (self::R + 8);
            $ly = $side ? $y + 4 - (count($l) - 1) * self::LINE_H / 2 : $y - self::R - 12 - (count($l) - 1) * self::LINE_H;
            $w = $width((string)$id, $n);
            $minX = min($minX, $x - self::R, $side < 0 ? $lx - $w : ($side ? $x : $lx - $w / 2));
            $maxX = max($maxX, $x + self::R, $side > 0 ? $lx + $w : ($side ? $x : $lx + $w / 2));
            $minY = min($minY, $y - self::R, $ly - 14);
            $maxY = max($maxY, $y + self::R, $ly + (count($l) - 1) * self::LINE_H + 4);
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

        return "<svg class=\"tv-metro__svg\" viewBox=\"$vb\" style=\"width:{$w}px\" role=\"img\" aria-label=\""
            . $h('Netzplan des Projektaufbaus: ' . implode('; ', $aria)) . "\">$svgEdges$out</svg>";
    }
}
