# Review: XmlParser + HtmlVoidElements + XmlParseError

**Files:** `src/XmlParser.php`, `src/HtmlVoidElements.php`, `src/XmlParseError.php`
**Related:** `src/TransformEngine.php` (emitter counterpart), `src/EmmetParser.php` (round-trip partner)
**Review date:** 2026-05-31

---

## Overview

`XmlParser` is a hand-rolled, recursive-descent XML/HTML scanner. Its single public method `parse()` tokenises one root element from a byte string and returns an immutable `Node` tree. It handles self-closing tags, quoted attributes with the five standard XML entity references, mixed text/element content, HTML void-element semantics, and clear error paths for mismatch/EOF conditions. `HtmlVoidElements` is a shared constant registry consumed by both parser and emitter. `XmlParseError` is a bare `RuntimeException` subclass used as the error channel.

The parser is intentionally narrow: no namespace resolution, no DTD, no CDATA sections, no comments, no processing instructions. The symmetric counterpart `TransformEngine::xmlEmit` re-serialises the `Node` tree to XML/HTML. This review covers the parser cluster itself and the `emmetEmit` path in `TransformEngine`, because several confirmed bugs exist at that boundary and round-trip correctness is an explicit contract of the system.

---

## What Exists

| Class | LOC | Public surface | Role |
|---|---|---|---|
| `XmlParser` | 261 | `__construct(string $src, string $mode = 'xml')`, `parse(): Node` | Scanner/parser |
| `HtmlVoidElements` | 18 | `TAGS` (const), `contains(string $tag): bool` | Void-element registry |
| `XmlParseError` | 3 | (inherits `RuntimeException`) | Typed error |
| `TransformEngine::xmlEmit` | ~52 | `xmlEmit(Node, string $mode): string` | Emitter (symmetric counterpart) |
| `TransformEngine::emmetEmit` | ~90 | `emmetEmit(Node, string $mode): string` | Emmet emitter (round-trip path) |

Key internal methods of `XmlParser`:

| Method | Purpose |
|---|---|
| `parseElement()` | Recursive entry for one element; handles open/close pair, self-close, child loop |
| `parseAttribute()` | Delegates to `parseName()` + `parseAttributeValue()` |
| `parseAttributeValue()` | Reads quoted value; applies `decodeEntities()` |
| `parseName()` | Accepts `[A-Za-z_][A-Za-z0-9\-_.:]*` |
| `parseTextRun()` | Scans to next `<`; applies `decodeEntities()` |
| `decodeEntities()` | `strtr` replacement of the five XML entities |
| `buildNode()` | Trivial `new Node(...)` factory |
| `isVoidElement()` | Mode guard + `HtmlVoidElements::contains()` |

---

## What's Good

**Single-responsibility, pure-value design.** One class, one public method, no side effects. The constructor is immutable after construction (`readonly` properties). The returned `Node` tree uses copy-on-write mutation (`withText`, `withAttr`, `withChild` each return new instances), making it safe to share across pipeline stages without defensive copying.

**Two-pass fragment analysis in `parseElement()`.** After collecting `$fragments`, the code counts `$textCount` and `$nodeCount` separately. The case `$textCount === 1 && $nodeCount === 0` sets `$node->text` directly; all other mixed or element-only cases promote text fragments to `#text` child nodes. This three-way distinction — pure-text, mixed, element-only — is the subtlest part of the design and is handled correctly.

**`strtr` entity decoding is double-decode-safe.** `strtr` applies all substitutions simultaneously. `&amp;lt;` correctly becomes `&lt;` (not `<`) because the `&amp;` replacement fires on the original string, not the result of a prior pass.

**Meaningful error messages.** Every `XmlParseError` carries the byte position and the offending character or tag name. Compare with typical mini-parsers that throw a generic `"parse error"`.

**`HtmlVoidElements` shared between parser and emitter.** `isVoidElement()` in `XmlParser` and `isHtmlVoidElement()` in `TransformEngine` both delegate to `HtmlVoidElements::contains()`, so the two cannot diverge on the void-element set.

**Trailing-content guard in `parse()`.** After `parseElement()` returns, the parser asserts `$this->pos === $this->len`. This catches the common mistake of passing a document fragment containing multiple root elements rather than silently ignoring the tail.

