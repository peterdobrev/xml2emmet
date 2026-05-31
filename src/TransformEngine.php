<?php
declare(strict_types=1);
namespace App;
final class TransformEngine {
    private function __construct() {}
    public static function emmetParse(string $abbr): Node {
        $p = new EmmetParser($abbr);
        return $p->parse();
    }

    public static function xmlParse(string $src, string $mode = 'xml'): Node {
        return (new XmlParser($src, $mode))->parse();
    }

    public static function xmlEmit(Node $n, string $mode = 'xml'): string {
        return self::xmlEmitNode($n, $mode);
    }

    private static function xmlEmitNode(Node $n, string $mode): string {
        // _root synthetic container: emit children only — no element tags.
        if ($n->tag === '_root') {
            $out = '';
            foreach ($n->children as $child) {
                $out .= self::xmlEmitNode($child, $mode);
            }
            return $out;
        }

        // #text synthetic node: emit escaped text only — no element tags.
        if ($n->tag === '#text') {
            return htmlspecialchars($n->text ?? '', ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
        }

        $attrs = self::emitAttrs($n->attrs);
        $open  = '<' . $n->tag . $attrs;

        // HTML void elements: no closing slash, no closing tag (e.g. <br>).
        if ($mode === 'html' && self::isHtmlVoidElement($n->tag)) {
            return $open . '>';
        }

        // Emit body: recursive children take priority over inline $text.
        if ($n->children !== []) {
            $body = '';
            foreach ($n->children as $child) {
                $body .= self::xmlEmitNode($child, $mode);
            }
            return $open . '>' . $body . '</' . $n->tag . '>';
        }

        // Pure text content.
        if ($n->text !== null) {
            $body = htmlspecialchars($n->text, ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
            return $open . '>' . $body . '</' . $n->tag . '>';
        }

        // Empty element: xml uses self-closing shorthand; html uses open+close pair.
        if ($mode === 'html') {
            return $open . '></' . $n->tag . '>';
        }
        return $open . '/>';
    }

    private static function isHtmlVoidElement(string $tag): bool {
        return HtmlVoidElements::contains($tag);
    }

    /** @param array<string,string> $attrs */
    private static function emitAttrs(array $attrs): string {
        if ($attrs === []) return '';
        $pairs = [];
        foreach ($attrs as $k => $v) {
            $pairs[] = $k . '="' . htmlspecialchars($v, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '"';
        }
        return ' ' . implode(' ', $pairs);
    }

    /**
     * Apply a list of rules to the tree, returning a new (possibly transformed) tree.
     *
     * @param Rule[] $rules
     */
    public static function applyRules(Node $root, array $rules): Node {
        return RulesEngine::apply($root, $rules);
    }

    /**
     * Apply a single click-op to the tree, returning a new tree.
     *
     * @param array{type: string, path: int[], with?: string, to?: int[]} $op
     */
    public static function applyClickOp(Node $root, array $op): Node {
        return ClickOps::apply($root, $op);
    }

    public static function emmetEmit(Node $n, string $mode = 'html'): string {
        return self::emitNode($n, $mode, false);
    }

    private static function emitNode(Node $n, string $mode, bool $needsParensIfSiblings): string {
        // _root is a synthetic container: emit only its children joined by '+'
        if ($n->tag === '_root') {
            $chain = self::emitChildren($n->children, $mode);
            return $needsParensIfSiblings ? '(' . $chain . ')' : $chain;
        }

        // #text synthetic node: emit only the text content (no tag)
        if ($n->tag === '#text') {
            if ($n->text !== null) {
                $safeText = str_replace(['\\', '}', '$'], ['\\\\', '\\}', '\\$'], $n->text);
                return '{' . $safeText . '}';
            }
            return '';
        }

        // Emit tag decorators (id, classes, extra attrs, text)
        $out = $n->tag;
        if ($mode === 'xml') {
            // In xml mode, ALL attributes are emitted as [k="v"] in declaration order.
            // Never use #id or .class shortcuts.
            if ($n->attrs !== []) {
                $pairs = [];
                foreach ($n->attrs as $k => $v) {
                    $pairs[] = $k . '="' . str_replace(['"', '\\'], ['\\"', '\\\\'], $v) . '"';
                }
                $out .= '[' . implode(' ', $pairs) . ']';
            }
        } else {
            // html mode: use #id, .class shortcuts; remaining attrs in [k="v"]
            $id      = $n->attrs['id']    ?? '';
            $classes = $n->attrs['class'] ?? '';
            if ($id !== '') {
                $out .= '#' . $id;
            }
            if ($classes !== '') {
                foreach (preg_split('/\s+/', trim($classes)) as $cls) {
                    if ($cls !== '') $out .= '.' . $cls;
                }
            }
            $skip = ['id' => true, 'class' => true];
            $extras = array_filter(
                $n->attrs,
                static fn($k) => !isset($skip[$k]),
                ARRAY_FILTER_USE_KEY
            );
            if ($extras !== []) {
                $pairs = [];
                foreach ($extras as $k => $v) {
                    $pairs[] = $k . '="' . str_replace(['"', '\\'], ['\\"', '\\\\'], $v) . '"';
                }
                $out .= '[' . implode(' ', $pairs) . ']';
            }
        }
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

    /** @param Node[] $children */
    private static function emitChildren(array $children, string $mode): string {
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
        $parts = [];
        $lastIdx = count($runs) - 1;
        foreach ($runs as $idx => [$child, $runLen]) {
            // A _root sub-group among siblings needs parens to avoid ambiguity
            $needsParens = $child->tag === '_root' && $multipleRuns;
            $subtree = self::emitNode($child, $mode, $needsParens);
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
