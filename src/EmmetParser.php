<?php
namespace App;
final class EmmetParser {
    private int $pos = 0;
    private int $len;

    public function __construct(private readonly string $input) {
        $this->len = strlen($input);
    }

    // ── public entry points ──────────────────────────────────────────────────

    /**
     * Top-level parse entry point. Delegates to parseExpr() which runs the
     * stack-based loop, then wraps multiple top-level siblings in `_root`.
     */
    public function parse(): Node {
        $siblings = $this->parseExpr();
        return $this->siblingsToNode($siblings);
    }

    /**
     * Convert a sibling list to a single Node: one node is returned as-is;
     * multiple siblings are wrapped in a synthetic `_root` node.
     *
     * @param Node[] $siblings
     */
    private function siblingsToNode(array $siblings): Node {
        if (count($siblings) === 1) {
            return $siblings[0];
        }
        $root = new Node('_root');
        foreach ($siblings as $sibling) {
            $root = $root->withChild($sibling);
        }
        return $root;
    }

    /**
     * Core iterative parser using an explicit depth stack.
     *
     * Each stack level is a Node[] of siblings at that depth. A parallel
     * $parents stack records the Node that owns each level's sibling list
     * (so we know where to attach children when popping).
     *
     * Operators handled:
     *   `>`  — push a new child level (descend into last element)
     *   `+`  — add a sibling at the current level
     *   `^`  — pop one level, attaching collected children (repeatable)
     *   `(`  — parse a grouped sub-expression (recursive call to parseExpr)
     *
     * Stops when end-of-input is reached or a `)` is the next character
     * (so the same method is reused by parseGroup without modification).
     *
     * @return Node[]
     */
    private function parseExpr(): array {
        // stack[i] = sibling list at depth i
        $stack = [[]];
        // parents[i] = the Node whose children are being built at stack[i+1];
        // parents is always one shorter than $stack (no parent for level 0).
        $parents = [];

        while (true) {
            // Parse the next operand: either a `(group)` or a plain element.
            if ($this->peek() === '(') {
                $this->consume(); // consume `(`
                $inner = $this->parseExpr(); // recurse; stops at `)`
                $this->consume(); // consume `)`
                // Treat the group as a single operand (wrap if multiple siblings)
                $node = $this->siblingsToNode($inner);
            } else {
                $node = $this->parseElement();
            }

            // Append this operand to the current sibling level
            $top = count($stack) - 1;
            $stack[$top][] = $node;

            $ch = $this->peek();

            if ($ch === '>') {
                // Descend: open a new child level for $node
                $this->consume(); // consume `>`
                $parents[] = $node;
                $stack[] = []; // push empty sibling list for children
            } elseif ($ch === '+') {
                // Sibling: stay at the same level, just loop
                $this->consume(); // consume `+`
            } elseif ($ch === '^') {
                // Climb-up: pop one level per `^`, attaching children upward
                while ($this->peek() === '^') {
                    $this->consume(); // consume one `^`
                    if (count($stack) <= 1) {
                        // Already at root level — cannot climb further
                        break;
                    }
                    // Attach the current child list to the parent node
                    $children = array_pop($stack);
                    $parentNode = array_pop($parents);
                    foreach ($children as $child) {
                        $parentNode = $parentNode->withChild($child);
                    }
                    // Replace the last element of the now-current level with
                    // the updated parent (the one that now has its children).
                    $top = count($stack) - 1;
                    $stack[$top][count($stack[$top]) - 1] = $parentNode;
                }
                // After climbing, if next char is `+` consume it and continue;
                // otherwise let the next iteration's operand parse handle it.
                if ($this->peek() === '+') {
                    $this->consume(); // consume `+` that follows `^`
                }
                // If the next char is something that starts an operand, loop.
                // If it's `)` or end-of-input the outer while condition handles it.
                if ($this->peek() === '' || $this->peek() === ')') {
                    break;
                }
            } else {
                // End of input or `)` — stop the loop
                break;
            }
        }

        // Unwind any remaining open levels (e.g. a plain `div>span` with no `^`)
        while (count($stack) > 1) {
            $children = array_pop($stack);
            $parentNode = array_pop($parents);
            foreach ($children as $child) {
                $parentNode = $parentNode->withChild($child);
            }
            $top = count($stack) - 1;
            $stack[$top][count($stack[$top]) - 1] = $parentNode;
        }

        return $stack[0];
    }

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
                $text = ($text ?? '') . $this->parseTextLiteral();
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
                // Valueless attribute — grammar §3: assign empty string
                $attrs[$key] = '';
                continue;
            }
            // Consume value
            $quote = $this->peek();
            if ($quote === '"' || $quote === "'") {
                $this->pos++; // consume opening quote
                $value = '';
                while ($this->pos < $this->len && $this->peek() !== $quote) {
                    $ch = $this->peek();
                    if ($ch === '\\') {
                        $this->pos++; // consume backslash
                        $next = $this->peek();
                        if ($next === $quote || $next === '\\') {
                            $value .= $next;
                            $this->pos++;
                        } else {
                            $value .= '\\'; // literal backslash
                        }
                        continue;
                    }
                    $value .= $ch;
                    $this->pos++;
                }
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