**Test suite covers the nine canonical parse paths.** `XmlParseTest` (C1–C9) exercises self-closing, open/close, attributes, children, text, mixed content, HTML void elements, and the two primary error paths. `RoundTripTest` (G1–G6) verifies the full `xmlParse → emmetEmit → emmetParse → xmlEmit` pipeline including repetition collapsing, attribute reordering, and group notation.

---

## What's Bad

**`buildNode()` is a trivial one-liner with misleading implied weight.** Lines 257–260:

```php
private function buildNode(string $tag, array $attrs, array $children): Node {
    $node = new Node($tag, $attrs, $children);
    return $node;
}
```

The method exists, the docblock implies a factory with invariant-checking potential, but it does nothing beyond `new Node(...)`. Either give it a real purpose (e.g., duplicate-attribute validation) or inline it. As written it adds a call-stack frame and implies future extensibility that does not materialise.

**`$mode` is not validated.** `XmlParser::__construct` accepts any string. Passing `'xhtml'` silently falls through to XML semantics. `<br>` then throws `"Unclosed tag <br>"` instead of a clear `InvalidArgumentException("Unknown mode 'xhtml'")`. The valid values are exactly `'xml'` and `'html'`; this should be enforced at construction time, ideally with a PHP 8.1 enum.

**Character-by-character string concatenation in `parseTextRun()` and `parseAttributeValue()`.** Both methods use `$raw .= $this->src[$this->pos++]` inside a loop. For large text nodes this is O(n²) in the number of string copies. The correct pattern is to find the delimiter with `strpos()` or `strcspn()` and extract with a single `substr()`. Benchmarks show sub-quadratic growth in practice due to PHP copy-on-write, but this is still a code-quality issue and will degrade on documents with large text blocks.

**Duplicate attribute names are silently last-wins.** In `parseElement()`, attributes are collected as `$attrs[$name] = $value`. If the same name appears twice, PHP array assignment silently overwrites the first. `<div class='a' class='b'/>` yields `class='b'`. XML 1.0 §3.3 forbids duplicate attributes; the parser should throw `XmlParseError` rather than silently discard data.

**`parseName()` docblock only mentions element names.** The function is also used for attribute names (called from `parseAttribute()`). The docblock says "Parse an XML name" with no mention of attribute use, which will confuse maintainers reading `parseAttribute()`.

**Error messages report byte offsets, not character offsets.** `strlen()` counts bytes; `$this->pos` is therefore a byte offset. For UTF-8 content with multi-byte characters, the position in error messages (`"Expected XML name start at position 3, got \\xc3"`) is a byte position, not a codepoint position or line/column. There is no line or column tracking anywhere in the parser.

**`parseName()` accepts `:` but the parser is not namespace-aware.** The colon is included in the name continuation set (`$c === ':'`), so `svg:path` is accepted as an opaque name. This is consistent but creates a mismatch with the XML Namespaces spec and may surprise callers who expect namespace binding.

---

## Bugs & Risks

### Confirmed Bugs

**🔴 `emmetEmit` does not escape attribute values containing `"`.**
In `TransformEngine::emitNode` (line 129), attribute values in bracket notation are emitted as:
```php
$pairs[] = $k . '="' . $v . '"';
```
No escaping of embedded double-quotes. A node with `title='say "hi"'` emits `a[title="say "hi""]`. `EmmetParser::parseAttrList` terminates the value at the first unescaped `"` (it does support `\"` as an escape sequence but `emmetEmit` never uses it), yielding `title='say '` plus a spurious extra attribute. Round-trip of any attribute value containing `"` silently truncates the value.

**🔴 `emmetEmit` does not escape `}` in text content.**
`emitNode` wraps text content as `{$n->text}` with no escaping. `EmmetParser::parseTextLiteral` does not balance braces; it terminates at the first unescaped `}`. A `Node` with `text='hello {world}'` emits `p{hello {world}}`. On re-parse, `parseTextLiteral` reads `hello {world` and stops, losing ` {world}`. Any text containing `}` is silently truncated.

