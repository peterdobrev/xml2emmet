<?php
declare(strict_types=1);
namespace App;

final class Stats {
    /**
     * @return array{nodeCount:int, depth:int, tagHistogram:array<string,int>, attrCount:int, textLength:int, classCounts:array<string,int>, depthHistogram:array<int,int>}
     */
    public static function compute(Node $node): array {
        $histogram      = [];
        $classCounts    = [];
        $depthHistogram = [];
        $nodeCount      = 0;
        $attrCount      = 0;
        $textLength     = 0;
        $depth = self::walk($node, 1, $histogram, $classCounts, $depthHistogram, $nodeCount, $attrCount, $textLength);
        return [
            'nodeCount'      => $nodeCount,
            'depth'          => $depth,
            'tagHistogram'   => $histogram,
            'attrCount'      => $attrCount,
            'textLength'     => $textLength,
            'classCounts'    => $classCounts,
            'depthHistogram' => $depthHistogram,
        ];
    }

    /**
     * @param array<string,int> $histogram
     * @param array<string,int> $classCounts
     * @param array<int,int>    $depthHistogram
     */
    private static function walk(
        Node $n,
        int $depth,
        array &$histogram,
        array &$classCounts,
        array &$depthHistogram,
        int &$nodeCount,
        int &$attrCount,
        int &$textLength,
    ): int {
        $nodeCount++;
        $attrCount += count($n->attrs);
        $textLength += strlen($n->text ?? '');
        if ($n->tag !== '#text') {
            $histogram[$n->tag] = ($histogram[$n->tag] ?? 0) + 1;
            $depthHistogram[$depth] = ($depthHistogram[$depth] ?? 0) + 1;
        }
        if (isset($n->attrs['class']) && $n->attrs['class'] !== '') {
            foreach (preg_split('/\s+/', trim($n->attrs['class'])) ?: [] as $cls) {
                if ($cls === '') continue;
                $classCounts[$cls] = ($classCounts[$cls] ?? 0) + 1;
            }
        }
        $maxChildDepth = 0;
        foreach ($n->children as $child) {
            $d = self::walk($child, $depth + 1, $histogram, $classCounts, $depthHistogram, $nodeCount, $attrCount, $textLength);
            if ($d > $maxChildDepth) $maxChildDepth = $d;
        }
        return 1 + $maxChildDepth;
    }
}
