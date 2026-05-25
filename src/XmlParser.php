<?php
namespace App;

/**
 * Minimal XML/HTML scanner: parses an XML element (self-closing or open/close pair) with
 * optional attributes, children, and text into a Node tree. Decodes the five standard
 * XML entities (&amp; &lt; &gt; &quot; &apos;) in attribute values and text content.
 *
 * When $mode is 'html', the void-element list (area, base, br, col, embed, hr, img,
 * input, link, meta, source, track, wbr) is treated as self-closing even without a '/'.
 */
final class XmlParser {
    private int $pos = 0;
    private int $len;

    /** HTML void elements (lowercase). */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img',
        'input', 'link', 'meta', 'source', 'track', 'wbr',
    ];

    public function __construct(
        private readonly string $src,
        private readonly string $mode = 'xml',
    ) {
        $this->len = strlen($src);
    }

    // ── Public entry ─────────────────────────────────────────────────────────

    public function parse(): Node {
        $this->skipWhitespace();
        $node = $this->parseElement();
        $this->skipWhitespace();
        if ($this->pos !== $this->len) {
            throw new XmlParseError(
                "Unexpected trailing content at position {$this->pos}"
            );
        }
        return $node;
    }

    // ── Element parsing ───────────────────────────────────────────────────────

    private function parseElement(): Node {
        $openPos = $this->pos;
        $this->expect('<');

        $tag = $this->parseName();

        // Parse attributes
        $attrs = [];
        while (true) {
            $this->skipWhitespace();
            if ($this->peek() === '/' || $this->peek() === '>') {
                break;
            }
            [$name, $value] = $this->parseAttribute();
            $attrs[$name] = $value;
        }

        // Self-closing: `<tag .../>`
        if ($this->peek() === '/') {
            $this->pos++; // consume '/'
            $this->expect('>');
            return $this->buildNode($tag, $attrs, []);
        }

        // Opening tag end: `<tag ...>`
        $this->expect('>');

        // HTML mode: void elements are implicitly self-closing — no children, no close tag.
        if ($this->isVoidElement($tag)) {
            return $this->buildNode($tag, $attrs, []);
        }

        // Child loop: alternate text runs and child elements until `</`
        $fragments = []; // each entry is either ['text', string] or ['node', Node]
        while (true) {
            // EOF before closing tag
            if ($this->pos >= $this->len) {
                throw new XmlParseError(
                    "Unclosed tag <$tag> opened at position $openPos: reached end of input"
                );
            }
            // End of child content?
            if ($this->pos + 1 < $this->len
                && $this->src[$this->pos] === '<'
                && $this->src[$this->pos + 1] === '/') {
                break;
            }
            if ($this->peek() === '<') {
                $fragments[] = ['node', $this->parseElement()];
            } else {
                $fragments[] = ['text', $this->parseTextRun()];
            }
        }

        // Closing tag: `</tag>`
        $this->expect('<');
        $this->expect('/');
        $closeTag = $this->parseName();
        if ($closeTag !== $tag) {
            throw new XmlParseError(
                "Mismatched tags at position {$this->pos}: opened <$tag> at $openPos, closed </$closeTag>"
            );
        }
        $this->skipWhitespace();
        $this->expect('>');

        // Decide tree shape per conventions:
        // - Only one text fragment and no element children → set $text directly
        // - Otherwise → all text fragments become Node('#text') children
        $textCount = 0;
        $nodeCount = 0;
        foreach ($fragments as [$type]) {
            if ($type === 'text') $textCount++;
            else $nodeCount++;
        }

        if ($textCount === 1 && $nodeCount === 0) {
            // Pure text content
            $node = $this->buildNode($tag, $attrs, []);
            return $node->withText($fragments[0][1]);
        }

        // Mixed or element-only: convert text fragments to #text nodes
        $children = [];
        foreach ($fragments as [$type, $value]) {
            if ($type === 'node') {
                $children[] = $value;
            } else {
                $children[] = (new Node('#text'))->withText($value);
            }
        }
        return $this->buildNode($tag, $attrs, $children);
    }

    // ── Attribute parsing ─────────────────────────────────────────────────────

    /** @return array{string, string} [name, value] */
    private function parseAttribute(): array {
        $name = $this->parseName();
        $this->skipWhitespace();
        $this->expect('=');
        $this->skipWhitespace();
        $value = $this->parseAttributeValue();
        return [$name, $value];
    }

    private function parseAttributeValue(): string {
        $quote = $this->current();
        if ($quote !== '"' && $quote !== "'") {
            throw new XmlParseError(
                "Expected quoted attribute value at position {$this->pos}, got '{$quote}'"
            );
        }
        $this->pos++; // consume opening quote
        $raw = '';
        while ($this->pos < $this->len && $this->current() !== $quote) {
            $raw .= $this->current();
            $this->pos++;
        }
        $this->expect($quote); // consume closing quote
        return $this->decodeEntities($raw);
    }

    // ── Lexer helpers ─────────────────────────────────────────────────────────

    /**
     * Parse an XML name: starts with letter or '_', followed by letters, digits,
     * '-', '_', '.', ':'.
     */
    private function parseName(): string {
        if ($this->pos >= $this->len) {
            throw new XmlParseError("Expected XML name but reached end of input");
        }
        $start = $this->pos;
        $first = $this->current();
        if (!ctype_alpha($first) && $first !== '_') {
            throw new XmlParseError(
                "Expected XML name start at position {$this->pos}, got '$first'"
            );
        }
        $this->pos++;
        while ($this->pos < $this->len) {
            $c = $this->current();
            if (ctype_alnum($c) || $c === '-' || $c === '_' || $c === '.' || $c === ':') {
                $this->pos++;
            } else {
                break;
            }
        }
        return substr($this->src, $start, $this->pos - $start);
    }

    private function skipWhitespace(): void {
        while ($this->pos < $this->len && ctype_space($this->src[$this->pos])) {
            $this->pos++;
        }
    }

    /** Scan a text run up to the next `<`, applying entity decoding. */
    private function parseTextRun(): string {
        $raw = '';
        while ($this->pos < $this->len && $this->src[$this->pos] !== '<') {
            $raw .= $this->src[$this->pos];
            $this->pos++;
        }
        return $this->decodeEntities($raw);
    }

    private function peek(): ?string {
        return $this->pos < $this->len ? $this->src[$this->pos] : null;
    }

    private function current(): string {
        if ($this->pos >= $this->len) {
            throw new XmlParseError("Unexpected end of input at position {$this->pos}");
        }
        return $this->src[$this->pos];
    }

    private function expect(string $char): void {
        if ($this->pos >= $this->len) {
            throw new XmlParseError(
                "Expected '$char' but reached end of input"
            );
        }
        if ($this->src[$this->pos] !== $char) {
            throw new XmlParseError(
                "Expected '$char' at position {$this->pos}, got '{$this->src[$this->pos]}'"
            );
        }
        $this->pos++;
    }

    // ── HTML void-element check ───────────────────────────────────────────────

    /** Returns true when in html mode and $tag is a void element (case-insensitive). */
    private function isVoidElement(string $tag): bool {
        return $this->mode === 'html'
            && in_array(strtolower($tag), self::VOID_ELEMENTS, true);
    }

    // ── Entity decoding ───────────────────────────────────────────────────────

    private function decodeEntities(string $raw): string {
        return strtr($raw, [
            '&amp;'  => '&',
            '&lt;'   => '<',
            '&gt;'   => '>',
            '&quot;' => '"',
            '&apos;' => "'",
        ]);
    }

    // ── Node construction ─────────────────────────────────────────────────────

    /**
     * @param array<string,string> $attrs
     * @param Node[] $children
     */
    private function buildNode(string $tag, array $attrs, array $children): Node {
        $node = new Node($tag, $attrs, $children);
        return $node;
    }
}