**🔴 `emmetEmit` does not escape `\` before `{` or `}` in text content.**
`parseTextLiteral` treats `\{` and `\}` as escape sequences. `emitNode` emits raw backslashes. A `Node` with `text='a\{b}'` emits `p{a\{b}}` which re-parses as `'a{b'` — the backslash is consumed as an escape prefix and the tail is lost.

**🔴 `emmetEmit` html-mode `#id` and `.class` shortcuts break round-trip when values contain Emmet metacharacters.**
`emitNode` in html mode emits `div#foo.bar` for `id='foo.bar'`. On re-parse, `consumeIdent` reads `foo`, then `parseElement` sees `.` and starts a class token, yielding `id='foo'` and `class='bar'`. Similarly, `id='my>div'` emits `div#my>div` which the parser reads as a descend operator, producing a nested structure. Any `id` or `class` value containing `.`, `#`, `>`, `+`, `^`, `[`, `{`, or `}` produces structurally incorrect Emmet. The emitter must detect metacharacters and fall back to bracket notation `[id="..."]` when the value is not safe.

**🔴 Mixed-content `#text` synthetic nodes produce unparseable Emmet.**
`emitNode` has no special case for `#text` children. `xmlParse('<p>text <b>bold</b> more</p>')` produces `#text` child nodes (per the two-pass fragment logic). `emmetEmit` emits `p>#text{text }+b{bold}+#text{ more}`. `EmmetParser::parseElement` calls `consumeIdent`, which requires an alpha first character; `#` causes `consumeIdent` to return `''`, which triggers `EmmetParseError("Expected tag name")`. Any mixed-content node is completely un-round-trippable through Emmet, with a fatal parse error. `xmlEmit` handles `#text` nodes correctly (lines 19–21 in `TransformEngine`) but `emmetEmit` has no equivalent guard.

**🔴 `expandRepetition()` is unbounded.**
`EmmetParser::expandRepetition` (lines 165–198) allocates `$n` clones of the operand with no cap on `$n`. `div*1000000` causes PHP fatal memory exhaustion. Any user-controlled Emmet string reaching this path is a server-side DoS vector. A guard such as `if ($n > 1000) throw new EmmetParseError(...)` must be added before the clone loop.

### Confirmed Risks

**🟡 No recursion depth limit in `parseElement()`.**
`parseElement()` calls itself recursively for each child element. PHP's call stack will overflow on sufficiently deep input. Testing on this platform survived 20,000 levels; segfault was observed around 50,000. The threshold is higher than the common characterisation of "~5000 levels" but the risk is real for untrusted XML with no depth guard. A `$depth` counter threaded through the recursive call, with a configurable cap (default 256), is the standard mitigation.

**🟡 Duplicate attribute names are silently last-wins.**
Detailed above under "What's Bad." This is both a spec violation and a data-loss risk at the API level.

**🟡 `$mode` is not validated.**
Detailed above. A future mode string (e.g., `'svg'`, `'xhtml'`) will silently behave as XML with no diagnostic.

**🟢 Null bytes silently accepted.**
`ctype_space(chr(0))` is false, so null bytes pass through `parseTextRun()` and `parseAttributeValue()` into `Node::$text` and `Node::$attrs`. Downstream consumers (JSON serialisation, database storage) may not handle null bytes correctly.

**🟢 No input size guard.**
No maximum byte length check. A 500 MB string will be processed in full. Combined with the recursion risk, this makes the parser unsuitable for untrusted input without an external size check.

### False Positives from Prior Analysis

The original analysis flagged two issues that do not hold up:

- **"Entity-in-attribute double-decoding breaks XML round-trip"** — False. `xmlParse` decodes `&amp;` to `&` in attribute values; `xmlEmit` re-encodes `&` to `&amp;` via `htmlspecialchars`. The full `xmlParse → xmlEmit` round-trip is lossless. The Emmet intermediate representation also round-trips correctly because `emmetParse` stores the decoded value and `xmlEmit` re-encodes it on output.

- **"emmetEmit emits unescaped `<` and `>` in text, breaking round-trip"** — False. `emmetEmit` emits `p{<b>}` for text `'<b>'`. `EmmetParser::parseTextLiteral` reads all bytes until `}`, treating `<` and `>` as literal characters, not XML markup. `xmlEmit` then re-escapes them to `&lt;b&gt;`. The full round-trip is lossless for these characters specifically.

The O(n²) string concatenation claim is real code quality but overstated as a performance bug: benchmarks show 1 MB text parses in ~42 ms on this platform.

---

## Missing Features

