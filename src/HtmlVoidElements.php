<?php
namespace App;

/**
 * The HTML void-element list. Used by both the parser (treat as self-closing
 * even without a `/`) and the emitter (omit the closing tag).
 */
final class HtmlVoidElements {
    /** @var string[] */
    public const TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
        'input', 'link', 'meta', 'source', 'track', 'wbr',
    ];

    public static function contains(string $tag): bool {
        return in_array(strtolower($tag), self::TAGS, true);
    }
}
