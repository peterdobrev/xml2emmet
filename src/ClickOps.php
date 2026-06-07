<?php
declare(strict_types=1);
namespace App;

final class ClickOps {
    private const VALID_TYPES = ['swap', 'rename', 'unwrap', 'wrap', 'delete', 'move'];

    public static function apply(Node $root, array $op): Node {
        $type = $op['type'] ?? '';
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new ClickOpError('unknown_op', "Unknown click-op type: $type");
        }

        // Path values are validated lazily by the walker (per-level bounds check
        // when navigating). Type/sign of each element is checked there too.
        $path = $op['path'] ?? [];

        return match ($type) {
            'swap'   => self::swap($root, $path, $op),
            'rename' => self::rename($root, $path, $op),
            'unwrap' => self::unwrap($root, $path),
            'wrap'   => self::wrap($root, $path, $op),
            'delete' => self::delete($root, $path),
            'move'   => self::move($root, $path, $op),
        };
    }

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    private static function swap(Node $root, array $path, array $op): Node {
        if ($path === []) {
            throw new ClickOpError('bad_path', 'swap requires a non-empty path (root has no siblings)');
        }
        $parentPath = array_slice($path, 0, -1);
        $idx        = $path[count($path) - 1];
        $parent     = self::getAt($root, $parentPath);
        if (!is_int($idx) || $idx < 0 || $idx + 1 >= count($parent->children)) {
            throw new ClickOpError('bad_path', "swap: node at depth " . count($path) . " index $idx has no next sibling");
        }
        $newChildren = $parent->children;
        [$newChildren[$idx], $newChildren[$idx + 1]] = [$newChildren[$idx + 1], $newChildren[$idx]];
        return self::replaceAt($root, $parentPath, $parent->withChildren($newChildren));
    }

    private static function rename(Node $root, array $path, array $op): Node {
        if (!isset($op['with']) || !is_string($op['with']) || $op['with'] === '') {
            throw new ClickOpError('missing_with', "rename requires a non-empty string 'with' tag");
        }
        $node = self::getAt($root, $path);
        $newNode = new Node($op['with'], $node->attrs, $node->children, $node->text, $node->appliedRules);
        return self::replaceAt($root, $path, $newNode);
    }

    private static function unwrap(Node $root, array $path): Node {
        if ($path === []) {
            throw new ClickOpError('unwrap_root', "unwrap requires a non-empty path");
        }
        return self::transformParent($root, $path, function (Node $parent, int $leafIdx): Node {
            self::checkChildIndex($parent, $leafIdx);
            $target  = $parent->children[$leafIdx];
            $newKids = array_merge(
                array_slice($parent->children, 0, $leafIdx),
                $target->children,
                array_slice($parent->children, $leafIdx + 1),
            );
            return $parent->withChildren($newKids);
        });
    }

    private static function wrap(Node $root, array $path, array $op): Node {
        if (!isset($op['with']) || !is_string($op['with']) || $op['with'] === '') {
            throw new ClickOpError('missing_with', "wrap requires a non-empty string 'with' tag");
        }
        $node = self::getAt($root, $path);
        $wrapper = new Node($op['with'], [], [$node]);
        return self::replaceAt($root, $path, $wrapper);
    }

    private static function delete(Node $root, array $path): Node {
        if ($path === []) {
            throw new ClickOpError('root_delete', "delete requires a non-empty path (cannot delete root)");
        }
        return self::replaceAt($root, $path, null);
    }

    private static function move(Node $root, array $path, array $op): Node {
        if ($path === []) {
            throw new ClickOpError('bad_path', "move requires a non-empty source path");
        }
        if (!isset($op['to']) || !is_array($op['to'])) {
            throw new ClickOpError('missing_to', "move requires a 'to' array path");
        }
        $to = $op['to'];

        $subtree     = self::getAt($root, $path);
        $afterDelete = self::replaceAt($root, $path, null);
        $adjustedTo  = self::adjustDestinationAfterDelete($path, $to);
        return self::insertAt($afterDelete, $adjustedTo, $subtree);
    }

    /**
     * Adjust $to so it points to the same logical position after $from is deleted.
     *
     * If $from and $to share the same parent (i.e. $from's prefix equals $to's
     * matching prefix), and $from's last index is < the corresponding index in
     * $to, then deleting the source shifts that index in $to down by 1.
     *
     * Paths to entirely separate subtrees are unaffected.
     *
     * @param int[] $from
     * @param int[] $to
     * @return int[]
     */
    private static function adjustDestinationAfterDelete(array $from, array $to): array {
        $parentDepth = count($from) - 1;
        if (count($to) <= $parentDepth) {
            return $to; // destination above or alongside source's parent — no shift
        }
        // Same-parent prefix check
        for ($i = 0; $i < $parentDepth; $i++) {
            if ($to[$i] !== $from[$i]) return $to; // diverged — no shift
        }
        // The source and destination share a parent. Shift only when source index
        // is strictly less than dest index at that level (equal means dest slot is
        // now occupied by the formerly-next sibling — no adjustment needed).
        $srcIdx = $from[$parentDepth];
        $dstIdx = $to[$parentDepth];
        if ($srcIdx < $dstIdx) {
            $to[$parentDepth] = $dstIdx - 1;
        }
        return $to;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Navigate down by index array. Empty path returns root.
     * Out-of-range or negative index throws ClickOpError.
     */
    private static function getAt(Node $root, array $path): Node {
        $node = $root;
        foreach ($path as $depth => $idx) {
            self::checkChildIndex($node, $idx, $depth);
            $node = $node->children[$idx];
        }
        return $node;
    }

    /**
     * Walk to the node whose children list is targeted by $path, then call $atParent
     * on it with the leaf index. $atParent returns the rebuilt parent. Each level
     * above the leaf parent is rebuilt with its mutated child slot via withChildren.
     *
     * Bounds at each navigated level are checked by checkChildIndex; the leaf-level
     * check belongs to $atParent (since 'insert at position N' allows index === count
     * while 'replace/unwrap at N' does not).
     *
     * Path must be non-empty.
     */
    private static function transformParent(Node $root, array $path, callable $atParent, int $depth = 0): Node {
        if ($depth === count($path) - 1) {
            // We're at the parent of the leaf — let the callback rebuild it.
            return $atParent($root, $path[$depth]);
        }
        $idx = $path[$depth];
        self::checkChildIndex($root, $idx, $depth);
        $newChildren = $root->children;
        $newChildren[$idx] = self::transformParent($root->children[$idx], $path, $atParent, $depth + 1);
        return $root->withChildren($newChildren);
    }

    /**
     * Validate that $idx is an in-range child index of $node. Throws ClickOpError
     * with a contextual message otherwise. $depth (when provided) is included in
     * the message; pass null for leaf-level checks where depth is implicit.
     */
    private static function checkChildIndex(Node $node, mixed $idx, ?int $depth = null): void {
        if (!is_int($idx) || $idx < 0 || $idx >= count($node->children)) {
            $depthStr = $depth !== null ? " at depth $depth" : '';
            throw new ClickOpError(
                'bad_path',
                "Path index $idx out of range$depthStr (node <{$node->tag}> has " . count($node->children) . " children)"
            );
        }
    }

    /**
     * Return a new tree with the node at $path replaced by $new, or removed if $new === null.
     * Empty path: returns $new (or throws if null, since root cannot be removed this way).
     */
    private static function replaceAt(Node $root, array $path, ?Node $new): Node {
        if ($path === []) {
            if ($new === null) {
                throw new ClickOpError('root_delete', "Cannot remove root via replaceAt with empty path");
            }
            return $new;
        }
        return self::transformParent($root, $path, function (Node $parent, int $leafIdx) use ($new): Node {
            self::checkChildIndex($parent, $leafIdx);
            $newChildren = $parent->children;
            if ($new === null) {
                array_splice($newChildren, $leafIdx, 1);
            } else {
                $newChildren[$leafIdx] = $new;
            }
            return $parent->withChildren($newChildren);
        });
    }

    /**
     * Insert $new as a child at position $path in the tree.
     * $path = [parentIdx, ..., insertIdx]: navigate to parent via all but last
     * element, then insert before the child at insertIdx.
     * If insertIdx equals the parent's current child count, appends.
     */
    private static function insertAt(Node $root, array $path, Node $new): Node {
        if ($path === []) {
            throw new ClickOpError('bad_path', "insertAt requires a non-empty path");
        }
        return self::transformParent($root, $path, function (Node $parent, int $insertIdx) use ($new): Node {
            $count = count($parent->children);
            if (!is_int($insertIdx) || $insertIdx < 0 || $insertIdx > $count) {
                throw new ClickOpError(
                    'bad_path',
                    "Insert index $insertIdx out of range (node <{$parent->tag}> has $count children)"
                );
            }
            $newChildren = $parent->children;
            array_splice($newChildren, $insertIdx, 0, [$new]);
            return $parent->withChildren($newChildren);
        });
    }
}