| Feature | Impact | Notes |
|---|---|---|
| Numeric entity decoding (`&#65;`, `&#x41;`) | 🔴 Data corruption | `&#65;` is stored as the literal string `&#65;` in `Node::$text`. Confirmed broken. Common in auto-generated XML. |
| Boolean/valueless HTML attributes (`disabled`, `checked`) | 🔴 Parse failure | `parseAttribute()` always calls `expect('=')`, so `<input disabled>` throws `"Expected '=' at position N"`. HTML5 universal. |
| Comment skipping (`<!-- -->`) | 🟡 Parse failure | Any document with a comment fails with a confusing error on `<`. |
| XML declaration (`<?xml version="1.0"?>`) | 🟡 Parse failure | Must be manually stripped before passing to parser. |
| DOCTYPE declaration (`<!DOCTYPE html>`) | 🟡 Parse failure | Fails immediately. All HTML5 documents start with this. |
| CDATA sections (`<![CDATA[...]]>`) | 🟡 Parse failure | Needed for inline scripts/styles in XML. |
| Fragment parsing (multiple root elements) | 🟡 Limitation | The trailing-content check rejects `<br/><br/>`. No `parseFragment(): Node[]` method exists. |
| Line/column tracking in errors | 🟢 DX | Only byte offsets reported. Multi-line documents produce useless error positions. |
| Processing instructions (`<?...?>`) | 🟢 Limitation | Rejected with confusing error. |
| Namespace-aware parsing | 🟢 Limitation | Colons are accepted in names but no URI binding occurs. |
| Streaming / incremental parsing | 🟢 Limitation | Full source must be in memory. |

---

## Improvement Ideas

**1. Fix `emmetEmit` text escaping (critical, unblocks round-trip).**
In `TransformEngine::emitNode`, before emitting `{$n->text}`, scan for `}`, `\`, and other DSL metacharacters and apply the escape sequences that `parseTextLiteral` already supports (`\}`, `\\`):
```php
$escaped = strtr($n->text, ['}' => '\\}', '\\' => '\\\\']);
$out .= '{' . $escaped . '}';
```

**2. Fix `emmetEmit` attribute value escaping (critical, unblocks round-trip).**
In `emitNode`, replace raw `$v` in bracket notation with an escaped form. `EmmetParser::parseAttrList` already handles `\"` for double-quoted values:
```php
$pairs[] = $k . '="' . str_replace(['"', '\\'], ['\\"', '\\\\'], $v) . '"';
```

**3. Fix `emmetEmit` html-mode shortcut safety check (critical).**
Before emitting `#$id` or `.$cls`, check whether the value contains any Emmet metacharacter. If so, emit `[id="..."]` / `[class="..."]` in bracket notation instead. A fast check:
```php
private static function isSafeIdent(string $v): bool {
    return $v !== '' && ctype_alpha($v[0]) && !preg_match('/[.#>+^*\[\]{}\\\\"\'()]/', $v);
}
```

**4. Add a special-case for `#text` nodes in `emmetEmit` (critical, unblocks mixed content).**
`xmlEmit` already handles `#text` at line 19. Add the same guard in `emitNode`:
```php
if ($n->tag === '#text') {
    // Emit as bare escaped text — no Emmet wrapper possible for inline text nodes.
    // Callers should not attempt to round-trip mixed-content through Emmet.
    return '{' . strtr($n->text ?? '', ['}' => '\\}', '\\' => '\\\\']) . '}';
}
```
Or explicitly throw/warn — but silently emitting broken Emmet is the current worst outcome.

**5. Cap `expandRepetition()` (critical security fix).**
```php
private const MAX_REPETITION = 1000;
if ($n > self::MAX_REPETITION) {
    throw new EmmetParseError("*N repetition N={$n} exceeds limit of " . self::MAX_REPETITION);
}
```

**6. Validate `$mode` in `XmlParser::__construct`.**
```php
if (!in_array($mode, ['xml', 'html'], true)) {
    throw new \InvalidArgumentException("Unknown mode '$mode'; expected 'xml' or 'html'");
}
```
Or introduce a PHP 8.1 backed enum `ParseMode` and type-hint the constructor.

**7. Add duplicate-attribute detection in `parseElement()`.**
After the attribute loop, before construction:
```php
if (count($attrs) !== $parsedAttrCount) {
    throw new XmlParseError("Duplicate attribute name at position {$this->pos}");
}
```
Track `$parsedAttrCount` as a counter incremented for each `parseAttribute()` call.

