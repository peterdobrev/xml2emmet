<?php
namespace App;
final class EmmetParser {
    private int $pos = 0;
    private int $len;

    public function __construct(private readonly string $input) {
        $this->len = strlen($input);
    }

    // ── public entry points ──────────────────────────────────────────────────

    public function parseElement(): Node {
        $tag = $this->consumeIdent();
        if ($tag === '') {
            throw new \InvalidArgumentException(
                "Expected tag name at position {$this->pos}"
            );
        }

        // Collect all qualifiers first so we can write attrs in canonical order
        // (id first, then class, then explicit [k=v] attrs) regardless of lexing order.
        $id         = null;
        $classes    = [];
        $attrsList  = []; // list of [k => v] maps in encounter order
        $text       = null;

        while ($this->pos < $this->len) {
            $ch = $this->peek();
            if ($ch === '#') {
                $this->pos++;
                $id = $this->consumeIdent();
            } elseif ($ch === '.') {
                $this->pos++;
                $classes[] = $this->consumeIdent();
            } elseif ($ch === '[') {
                $this->pos++;
                $attrsList[] = $this->parseAttrList();
            } elseif ($ch === '{') {
                $this->pos++;
                $text = $this->parseTextLiteral();
            } else {
                break;
            }
        }

        $node = new Node($tag);

        if ($id !== null) {
            $node = $node->withAttr('id', $id);
        }
        if ($classes !== []) {
            $node = $node->withAttr('class', implode(' ', $classes));
        }
        foreach ($attrsList as $map) {
            foreach ($map as $k => $v) {
                $node = $node->withAttr($k, $v);
            }
        }
        if ($text !== null) {
            $node = $node->withText($text);
        }

        return $node;
    }

    // ── low-level helpers ────────────────────────────────────────────────────

    /** Return the character at the current position without advancing. */
    public function peek(): string {
        return $this->pos < $this->len ? $this->input[$this->pos] : '';
    }

    /** Advance by one and return the consumed character. */
    public function consume(): string {
        return $this->pos < $this->len ? $this->input[$this->pos++] : '';
    }

    /** Advance if the current character equals $char; return whether it matched. */
    public function consumeIf(string $char): bool {
        if ($this->peek() === $char) {
            $this->pos++;
            return true;
        }
        return false;
    }

    /** Consume [A-Za-z][A-Za-z0-9_-]* and return the matched string (may be empty). */
    public function consumeIdent(): string {
        if ($this->pos >= $this->len) {
            return '';
        }
        // First char: letter only
        if (!ctype_alpha($this->input[$this->pos])) {
            return '';
        }
        $start = $this->pos++;
        while ($this->pos < $this->len) {
            $ch = $this->input[$this->pos];
            if (ctype_alnum($ch) || $ch === '_' || $ch === '-') {
                $this->pos++;
            } else {
                break;
            }
        }
        return substr($this->input, $start, $this->pos - $start);
    }

    /**
     * Parse whitespace-separated key=value pairs up to the closing `]`.
     * The opening `[` has already been consumed by the caller.
     * Returns a map of attribute name => value.
     *
     * @return array<string,string>
     */
    private function parseAttrList(): array {
        $attrs = [];
        while ($this->pos < $this->len && $this->peek() !== ']') {
            // Skip whitespace between pairs
            while ($this->pos < $this->len && ctype_space($this->peek())) {
                $this->pos++;
            }
            if ($this->peek() === ']' || $this->pos >= $this->len) {
                break;
            }
            // Consume key: everything up to `=`
            $keyStart = $this->pos;
            while ($this->pos < $this->len && $this->peek() !== '=' && $this->peek() !== ']' && !ctype_space($this->peek())) {
                $this->pos++;
            }
            $key = substr($this->input, $keyStart, $this->pos - $keyStart);
            if ($key === '') {
                break;
            }
            // Consume `=`
            if (!$this->consumeIf('=')) {
                // Attribute with no value — skip for now
                continue;
            }
            // Consume value
            $quote = $this->peek();
            if ($quote === '"' || $quote === "'") {
                $this->pos++; // consume opening quote
                $valStart = $this->pos;
                while ($this->pos < $this->len && $this->peek() !== $quote) {
                    $this->pos++;
                }
                $value = substr($this->input, $valStart, $this->pos - $valStart);
                $this->consumeIf($quote); // consume closing quote
            } else {
                // Bare value: terminated by whitespace, `]`, `"`, or `'`
                $valStart = $this->pos;
                while ($this->pos < $this->len) {
                    $ch = $this->peek();
                    if ($ch === ']' || $ch === '"' || $ch === "'" || ctype_space($ch)) {
                        break;
                    }
                    $this->pos++;
                }
                $value = substr($this->input, $valStart, $this->pos - $valStart);
            }
            $attrs[$key] = $value;
        }
        $this->consumeIf(']'); // consume closing `]`
        return $attrs;
    }

    /**
     * Parse literal text up to the closing `}`.
     * The opening `{` has already been consumed by the caller.
     * Recognises `\{` and `\}` as escape sequences for literal braces.
     * Braces do not nest.
     */
    private function parseTextLiteral(): string {
        $buf = '';
        while ($this->pos < $this->len) {
            $ch = $this->peek();
            if ($ch === '}') {
                $this->pos++; // consume closing `}`
                break;
            }
            if ($ch === '\\') {
                $this->pos++; // consume backslash
                $next = $this->peek();
                if ($next === '{' || $next === '}') {
                    $buf .= $next;
                    $this->pos++;
                } else {
                    $buf .= '\\'; // literal backslash
                    // leave next char to be consumed on next iteration
                }
                continue;
            }
            $buf .= $ch;
            $this->pos++;
        }
        return $buf;
    }
}
