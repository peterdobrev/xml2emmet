<?php
namespace App;

/**
 * Deep equality for Node trees, ignoring appliedRules.
 *
 * Attribute order is NOT significant: {a:1, b:2} equals {b:2, a:1}. Children
 * are compared in order. Text and tag must match exactly.
 */
final class NodeEquality {
    public static function equals(Node $a, Node $b): bool {
        if ($a->tag !== $b->tag) return false;
        if ($a->text !== $b->text) return false;
        $attrsA = $a->attrs;
        $attrsB = $b->attrs;
        ksort($attrsA);
        ksort($attrsB);
        if ($attrsA !== $attrsB) return false;
        if (count($a->children) !== count($b->children)) return false;
        foreach ($a->children as $i => $childA) {
            if (!self::equals($childA, $b->children[$i])) return false;
        }
        return true;
    }
}
