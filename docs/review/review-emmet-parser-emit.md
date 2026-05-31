# Review: EmmetParser + emmetEmit Layer

**Files:** `src/EmmetParser.php`, `src/TransformEngine.php` (`emmetEmit`, `emitNode`, `emitChildren`)
**Node value object:** `src/Node.php`, `src/NodeEquality.php`
**Test files:** `tests/EmmetParseTest.php`, `tests/EmmetEmitTest.php`, `tests/RoundTripTest.php`

---

## Overview

This component has two responsibilities that are the inverse of each other:

1. **Parser** (`EmmetParser::parse` / `parseExpr`): converts an Emmet abbreviation string into a `Node` tree. Uses an iterative stack machine for the main expression loop and recursive calls only for parenthesised groups.

2. **Emitter** (`TransformEngine::emmetEmit` / `emitNode` / `emitChildren`): serialises a `Node` tree back to an Emmet abbreviation string. Applies run-length encoding (RLE) over the child list to collapse identical siblings into `*N` form.

The pair is the critical seam in the xml→emmet→xml pipeline. Correctness of the emitter is a strict prerequisite for that pipeline — any lossiness here manifests as silent data corruption downstream.

---

## What Exists

### Operators supported by the parser

| Operator | Symbol | Implementation |
|---|---|---|
| Child | `>` | Push new level onto `$stack` |
| Sibling | `+` | Stay at current level, loop |
| Climb-up | `^` | Pop level(s) and unwind |
| Repetition | `*N` | `expandRepetition()` with `cloneWithIndex()` |
| Group | `(...)` | Recursive `parseExpr()` call |
| ID shortcut | `#ident` | `consumeIdent()` after `#` |
| Class shortcut | `.ident` | `consumeIdent()` after `.` |
| Attribute list | `[k=v ...]` | `parseAttrList()` |
| Text literal | `{...}` | `parseTextLiteral()` |
| `$` placeholder | inside `{...}` and `[v]` | `cloneWithIndex()` via `str_replace` |

### Emitter modes

| Mode | Behaviour |
|---|---|
| `html` (default) | `#id`, `.class` shortcuts; remaining attrs as `[k="v"]` |
| `xml` | All attrs as `[k="v"]`; no shortcuts |

---

## What's Good

- **Bounded recursion.** `parseExpr()` is iterative for the main expression loop. Recursion only occurs for `(group)` operands. A deeply nested flat chain (e.g., `a>b>c>d...`) does not grow the PHP call stack.

- **Structurally independent repetitions.** `cloneWithIndex()` performs a full deep clone of the subtree for each repetition index. There is no aliasing between expanded siblings; mutating one clone in a later transform step cannot affect another.

- **Correct `isMultiGroup` flattening.** In `expandRepetition`, when the operand is a `_root` synthetic node (the result of a multi-sibling group), each repetition contributes the children flat rather than wrapped. `(li+a)*3` correctly expands to `li,a,li,a,li,a`. This matches the Emmet spec.

- **Escaped quotes in attribute values.** `parseAttrList` handles `\"` and `\'` via the backslash-escape branch; `a[title="say \"hi\""]` parses correctly with `title = say "hi"`.

- **Escaped braces in text literals.** `parseTextLiteral` handles `\{` and `\}` and throws a descriptive `EmmetParseError` for unclosed `{`, including the position offset.

- **Case-insensitive void element lookup.** `HtmlVoidElements::contains()` calls `strtolower()` before the lookup, so `BR`, `Img`, etc. are all recognised.

- **XML attribute escaping.** `emitAttrs` in `xmlEmitNode` uses `htmlspecialchars(ENT_XML1 | ENT_COMPAT, 'UTF-8')`, which escapes `<`, `>`, `&`, and `"`. Attribute values round-trip safely through the XML serialiser.

- **`#text` handling in `xmlEmit`.** `xmlEmitNode` explicitly checks `$n->tag === '#text'` and emits `htmlspecialchars`-escaped text content only, without wrapping tags.

