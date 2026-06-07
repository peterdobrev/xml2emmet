<?php
declare(strict_types=1);
namespace App;

/**
 * Render a Node tree back to Emmet abbreviation syntax.
 *
 * Mode controls attribute serialization:
 *   `html` — use `#id`, `.class` shortcuts; remaining attrs as `[k="v"]`.
 *   `xml`  — every attribute emitted as `[k="v"]` in declaration order.
 *
 * Synthetic tags:
 *   `_root` renders as a chain of its children joined by `+`, parenthesized
 *           when it appears among siblings that would otherwise misbind.
 *   `#text` is dropped (Emmet has no inline text-sibling syntax).
 *
 * Sibling lists are run-length encoded into `node*N` repetitions when
 * adjacent children are structurally equal (per NodeEquality).
 */
final class EmmetEmitter {
    private function __construct() {}

    public static function emit(Node $n, string $mode = 'html'): string {
        return self::emitNode($n, $mode, false);
    }

    private static function emitNode(Node $n, string $mode, bool $needsParensIfSiblings): string {
        // _root is a synthetic container: emit only its children joined by '+'
        if ($n->tag === '_root') {
            $chain = self::emitChildren($n->children, $mode);
            return $needsParensIfSiblings ? '(' . $chain . ')' : $chain;
        }

        // #text synthetic node: Emmet has no standalone text-node syntax — skip it.
        if ($n->tag === '#text') {
            return '';
        }

        // Emit tag decorators (id, classes, extra attrs, text)
        $out = $n->tag . self::emitAttrs($n->attrs, $mode);

        if ($n->text !== null) {
            $safeText = str_replace(['\\', '}', '$'], ['\\\\', '\\}', '\\$'], $n->text);
            $out .= '{' . $safeText . '}';
        }

        // Recurse into children
        if ($n->children !== []) {
            $out .= '>' . self::emitChildren($n->children, $mode);
        }

        return $out;
    }

    /** @param array<string,string> $attrs */
    private static function emitAttrs(array $attrs, string $mode): string {
        if ($mode === 'xml') {
            // In xml mode, ALL attributes are emitted as [k="v"] in declaration order.
            // Never use #id or .class shortcuts.
            if ($attrs === []) return '';
            $pairs = [];
            foreach ($attrs as $k => $v) {
                $pairs[] = $k . '="' . self::escapeAttrValue($v) . '"';
            }
            return '[' . implode(' ', $pairs) . ']';
        }

        // html mode: use #id, .class shortcuts; remaining attrs in [k="v"]
        $out     = '';
        $id      = $attrs['id']    ?? '';
        $classes = $attrs['class'] ?? '';
        if ($id !== '') {
            $out .= '#' . $id;
        }
        if ($classes !== '') {
            foreach (preg_split('/\s+/', trim($classes)) as $cls) {
                if ($cls !== '') $out .= '.' . $cls;
            }
        }
        $skip   = ['id' => true, 'class' => true];
        $extras = array_filter(
            $attrs,
            static fn($k) => !isset($skip[$k]),
            ARRAY_FILTER_USE_KEY
        );
        if ($extras !== []) {
            $pairs = [];
            foreach ($extras as $k => $v) {
                $pairs[] = $k . '="' . self::escapeAttrValue($v) . '"';
            }
            $out .= '[' . implode(' ', $pairs) . ']';
        }
        return $out;
    }

    /** Backslash-escape `"` and `\` for Emmet [k="…"] values. */
    private static function escapeAttrValue(string $v): string {
        return str_replace(['"', '\\'], ['\\"', '\\\\'], $v);
    }

    /** @param Node[] $children */
    private static function emitChildren(array $children, string $mode): string {
        // Strip #text synthetic nodes — Emmet has no inline text-sibling syntax.
        $children = array_values(array_filter($children, fn(Node $c) => $c->tag !== '#text'));
        if ($children === []) return '';

        // Build run-length encoded list: [[Node, int], ...]
        $runs = [];
        foreach ($children as $child) {
            if ($runs !== [] && NodeEquality::equals($child, $runs[count($runs) - 1][0])) {
                $runs[count($runs) - 1][1]++;
            } else {
                $runs[] = [$child, 1];
            }
        }

        $multipleRuns = count($runs) > 1;
        $parts        = [];
        $lastIdx      = count($runs) - 1;
        foreach ($runs as $idx => [$child, $runLen]) {
            // A _root sub-group among siblings needs parens to avoid ambiguity
            $needsParens = $child->tag === '_root' && $multipleRuns;
            $subtree     = self::emitNode($child, $mode, $needsParens);
            // Wrap in parens if there are multiple runs, the subtree is NOT the last,
            // and it contains '>' — otherwise the '>' would "steal" subsequent siblings.
            if ($multipleRuns && $idx < $lastIdx && str_contains($subtree, '>')) {
                $subtree = '(' . $subtree . ')';
            }
            if ($runLen > 1) {
                // Wrap in parens if the subtree is complex (has children → contains '>')
                if ($child->children !== []) {
                    $subtree = '(' . $subtree . ')';
                }
                $subtree .= '*' . $runLen;
            }
            $parts[] = $subtree;
        }
        return implode('+', $parts);
    }
}
