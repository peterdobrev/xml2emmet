<?php
namespace App;

final class RulesEngine {
    /** Regex that identifies a node tag as a placeholder (e.g. E1, E2, E12). */
    private const PLACEHOLDER_RE = '/^E\d+$/';

    /**
     * Apply an array of rules to a tree in order, returning a new tree.
     * Each rule is applied as a complete tree pass before the next rule runs
     * (left-fold / sequential passes), so r1: a→b followed by r2: b→c
     * against tree <a> yields <c>.
     *
     * @param Rule[] $rules
     */
    public static function apply(Node $root, array $rules): Node {
        foreach ($rules as $rule) {
            $root = self::walk($root, [$rule]);
        }
        return $root;
    }

    /**
     * Pre-order, top-down traversal.  On match, recursion stops at the matched
     * node (the replacement subtree is not re-walked for the same rule due to
     * the appliedRules guard, and is also returned as-is rather than re-walked
     * for other rules).  Called with a single-element array from apply() so
     * that multi-rule sequencing is handled at the apply() level.
     *
     * @param Rule[] $rules
     */
    private static function walk(Node $node, array $rules): Node {
        foreach ($rules as $rule) {
            // Guard: skip this rule if the node was already produced by it.
            if (in_array($rule->id, $node->appliedRules, true)) {
                continue;
            }

            $bindings = self::matchNode($rule->pattern, $node);
            if ($bindings !== null) {
                // Substitute bound placeholders into the replacement template,
                // then tag every node in the result with the rule id.
                $materialized = self::substitute($rule->replacement, $bindings);
                return self::tagSubtree($materialized, $rule->id);
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
     * Attempt to match $candidate against $pattern.
     *
     * Returns null on no match, or the (possibly augmented) bindings array on
     * success.  A placeholder pattern node (tag matching /^E\d+$/) binds the
     * entire candidate subtree; a second binding for the same name must be
     * equal (via NodeEquality::equals) or the match fails.  Non-placeholder
     * nodes must agree on tag, text, attrs (order-independent), and child
     * count; children are matched recursively.
     *
     * Bindings flow through child recursion via the parameter — no shared
     * mutable state.
     *
     * @return array<string,Node>|null  null on no match, otherwise the placeholder bindings.
     */
    private static function matchNode(Node $pattern, Node $candidate, array $bindings = []): ?array {
        if (preg_match(self::PLACEHOLDER_RE, $pattern->tag)) {
            $name = $pattern->tag;
            if (isset($bindings[$name])) {
                // Same placeholder seen again — candidate must equal prior binding.
                return NodeEquality::equals($bindings[$name], $candidate) ? $bindings : null;
            }
            $bindings[$name] = $candidate;
            return $bindings;
        }

        // Structural match: tag, text, attrs, child count, children.
        if ($pattern->tag !== $candidate->tag) return null;

        // Text: null on pattern means "don't care"; non-null must match exactly.
        if ($pattern->text !== null && $pattern->text !== $candidate->text) return null;

        // Attrs: pattern attrs are a required subset — candidate may have extras.
        foreach ($pattern->attrs as $k => $v) {
            if (!array_key_exists($k, $candidate->attrs) || $candidate->attrs[$k] !== $v) return null;
        }

        if (count($pattern->children) !== count($candidate->children)) return null;

        foreach ($pattern->children as $i => $patternChild) {
            $bindings = self::matchNode($patternChild, $candidate->children[$i], $bindings);
            if ($bindings === null) return null;
        }

        return $bindings;
    }

    /**
     * Deep-clone the replacement template, swapping every placeholder node
     * (tag matching /^E\d+$/) with the bound subtree from $bindings.
     *
     * @param array<string,Node> $bindings
     * @throws RuleError  If the replacement references an unbound placeholder.
     */
    private static function substitute(Node $template, array $bindings): Node {
        if (preg_match(self::PLACEHOLDER_RE, $template->tag)) {
            $name = $template->tag;
            if (!isset($bindings[$name])) {
                throw new RuleError(
                    "Replacement references unbound placeholder '$name'."
                );
            }
            return $bindings[$name];
        }

        $newChildren = [];
        foreach ($template->children as $child) {
            $newChildren[] = self::substitute($child, $bindings);
        }

        return new Node(
            $template->tag,
            $template->attrs,
            $newChildren,
            $template->text,
            [],
        );
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