- **Order-insensitive `NodeEquality`.** The comparator calls `ksort()` on both attribute maps before comparing, so attribute insertion order does not affect equality checks. This makes RLE collapsing in `emitChildren` robust to the order in which attributes were added.

- **`EmmetParseError` extends `RuntimeException`.** Callers can catch parse failures without importing a custom exception hierarchy.

---

## What's Bad

### 🔴 emmetEmit: `#text` synthetic nodes produce invalid output

`emitNode` handles `_root` as a special case but has no corresponding branch for `#text`. A `#text` node (produced by `xmlParse` for text runs adjacent to element children) is treated as a regular element, emitting `#text{Hello }`. When that string is fed back to `emmetParse`, the `#` is interpreted as an ID qualifier at position 0, triggering `EmmetParseError: Expected tag name at position 0`. Any XML with mixed content (e.g., `<p>Hello <em>world</em></p>`) crashes the round-trip.

### 🔴 emmetEmit: `}` in node text is not escaped

`emitNode` line 135 writes `'{' . $n->text . '}'` with no preprocessing. A node whose text contains `}` — for example, text `a}b` — emits `span{a}b}`. `parseTextLiteral` stops at the first unescaped `}`, reading only `a` and silently dropping `b}`. The fix is to escape `}` as `\}` (and `\` as `\\`) before wrapping. Note: `{` alone is not dangerous here — `parseTextLiteral` reads until the first `}`, so a bare `{` inside text is preserved verbatim.

### 🔴 emmetEmit: `$` in text content is not escaped

`cloneWithIndex` uses `str_replace('$', ...)` on text and attribute values during `*N` expansion. Because `emmetEmit` writes raw `$n->text` into braces without escaping `$`, a node with text `price: $5` emits `span{price: $5}`. If that abbreviation is later used in a `*N` context, the `$` is treated as the repetition index placeholder and substituted — producing `price: 15`, `price: 25`, etc. This is a confirmed data-corruption bug. Demonstrated: `xmlParse('<ul><li>price: $5</li><li>price: $5</li></ul>') → emmetEmit → emmetParse → xmlEmit` produces `<ul><li>price: 15</li><li>price: 25</li></ul>`.

### 🔴 emitChildren: depth-bearing siblings are not wrapped in parentheses

When `emitChildren` joins multiple runs with `+`, it does not parenthesise subtrees whose emitted form contains `>`. The result is an abbreviation that reparses into a completely different tree. Example: `div>(a>b)+(c>d)` emits `div>a>b+c>d`, which reparses as `div(a(b, c(d)))`. The scope of this bug is broader than the `_root` case — it fires on any node with multiple children where at least one child has its own children, including trees produced directly by `xmlParse`. The fix is: when there are multiple runs, wrap any run whose emitted string contains `>` in `(...)` before joining. A `str_contains($subtree, '>')` check in `emitChildren` suffices.

### 🔴 emmetEmit: attribute values containing `"` are emitted unescaped

In `emitNode`, both the xml-mode branch (line 104) and the html-mode extras branch (line 129) write `$k . '="' . $v . '"'` with no escaping on `$v`. A value decoded from `&quot;` in XML is entirely legal and contains a literal `"`. The emitted `[title="say "hi""]` causes `parseAttrList` to misparse: it reads the closing `"` after `say ` as the end of the value, then interprets `hi` as a second valueless attribute. The fix is to escape `"` as `\"` before inserting into the `[k="v"]` form. (`parseAttrList` already handles `\"` on input.)

### 🔴 xmlEmit: `_root` synthetic nodes produce invalid XML

`xmlEmitNode` checks for `#text` but not `_root`. When the top-level node is `_root` (produced by `emmetParse('h1+p')`) or a `_root` appears as a child (from `emmetParse('div>(a+b)')`), `xmlEmitNode` emits a literal `<_root>...</_root>` wrapper, which is invalid XML. The fix is to add a `_root` branch in `xmlEmitNode` that concatenates children without a wrapper element, mirroring the `emitNode` handling in the emmet emitter.

### 🔴 No repetition upper bound — DoS via OOM

`expandRepetition` validates `N >= 1` but sets no maximum. `li*9999999` allocates approximately ten million `Node` objects eagerly before the function returns. A single unauthenticated HTTP request containing a large `*N` value can exhaust server memory and crash the PHP process. A hard cap (e.g., `N > 1000` throws `EmmetParseError`) is required before this endpoint is exposed publicly.

### 🟡 Trailing and mid-expression unrecognised characters are silently discarded

`EmmetParser::parse()` calls `parseExpr()` then `siblingsToNode()` with no check that `$this->pos === $this->len` after parsing. The `parseExpr` loop's `else` branch breaks on any unrecognised character. The consequences are:
- `div.foo GARBAGE` parses as `div.foo` with no error.
- `li.item${item $}*3` parses as `li.item` — the `$` terminates `consumeIdent`'s qualifier loop at `item`, and the `${...}*3` tail is silently dropped.

A strict mode that throws `EmmetParseError` on any non-whitespace, non-`)`, non-EOF character after a complete expression would catch both cases.

### 🟡 emitChildren: double-paren emission for `_root` runs with `runLen > 1`

When a child is a `_root` node and there are multiple runs and `runLen > 1`, two layers of parens are applied cumulatively. `emitNode(_root, needsParens=true)` already returns `(a+b)`, and then the `$child->children !== []` guard in `emitChildren` adds another pair, producing `((a+b))*2`. Reparsing `((a+b))` flattens via `isMultiGroup` in `expandRepetition` to `[a, b]`, so the structure is not corrupted in the re-parse, but the double-paren form is an emitter defect. The `needsParensIfSiblings` path and the `runLen > 1` path are independent conditions applied cumulatively rather than exclusively.

---

## Bugs & Risks

| # | Severity | Status | Description |
|---|---|---|---|
| 1 | 🔴 Critical | Confirmed | `$` in node text emitted raw; roundtrip with `*N` corrupts numeric content |
| 2 | 🔴 Critical | Confirmed | `emitNode` emits `#text{...}` for `#text` synthetic nodes; causes `EmmetParseError` on reparse |
| 3 | 🔴 Critical | Confirmed | `emitChildren` omits parens around depth-bearing siblings; re-parse tree is structurally wrong |
| 4 | 🔴 Critical | Confirmed | Attribute values with `"` emitted unescaped into `[k="v"]`; `parseAttrList` misparses |
| 5 | 🔴 Critical | Confirmed | `}` in node text emitted unescaped; `parseTextLiteral` silently truncates text at first `}` |
| 6 | 🔴 Critical | Confirmed | `xmlEmitNode` has no `_root` branch; emits literal `<_root>` tags — invalid XML |
| 7 | 🔴 Critical | Confirmed (DoS) | No `*N` repetition cap; large N causes fatal OOM; one HTTP request can crash the process |
| 8 | 🟡 Moderate | Confirmed | Trailing/mid-expression garbage silently ignored; `li.item${item $}*3` parses as `li.item` |
| 9 | 🟡 Moderate | Confirmed | Double-paren emission `((a+b))*2` for `_root` runs with multiple siblings and `runLen > 1` |

**Bug 3 is broader than it appears:** the parens-omission in `emitChildren` affects any node (not just `_root` trees) with multiple children where at least one child has its own children. `xmlParse('<div><a><b/></a><c><d/></c></div>') → emmetEmit` produces `div>a>b+c>d`, which reparses as `<div><a><b/><c><d/></c></a></div>` — a completely wrong structure. The protection "xmlParse never returns `_root`" does not prevent this from firing.

---

## Missing Features

| Feature | Description |
|---|---|
| Implicit tag names | `.foo` should expand to `div.foo`; `#id` to `div#id`. Both currently throw `EmmetParseError: Expected tag name at position 0`. |
| Zero-padded `$$` counter | `$$` should produce `01, 02, ...`; `$$$` should produce `001, 002, ...`. Currently `$$` expands `$` twice, yielding `11, 22, ...` — numerically doubled rather than padded. |
| `$@N` start-offset modifier | `li{$@5}*3` should produce items 5, 6, 7. Currently `@5` is emitted literally: `1@5`, `2@5`, `3@5`. |
| `$@-` descending counter | `li{$@-}*3` should produce 3, 2, 1. Not implemented. |
| Context-sensitive implicit tags | Inside `ul`/`ol`, `.item` should default to `li.item`; inside `table`, `.row` to `tr.row`. |
| Namespace-prefixed tags | `xsl:for-each` — `consumeIdent` stops at `:`. Compounds with the trailing-garbage bug: `xsl` is parsed as the tag and `:for-each` is silently dropped. |
| Empty id/class validation | `div#` and `div.` silently produce `id=""` and `class=""` with no error. `consumeIdent` returns `''` for a non-alpha first character; that empty string is stored without validation. |

---

## Improvement Ideas

### Fix emitNode text escaping (addresses bugs 1, 5)

In `emitNode` at the `$n->text` branch, preprocess the text value before wrapping in braces:

```php
$safeText = str_replace(['\\', '}', '{', '$'], ['\\\\', '\\}', '\\{', '\\$'], $n->text);
$out .= '{' . $safeText . '}';
```

`parseTextLiteral` already handles `\{` and `\}` on input. The `\$` escape must also be recognised by `cloneWithIndex` to avoid substituting escaped dollar signs during `*N` expansion.

### Fix emitNode attribute value escaping (addresses bug 4)

In both the xml-mode branch and the html-mode extras branch of `emitNode`, escape `"` as `\"` before inserting into the `[k="v"]` string:

```php
$pairs[] = $k . '="' . str_replace('"', '\\"', $v) . '"';
```

`parseAttrList` already handles `\"` on input (line 363 of `EmmetParser.php`).

### Fix emitChildren to parenthesise depth-bearing siblings (addresses bug 3)

After computing `$subtree = self::emitNode($child, $mode, $needsParens)`, add a check before the `runLen > 1` branch:

```php
$multipleRuns = count($runs) > 1;
// ...
$needsParens = ($child->tag === '_root' && $multipleRuns)
             || ($multipleRuns && str_contains($subtree, '>'));
```

More precisely: replace the existing `$needsParens` logic with a post-emit wrap: if `$multipleRuns && str_contains($subtree, '>')`, wrap `$subtree` in `(...)`. This covers both `_root` children and non-`_root` children with their own depth.

### Fix xmlEmitNode to handle `_root` (addresses bug 6)

Add a branch analogous to the existing `#text` branch:

```php
if ($n->tag === '_root') {
    $out = '';
    foreach ($n->children as $child) {
        $out .= self::xmlEmitNode($child, $mode);
    }
    return $out;
}
```

### Add emitNode handling for `#text` (addresses bug 2)

In `emitNode`, add a branch before the tag decorator logic:

```php
if ($n->tag === '#text') {
    // Emmet has no inline text-node syntax; emit as a comment or skip.
    // For lossless round-trips, the caller must handle mixed content upstream.
    return '';
}
```

The correct long-term fix is to document that mixed-content XML (text runs adjacent to element children) has no valid Emmet representation. The emitter should either skip `#text` nodes silently or throw a descriptive error, not emit invalid syntax.

### Add repetition cap (addresses bug 7)

At the top of `expandRepetition`, after parsing `$n`:

```php
const MAX_REPETITIONS = 1000;
if ($n > self::MAX_REPETITIONS) {
    throw new EmmetParseError(
        "*N repetition limit is " . self::MAX_REPETITIONS . ", got {$n}"
    );
}
```

### Add trailing-garbage detection (addresses bug 8)

After `parseExpr()` returns in `EmmetParser::parse()`:

```php
if ($this->pos < $this->len) {
    throw new EmmetParseError(
        "Unexpected character '{$this->input[$this->pos]}' at position {$this->pos}"
    );
}
```

This would catch mid-expression truncations like `li.item${item $}*3 → li.item`.

### Fix double-paren emission (addresses bug 9)

In `emitChildren`, the `needsParensIfSiblings` flag and the `runLen > 1` wrap should be treated as mutually exclusive cases for `_root` nodes. When `$needsParens` is already true and the subtree already has outer parens from `emitNode`, the `$child->children !== []` guard should not add a second pair:

```php
if ($runLen > 1) {
    if (!$needsParens && $child->children !== []) {
        $subtree = '(' . $subtree . ')';
    }
    $subtree .= '*' . $runLen;
}
```

---

## Test Coverage

### What is covered

- **EmmetParseTest (A1–A20):** All six operators, basic attribute syntaxes (`#id`, `.class`, `[k=v]`, valueless, escaped quotes), repetition with `$` placeholder, group parsing, three error paths (unclosed `[`, unclosed `{`, unclosed `(`, empty tag, `*0`). Happy-path coverage is thorough.
- **EmmetEmitTest (B1–B12):** Bare node, class, id+class+attr, text, child, siblings, child-then-siblings, RLE collapse, no-collapse, xml-mode, xml-mode text, one round-trip (`div>h1.title{Hello}+ul>li*3`).
- **RoundTripTest (G1–G6):** Six xml→emmet→xml byte-exact assertions covering basic nesting, repetition collapsing, HTML void elements, attribute reordering, differing-text siblings, and nested group RLE.

### Critical gaps

| Gap | Consequence |
|---|---|
| No emmet→emmet round-trip for `^` trees | `a>b>c^^d` parses correctly (A12 asserts tree shape) but `emmetEmit` of that tree is never tested; the structural-loss bug in `emitChildren` is invisible to the suite |
| No mixed-content XML test | `xmlParse('<p>Hello <em>world</em></p>') → emmetEmit` crashes with `EmmetParseError`; bug 2 is undetected |
| No attr value with `"` through emitter | `xmlParse('<a title="say &quot;hi&quot;"/>') → emmetEmit → emmetParse` misparsing; bug 4 undetected |
| No `$` roundtrip corruption test | `li{price: $5}*2` producing `price: 15` / `price: 25`; bug 1 undetected |
| No DoS / input-size test | `li*9999999` is not exercised; no guard exists to catch |
| No trailing-garbage negative test | `div foo` silently parsing to `div`; bug 8 undetected |
| No `xmlEmit` of `_root` node | Neither top-level nor child `_root` is tested through `xmlEmit`; bug 6 undetected |
| No `_root`-child tree emitChildren test | `div>(a>b)+(c>d)` emitting `div>a>b+c>d`; bug 3 undetected |

The test suite is well-structured and readable. The A/B/G labelling convention makes it easy to extend. However, every one of the six confirmed critical bugs was caught only through manual probing, not by the automated suite. The absence of adversarial and edge-case tests gives a false sense of coverage completeness.

---

## Verdict

The parser core (`parseExpr`, `parseAttrList`, `parseTextLiteral`, `expandRepetition`) is well-implemented: the iterative stack approach, correct `isMultiGroup` flattening, full deep-clone on repetition, and escaped-quote handling are all sound. The problem is concentrated in the emitter and the xmlEmit layer, which contain six confirmed critical bugs: unescaped `}` and `$` in text content, unescaped `"` in attribute values, missing parentheses for depth-bearing siblings, absent `#text` handling, and absent `_root` handling in `xmlEmit`. One of these bugs (the missing repetition cap) is a security issue — a single HTTP request with a large `*N` can OOM the server. Several bugs interact: the sibling-depth parens bug (bug 3) and the `_root`/xmlEmit bugs mean the xml→emmet→xml pipeline is unreliable for any non-trivial tree, which is the component's primary use case. Before this component can be relied on for production data, all six critical bugs must be fixed, the repetition cap must be added, and adversarial test cases must be added for each fixed path.

**Rating: needs-work**
