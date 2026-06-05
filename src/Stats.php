<?php
declare(strict_types=1);
namespace App;

final class Stats {
    private function __construct() {}

    /**
     * @return array{nodeCount:int, depth:int, tagHistogram:array<string,int>, attrCount:int, textLength:int, classCounts:array<string,int>, depthHistogram:array<int,int>}
     */
    public static function compute(Node $node): array {
        $r = self::walk($node, 1);
        return [
            'nodeCount'      => $r['nodeCount'],
            'depth'          => $r['depth'],
            'tagHistogram'   => $r['tagHistogram'],
            'attrCount'      => $r['attrCount'],
            'textLength'     => $r['textLength'],
            'classCounts'    => $r['classCounts'],
            'depthHistogram' => $r['depthHistogram'],
        ];
    }

    /**
     * Recursive fold returning the partial stats for the subtree rooted at $n.
     * Children are walked, then merged in: scalar counters add, the two
     * string-keyed histograms (tag, class) are merged via addCounts, and the
     * integer-keyed depthHistogram is merged with an explicit `+=` loop —
     * array_merge would renumber the integer keys.
     *
     * @return array{nodeCount:int, depth:int, tagHistogram:array<string,int>, attrCount:int, textLength:int, classCounts:array<string,int>, depthHistogram:array<int,int>}
     */
    private static function walk(Node $n, int $depth): array {
        $r = [
            'nodeCount'      => 1,
            'depth'          => 1,
            'tagHistogram'   => [],
            'attrCount'      => count($n->attrs),
            'textLength'     => strlen($n->text ?? ''),
            'classCounts'    => [],
            'depthHistogram' => [],
        ];
        if ($n->tag !== '#text') {
            $r['tagHistogram'][$n->tag]   = 1;
            $r['depthHistogram'][$depth]  = 1;
        }
        if (isset($n->attrs['class']) && $n->attrs['class'] !== '') {
            foreach (preg_split('/\s+/', trim($n->attrs['class'])) as $cls) {
                if ($cls === '') continue;
                $r['classCounts'][$cls] = ($r['classCounts'][$cls] ?? 0) + 1;
            }
        }

        $maxChildDepth = 0;
        foreach ($n->children as $child) {
            $c = self::walk($child, $depth + 1);
            $r['nodeCount']    += $c['nodeCount'];
            $r['attrCount']    += $c['attrCount'];
            $r['textLength']   += $c['textLength'];
            $r['tagHistogram']  = self::addCounts($r['tagHistogram'], $c['tagHistogram']);
            $r['classCounts']   = self::addCounts($r['classCounts'], $c['classCounts']);
            // depthHistogram is INT-keyed — array_merge would renumber the keys.
            foreach ($c['depthHistogram'] as $d => $count) {
                $r['depthHistogram'][$d] = ($r['depthHistogram'][$d] ?? 0) + $count;
            }
            if ($c['depth'] > $maxChildDepth) $maxChildDepth = $c['depth'];
        }
        $r['depth'] = 1 + $maxChildDepth;
        return $r;
    }

    /**
     * @param array<string,int> $a
     * @param array<string,int> $b
     * @return array<string,int>
     */
    private static function addCounts(array $a, array $b): array {
        foreach ($b as $k => $v) {
            $a[$k] = ($a[$k] ?? 0) + $v;
        }
        return $a;
    }
}
