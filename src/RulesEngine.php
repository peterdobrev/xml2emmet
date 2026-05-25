<?php
namespace App;
final class RulesEngine {
    /**
     * Apply an array of rules to a tree, returning a new tree.
     * For Task 5.1: single rule, no placeholders — plain deep-equality match.
     *
     * @param Rule[] $rules
     */
    public static function apply(Node $root, array $rules): Node {
        return self::walk($root, $rules);
    }

    /** @param Rule[] $rules */
    private static function walk(Node $node, array $rules): Node {
        foreach ($rules as $rule) {
            if (NodeEquality::equals($node, $rule->pattern)) {
                // Replace this node with the rule's replacement subtree,
                // tagging every node in the replacement with the rule id.
                return self::tagSubtree($rule->replacement, $rule->id);
            }
        }

        // No rule matched at this node: rebuild with recursively-walked children.
        $newChildren = [];
        foreach ($node->children as $child) {
            $newChildren[] = self::walk($child, $rules);
        }

        if ($newChildren === $node->children) {
            return $node; // nothing changed — reuse the same instance
        }

        return new Node($node->tag, $node->attrs, $newChildren, $node->text, $node->appliedRules);
    }

    /**
     * Return a copy of the subtree with $ruleId added to appliedRules
     * on every node (root and all descendants).
     */
    private static function tagSubtree(Node $node, string $ruleId): Node {
        $taggedChildren = [];
        foreach ($node->children as $child) {
            $taggedChildren[] = self::tagSubtree($child, $ruleId);
        }
        return new Node(
            $node->tag,
            $node->attrs,
            $taggedChildren,
            $node->text,
            in_array($ruleId, $node->appliedRules, true)
                ? $node->appliedRules
                : [...$node->appliedRules, $ruleId],
        );
    }
}
