<?php
namespace App;

final class ClickOps {
    private const VALID_TYPES = ['swap', 'rename', 'unwrap', 'wrap', 'delete', 'move'];

    public static function apply(Node $root, array $op): Node {
        $type = $op['type'] ?? '';
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new ClickOpError('unknown_op', "Unknown click-op type: $type");
        }

        $path = $op['path'] ?? [];
        self::validatePath($path);

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
        if ($idx + 1 >= count($parent->children)) {
            throw new ClickOpError('bad_path', "swap: node at depth " . count($path) . " index $idx has no next sibling");
        }
        $a = $parent->children[$idx];
        $b = $parent->children[$idx + 1];
        $newChildren = $parent->children;
        $newChildren[$idx]     = $b;
        $newChildren[$idx + 1] = $a;
        $newParent = new Node($parent->tag, $parent->attrs, $newChildren, $parent->text, $parent->appliedRules);
        return self::replaceAt($root, $parentPath, $newParent);
    }

    private static function rename(Node $root, array $path, array $op): Node {
        if (!isset($op['with']) || $op['with'] === '') {
            throw new ClickOpError('missing_with', "rename requires a non-empty 'with' tag");
        }
        $node = self::getAt($root, $path);
        $newNode = new Node($op['with'], $node->attrs, $node->children, $node->text, $node->appliedRules);
        return self::replaceAt($root, $path, $newNode);
    }

    private static function unwrap(Node $root, array $path): Node {
        if ($path === []) {
            throw new ClickOpError('unwrap_root', "unwrap requires a non-empty path");
        }
        return self::unwrapAt($root, $path);
    }

    private static function wrap(Node $root, array $path, array $op): Node {
        if (!isset($op['with']) || $op['with'] === '') {
            throw new ClickOpError('missing_with', "wrap requires a non-empty 'with' tag");
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
        self::validatePath($to);

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
            if (!is_int($idx) || $idx < 0 || $idx >= count($node->children)) {
                throw new ClickOpError(
                    'bad_path',
                    "Path index $idx out of range at depth $depth (node <{$node->tag}> has " . count($node->children) . " children)"
                );
            }
            $node = $node->children[$idx];
        }
        return $node;
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

        $idx  = $path[0];
        $rest = array_slice($path, 1);

        if (!is_int($idx) || $idx < 0 || $idx >= count($root->children)) {
            throw new ClickOpError(
                'bad_path',
                "Path index $idx out of range (node <{$root->tag}> has " . count($root->children) . " children)"
            );
        }

        $newChildren = [];
        foreach ($root->children as $i => $child) {
            if ($i === $idx) {
                if ($rest === []) {
                    // Leaf: replace or drop this child
                    if ($new !== null) {
                        $newChildren[] = $new;
                    }
                    // if $new === null, we skip (delete) the child
                } else {
                    $newChildren[] = self::replaceAt($child, $rest, $new);
                }
            } else {
                $newChildren[] = $child;
            }
        }

        return new Node($root->tag, $root->attrs, $newChildren, $root->text, $root->appliedRules);
    }

    /**
     * Unwrap the node at $path: splice its children into the parent at the same index.
     * $path must be non-empty.
     */
    private static function unwrapAt(Node $root, array $path): Node {
        if (count($path) === 1) {
            $idx    = $path[0];
            $target = self::getAt($root, $path);
            $newChildren = [];
            foreach ($root->children as $i => $child) {
                if ($i === $idx) {
                    // Splice in the target's children
                    foreach ($target->children as $tc) {
                        $newChildren[] = $tc;
                    }
                } else {
                    $newChildren[] = $child;
                }
            }
            return new Node($root->tag, $root->attrs, $newChildren, $root->text, $root->appliedRules);
        }

        // Recurse: navigate one level deeper
        $idx  = $path[0];
        $rest = array_slice($path, 1);

        if (!is_int($idx) || $idx < 0 || $idx >= count($root->children)) {
            throw new ClickOpError(
                'bad_path',
                "Path index $idx out of range (node <{$root->tag}> has " . count($root->children) . " children)"
            );
        }

        $newChildren = [];
        foreach ($root->children as $i => $child) {
            if ($i === $idx) {
                $newChildren[] = self::unwrapAt($child, $rest);
            } else {
                $newChildren[] = $child;
            }
        }
        return new Node($root->tag, $root->attrs, $newChildren, $root->text, $root->appliedRules);
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

        if (count($path) === 1) {
            // Insert at this level before index $path[0]
            $insertIdx = $path[0];
            $children  = $root->children;
            if (!is_int($insertIdx) || $insertIdx < 0 || $insertIdx > count($children)) {
                throw new ClickOpError(
                    'bad_path',
                    "Insert index $insertIdx out of range (node <{$root->tag}> has " . count($children) . " children)"
                );
            }
            $newChildren = [];
            foreach ($children as $i => $child) {
                if ($i === $insertIdx) {
                    $newChildren[] = $new;
                }
                $newChildren[] = $child;
            }
            if ($insertIdx === count($children)) {
                $newChildren[] = $new;
            }
            return new Node($root->tag, $root->attrs, $newChildren, $root->text, $root->appliedRules);
        }

        // Navigate deeper: the first path element selects which child to recurse into
        $idx  = $path[0];
        $rest = array_slice($path, 1);

        if (!is_int($idx) || $idx < 0 || $idx >= count($root->children)) {
            throw new ClickOpError(
                'bad_path',
                "Path index $idx out of range (node <{$root->tag}> has " . count($root->children) . " children)"
            );
        }

        $newChildren = [];
        foreach ($root->children as $i => $child) {
            if ($i === $idx) {
                $newChildren[] = self::insertAt($child, $rest, $new);
            } else {
                $newChildren[] = $child;
            }
        }
        return new Node($root->tag, $root->attrs, $newChildren, $root->text, $root->appliedRules);
    }

    /** Validate that path is an array of integers (values checked during navigation). */
    private static function validatePath(array $path): void {
        foreach ($path as $i => $idx) {
            if (!is_int($idx)) {
                throw new ClickOpError('bad_path', "Path element at position $i must be an integer");
            }
        }
    }
}
