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
        // (id first, then class) regardless of lexing order.
        $id      = null;
        $classes = [];

        while ($this->pos < $this->len) {
            $ch = $this->peek();
            if ($ch === '#') {
                $this->pos++;
                $id = $this->consumeIdent();
            } elseif ($ch === '.') {
                $this->pos++;
                $classes[] = $this->consumeIdent();
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
}
