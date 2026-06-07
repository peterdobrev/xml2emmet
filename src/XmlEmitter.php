<?php
declare(strict_types=1);
namespace App;

/**
 * Render a Node tree back to XML or HTML source.
 *
 * Two synthetic tags get special treatment:
 *   `_root`  — container for multi-sibling top-level emit; renders children only.
 *   `#text`  — text run; renders as escaped text content with no element wrapper.
 *
 * Mode `xml` always self-closes empty elements ({@code <br/>}); mode `html`
 * uses HTML void-element rules (no slash on void tags, open+close pair on
 * non-void empties).
 */
final class XmlEmitter {
    private function __construct() {}

    public static function emit(Node $n, string $mode = 'xml'): string {
        return self::emitNode($n, $mode);
    }

    private static function emitNode(Node $n, string $mode): string {
        // _root synthetic container: emit children only — no element tags.
        if ($n->tag === '_root') {
            $out = '';
            foreach ($n->children as $child) {
                $out .= self::emitNode($child, $mode);
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
        if ($mode === 'html' && HtmlVoidElements::contains($n->tag)) {
            return $open . '>';
        }

        // Emit body: recursive children take priority over inline $text.
        if ($n->children !== []) {
            $body = '';
            foreach ($n->children as $child) {
                $body .= self::emitNode($child, $mode);
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

    /** @param array<string,string> $attrs */
    private static function emitAttrs(array $attrs): string {
        if ($attrs === []) return '';
        $pairs = [];
        foreach ($attrs as $k => $v) {
            $pairs[] = $k . '="' . htmlspecialchars($v, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '"';
        }
        return ' ' . implode(' ', $pairs);
    }
}
