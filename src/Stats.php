<?php
namespace App;

final class Stats {
    /**
     * @return array{nodeCount: int, depth: int, tagHistogram: array<string,int>}
     */
    public static function compute(Node $node): array {
        $histogram = [];
        $nodeCount = 0;
        $depth = self::walk($node, $histogram, $nodeCount);
        return [
            'nodeCount' => $nodeCount,
            'depth' => $depth,
            'tagHistogram' => $histogram,
        ];
    }

    /** @param array<string,int> $histogram */
    private static function walk(Node $n, array &$histogram, int &$nodeCount): int {
        $nodeCount++;
        if ($n->tag !== '#text') {
            $histogram[$n->tag] = ($histogram[$n->tag] ?? 0) + 1;
        }
        $maxChildDepth = 0;
        foreach ($n->children as $child) {
            $d = self::walk($child, $histogram, $nodeCount);
            if ($d > $maxChildDepth) $maxChildDepth = $d;
        }
        return 1 + $maxChildDepth;
    }
}
