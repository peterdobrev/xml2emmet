<?php
namespace App;

final class Stats {
    /**
     * @return array{nodeCount: int, depth: int, tagHistogram: array<string,int>, attrCount: int, textLength: int}
     */
    public static function compute(Node $node): array {
        $histogram = [];
        $nodeCount = 0;
        $attrCount = 0;
        $textLength = 0;
        $depth = self::walk($node, $histogram, $nodeCount, $attrCount, $textLength);
        return [
            'nodeCount' => $nodeCount,
            'depth' => $depth,
            'tagHistogram' => $histogram,
            'attrCount' => $attrCount,
            'textLength' => $textLength,
        ];
    }

    /** @param array<string,int> $histogram */
    private static function walk(Node $n, array &$histogram, int &$nodeCount, int &$attrCount, int &$textLength): int {
        $nodeCount++;
        $attrCount += count($n->attrs);
        $textLength += strlen($n->text ?? '');
        if ($n->tag !== '#text') {
            $histogram[$n->tag] = ($histogram[$n->tag] ?? 0) + 1;
        }
        $maxChildDepth = 0;
        foreach ($n->children as $child) {
            $d = self::walk($child, $histogram, $nodeCount, $attrCount, $textLength);
            if ($d > $maxChildDepth) $maxChildDepth = $d;
        }
        return 1 + $maxChildDepth;
    }
}
