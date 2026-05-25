<?php
namespace App;
final class TransformEngine {
    public static function emmetParse(string $abbr): Node {
        $p = new EmmetParser($abbr);
        return $p->parse();
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

        // Emit tag decorators (id, classes, extra attrs, text)
        $out = $n->tag;
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
                $pairs[] = $k . '="' . $v . '"';
            }
            $out .= '[' . implode(' ', $pairs) . ']';
        }
        if ($n->text !== null) {
            $out .= '{' . $n->text . '}';
        }

        // Recurse into children
        if ($n->children !== []) {
            $out .= '>' . self::emitChildren($n->children, $mode);
        }

        return $out;
    }

    /** @param Node[] $children */
    private static function emitChildren(array $children, string $mode): string {
        $parts = [];
        $count = count($children);
        foreach ($children as $child) {
            // A _root sub-group among siblings needs parens to avoid ambiguity
            $needsParens = $child->tag === '_root' && $count > 1;
            $parts[] = self::emitNode($child, $mode, $needsParens);
        }
        return implode('+', $parts);
    }
}