**8. Replace character-by-character concatenation with `strcspn`/`substr`.**
In `parseTextRun()`:
```php
$len = strcspn($this->src, '<', $this->pos);
$raw = substr($this->src, $this->pos, $len);
$this->pos += $len;
return $this->decodeEntities($raw);
```
Apply the same pattern to `parseAttributeValue()`, scanning for the closing quote byte.

**9. Add a recursion depth guard.**
```php
public function __construct(
    private readonly string $src,
    private readonly string $mode = 'xml',
    private readonly int $maxDepth = 256,
) { ... }

private function parseElement(int $depth = 0): Node {
    if ($depth >= $this->maxDepth) {
        throw new XmlParseError("Nesting depth exceeds limit of {$this->maxDepth}");
    }
    // ... recursive calls become: $this->parseElement($depth + 1)
}
```

**10. Add numeric entity decoding in `decodeEntities()`.**
```php
$result = preg_replace_callback(
    '/&#([0-9]+);|&#x([0-9a-fA-F]+);/',
    static function (array $m): string {
        $cp = isset($m[2]) && $m[2] !== '' ? hexdec($m[2]) : (int) $m[1];
        return mb_chr($cp, 'UTF-8') ?? '?';
    },
    $raw
);
return strtr($result, ['&amp;' => '&', '&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&apos;' => "'"]);
```

**11. Remove or give real purpose to `buildNode()`.**
The current body is two lines that could be `return new Node($tag, $attrs, $children);` inlined at each call site. If the method is kept, add the duplicate-attribute check (item 7) here instead.

**12. Add line/column tracking.**
Maintain `$line` and `$col` as instance state, updated in `skipWhitespace()` and the character-consuming helpers. Include both in all `XmlParseError` messages.

---

## Test Coverage

The nine `XmlParseTest` cases (C1–C9) cover the primary happy paths and the two primary error paths (unclosed tag, mismatched tags). The six `RoundTripTest` cases (G1–G6) cover the most important system-level contracts for clean, well-formed input.

**Confirmed untested gaps:**

| Gap | Confirmed behaviour |
|---|---|
| Numeric entity decoding | Broken — `&#65;` stored as literal string |
| Duplicate attribute names | Silently last-wins — no error |
| Unrecognised `$mode` value | Wrong error — `"Unclosed tag"` not `"Unknown mode"` |
| Boolean/valueless HTML attributes | Throws `"Expected '='"` |
| Emmet round-trip with `}` in text | Silently truncated |
| Emmet round-trip with `"` in attribute value | Silently truncated |
| Emmet round-trip with `\` before `{`/`}` in text | Data corruption |
| Emmet round-trip of mixed-content (has `#text` children) | Fatal `EmmetParseError` |
| Emmet html-mode with metacharacters in `id`/`class` | Structural corruption |
| `*N` with large N | OOM fatal |

The `XmlEmitTest` D7/D8 round-trip tests pass but do not exercise entity-encoded content or special characters in attribute values, so the broken Emmet paths go undetected.

There are no fuzz tests, property-based tests, or mutation tests. The parser has no depth or size limits; a single adversarial payload can OOM or overflow the call stack in a server context.

---

## Verdict

The parser core (`XmlParser`) is well-structured for its stated scope: immutable output, clean error messages, correct two-pass fragment analysis, and safe entity decoding. For trusted, well-formed XML/HTML input it is solid. The issues in the parser itself — unvalidated `$mode`, silent duplicate attributes, no recursion cap — are fixable in an afternoon.

The critical problem is the `emmetEmit` boundary. Five confirmed bugs make the `emmetEmit → emmetParse` round-trip unreliable for any non-trivial content: text with `}`, attribute values with `"`, mixed-content nodes with `#text` children, and html-mode `id`/`class` values containing Emmet metacharacters all produce either truncated data or fatal parse errors on re-parse. Additionally, `expandRepetition()` has no bound, making any user-controlled Emmet string a DoS vector. These are not edge cases — `}` in text content, `"` in attribute values, and mixed content are ordinary real-world XML patterns.

**Rating: needs-work.** The parser half is acceptable for clean input; the emitter round-trip half has correctness and security bugs that must be fixed before this component handles untrusted or production-quality XML.
