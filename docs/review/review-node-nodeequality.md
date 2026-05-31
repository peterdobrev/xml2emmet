# Review: Node + NodeEquality

**Files:** `src/Node.php`, `src/NodeEquality.php`
**Related:** `src/TransformEngine.php` (emmetEmit), `src/RulesEngine.php`, `src/ClickOps.php`
**Reviewer date:** 2026-05-31

---

## Overview

`Node` is the central immutable value-object for the entire xml2emmet pipeline. Every parse, emit, rule application, and click-operation works on `Node` trees. `NodeEquality` is a companion static class providing deep structural equality that ignores rule-provenance bookkeeping. Because nearly every other class in the codebase constructs or consumes `Node` objects, flaws here propagate everywhere.

The core design is sound: PHP 8.1+ readonly promoted properties, a fluent `with*` mutation family, and a clean companion equality class. Two confirmed bugs in the emission layer (text and attribute escaping), combined with the absence of `declare(strict_types=1)` in all core files and unvalidated array element types, push the verdict below "acceptable."

---

## What Exists

| Property | Type | Notes |
|---|---|---|
| `$tag` | `readonly string` | Tag name, no validation |
| `$attrs` | `readonly array` (`array<string,string>`) | Insertion-order preserved; generic annotation only |
| `$children` | `readonly array` (`Node[]`) | Generic annotation only; no runtime enforcement |
| `$text` | `readonly ?string` | `null` = absent, `''` = present-but-empty |
| `$appliedRules` | `readonly array` (`string[]`) | Rule-ID dedup set; backed by plain array |

| Mutator | Returns | Notes |
|---|---|---|
| `withChild(Node $c)` | `self` | Always appends; no insert position |
| `withAttr(string $k, string $v)` | `self` | Silently overwrites existing key |
| `withText(?string $t)` | `self` | Accepts `null` to clear |
| `withAppliedRule(string $id)` | `self` | Returns `$this` on dedup (no allocation) |

`NodeEquality::equals(Node $a, Node $b): bool` — deep structural equality; attribute-order independent via `ksort`; child-order sensitive; ignores `appliedRules` on both sides.

---

## What's Good

**Immutability is correctly enforced.** Every `with*` mutator returns a new instance; the originals are never modified. PHP's copy-on-write for arrays means `[...$this->children, $c]` only allocates on write, so memory pressure is proportional to mutation depth, not tree breadth.

**withAppliedRule is allocation-frugal.** The `in_array($ruleId, $this->appliedRules, true)` dedup guard uses strict comparison and returns `$this` on a no-op, so the rule-application hot path skips the constructor entirely when a rule has already been applied. `RulesEngine::walk` similarly short-circuits with `if ($newChildren === $node->children) return $node` — no allocation when nothing changed.

**null/empty-string distinction is preserved throughout.** `withText(?string $t)` accepts `null`, `xmlEmitNode` emits a text body only when `$n->text !== null`, and `NodeEquality::equals` uses `!==` for the text comparison. There is no conflation of "absent" with "empty."

**The `final` modifier is correct on both classes.** Subclassing `Node` could break the immutability contract (a subclass could add mutable fields); subclassing `NodeEquality` could silently break equality semantics. `final` forecloses both.

**PHPDoc generic annotations are accurate.** `@param array<string,string> $attrs`, `@param Node[] $children`, and `@param string[] $appliedRules` give static analysis tools (PHPStan, Psalm) enough information to enforce element types at the call site, even though PHP itself does not enforce them at runtime.

**Strict `in_array` throughout.** All `in_array` calls in `withAppliedRule`, `RulesEngine::walk`, and `RulesEngine::tagSubtree` pass `true` as the third argument. This prevents accidental loose matches between `'0'` and `false`, or between `''` and `null`.

---

## What's Bad

### 🔴 Missing `declare(strict_types=1)` in all core files

`Node.php`, `NodeEquality.php`, `TransformEngine.php`, `RulesEngine.php`, `EmmetParser.php`, `XmlParser.php`, `Rule.php`, and `ClickOps.php` all omit the declaration. Every file under `src/Http/`, `src/Db/`, and `src/Stats/` has it. The inconsistency is stark and consequential: without `strict_types=1`, PHP silently coerces a caller passing an `int` as `$tag` to the string `'0'` rather than throwing a `TypeError`. Bugs caused by wrong-type arguments become invisible until something downstream behaves strangely. This is the single highest-ROI fix in the codebase.

### 🔴 No runtime element-type enforcement on constructor arrays

`$children`, `$attrs`, and `$appliedRules` are typed as plain `array`. PHP does not enforce generic annotations at runtime. `new Node('div', [], ['oops'])` is silently accepted: `$node->children[0]` stores the string `'oops'`, and the corrupt state is publicly observable via the `readonly` property. The PHP Warning ("Attempt to read property 'tag' on string") only fires when something later iterates children — far from the site of the mistake.

### 🟡 `withAttr` silently overwrites an existing key

`->withAttr('class', 'foo')->withAttr('class', 'bar')` produces `['class' => 'bar']`, discarding `'foo'`. The array-spread `[...$this->attrs, $k => $v]` naturally overwrites, and this is relied upon by the Emmet parser's canonical ordering pass, but there is no docblock on `Node::withAttr` documenting this semantics. Callers who need class-token accumulation (e.g. merging classes from two rules) receive no guidance and no alternative `addAttr` or `mergeAttr` method.

### 🟡 `NodeEquality::ksort` uses default `SORT_REGULAR` flag

`ksort($attrsA)` without a flags argument uses `SORT_REGULAR`, which converts numeric-looking string keys (e.g. `'10'`, `'9'`) to integers before sorting, producing a different order than strict lexicographic comparison. In practice, XML attribute names are never purely numeric, and the equality result is still correct (both sides undergo the same transformation before `===`). However, the code's correctness depends on an undocumented assumption about key shapes, and `SORT_STRING` would make the intent explicit and future-proof.

### 🟡 `appliedRules` is semantically a set but stored as a plain array

`in_array` is O(n) in the number of rules. `RulesEngine::walk` calls `in_array($rule->id, $node->appliedRules, true)` for every node in the tree, and `RulesEngine::tagSubtree` does the same. For a 100-node tree with 20 rules that is 2,000 linear scans per `applyRules` invocation. Representing the set as `array<string, true>` and replacing `in_array` with `isset` would be asymptotically and practically faster. This is a breaking change to the shape of the `readonly` property, but all existing operations on `appliedRules` are membership tests and union — semantically equivalent.

### 🟢 No tag validation in the constructor

An empty string (`''`), a string containing whitespace, or a string containing XML-illegal characters are all silently accepted by `new Node(...)`. `TransformEngine::xmlEmitNode` interpolates `$n->tag` directly into XML output as `'<' . $n->tag . $attrs`. A tag like `'di>v'` or `'<script'` produces malformed XML without any error at the construction site.

### 🟢 `NodeEquality` has no private constructor

`NodeEquality` is a static utility class, but `new NodeEquality()` is valid PHP and produces a useless instance. A `private function __construct() {}` would communicate the intent and is consistent with how static-only utility classes should be written. The same applies to `HtmlVoidElements`, `Stats`, and `TransformEngine`.

---

## Bugs & Risks

### 🔴 CONFIRMED BUG — `emmetEmit` does not escape curly braces in text content

**Location:** `src/TransformEngine.php` line 135

```php
// Current (broken):
if ($n->text !== null) {
    $out .= '{' . $n->text . '}';
}
```

A `Node` with `text = 'a{b}'` emits `p{a{b}}`. When that string is re-parsed by `EmmetParser`, the inner `}` terminates the text literal early, so the round-trip produces text `'a{b'` — the closing brace and everything after is silently swallowed.

`EmmetParser::parseTextLiteral` already handles `\{` and `\}` as escape sequences. The fix is entirely in the emitter:

```php
// Fixed:
if ($n->text !== null) {
    $out .= '{' . addcslashes($n->text, '{}') . '}';
}
```

No test in `EmmetEmitTest` or `RoundTripTest` covers text content containing Emmet syntax characters.

### 🔴 CONFIRMED BUG — `emmetEmit` does not escape double-quote characters in attribute values

**Location:** `src/TransformEngine.php` lines 104 (xml mode) and 129 (html mode extras)

```php
// Current (broken), both blocks:
$pairs[] = $k . '="' . $v . '"';
```

A node with `attrs['href'] = 'x"y'` emits `a[href="x"y"]`. The parser tokenises this as `href='x'`, discarding everything after the unescaped quote. Additionally, a value of `'a\"b'` (backslash then double-quote) must escape the backslash first; otherwise `'\\"` in the output is consumed as an escaped quote on re-parse, losing the backslash.

The correct fix escapes `\` before `"`:

```php
// Fixed:
$escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
$pairs[] = $k . '="' . $escaped . '"';
```

Neither bug has a regression test.

### 🟡 LATENT RISK — Unbounded recursion on deeply nested trees

`NodeEquality::equals`, `RulesEngine::walk`, `RulesEngine::tagSubtree`, `RulesEngine::substitute`, `ClickOps::replaceAt`, `ClickOps::unwrapAt`, `ClickOps::insertAt`, and `Stats::walk` are all implemented with unbounded recursion on tree depth. Without xdebug, PHP can typically recurse several thousand frames before hitting the OS stack limit, but with xdebug active (common in development) the default `xdebug.max_nesting_level` of 256 would cause a fatal error on a tree as shallow as 128 levels. An explicit depth guard with a descriptive domain exception at, say, depth > 500 would make failures observable and recoverable.

### 🟡 RISK — Replacement template literal-attr overwrite is undocumented

When a `Rule` replacement template carries a hardcoded attribute value (not bound to a placeholder), `RulesEngine::substitute` copies it verbatim into the output. If the matched candidate already has a value for that key, it is silently overwritten by the template's hardcoded value. This is not a code bug — it is intentional substitution behaviour — but it is undocumented on the `Rule` API and is a footgun for rule authors.

### 🟢 RISK — `withChild` always appends; structural mutations are O(n) in children count

`Node` exposes no `insertChildAt` or `replaceChild` operation. All structural mutations in `ClickOps` rebuild `$newChildren` arrays from scratch. For a `ul` with 10,000 `li` children, every delete, insert, or reorder creates a new array of 10,000 elements. This is correct but creates significant GC pressure at scale.

---

## Missing Features

### 🟡 No `withTag(string $t): self`

Every operation that renames a tag constructs `new Node($newTag, $node->attrs, $node->children, $node->text, $node->appliedRules)` directly. This occurs in `ClickOps::rename` (line 54) and `RulesEngine::substitute` (line 126). A `withTag` mutator would be consistent with the `with*` family, eliminate the risk of accidentally omitting `appliedRules` in the constructor call, and make `ClickOps::rename` a one-liner.

### 🟡 No `withoutAttr(string $k): self`

There is no way to remove an attribute through the fluent API. Any caller needing attribute removal must construct a `new Node(...)` directly with a filtered `$attrs` array. Attribute-cleanup rules would require this.

### 🟡 No `withChildren(Node[] $children): self`

Replacing all children at once requires either repeated `withChild` calls (O(n²) to build an n-child list) or a direct `new Node(...)` call. `ClickOps` works around this by always building `$newChildren` inline and constructing `new Node(...)` directly — bypassing the fluent API for every structural operation.

### 🟢 No `equals(Node $other): bool` instance method on `Node`

`NodeEquality` is a reasonable separation, but it is not discoverable. A developer looking at `Node` has no indication that a companion equality class exists. An `equals` instance method delegating to `NodeEquality::equals` internally would be more ergonomic and would not break the companion-class architecture.

### 🟢 No `withoutAppliedRules(): self`

The only way to strip `appliedRules` is `new Node($n->tag, $n->attrs, $n->children, $n->text, [])`. A `withoutAppliedRules()` method would make the intent explicit and prevent callers from accidentally omitting the fifth constructor argument.

### 🟢 No stable serialization contract on `Node` itself

`src/Http/NodeJson.php` handles wire serialization and deliberately omits `appliedRules` from output (confirmed by `NodeJsonTest::testToArrayExcludesAppliedRules`). This is architecturally clean. However, `Node` has no documented serialization format of its own, so if `NodeJson`'s wire shape ever diverges from `Node`'s structure, the mismatch is caught only at runtime.

---

## Improvement Ideas

1. **Add `declare(strict_types=1)` to all eight core files** (`Node.php`, `NodeEquality.php`, `TransformEngine.php`, `RulesEngine.php`, `EmmetParser.php`, `XmlParser.php`, `Rule.php`, `ClickOps.php`). This converts silent scalar coercions into `TypeError`s, making caller bugs immediately visible. It is the single highest-ROI change in the codebase.

2. **Fix the two confirmed emmetEmit escaping bugs** (curly braces in text, double-quote and backslash in attribute values) with the one-liner fixes shown above. Both bugs also need regression tests added to `EmmetEmitTest` and `RoundTripTest` with round-trip assertions on inputs containing the offending characters.

3. **Change `appliedRules` storage from `string[]` to `array<string, true>`** (a hash map). Update `withAppliedRule` to use `isset` instead of `in_array`, and update the guards in `RulesEngine::walk` and `RulesEngine::tagSubtree` to use `array_key_exists`. This is a breaking change to the `readonly` property's public shape but semantically equivalent for all existing consumers.

4. **Change `NodeEquality::ksort` calls to `ksort($arr, SORT_STRING)`** to make sort behaviour explicit and independent of future PHP changes to `SORT_REGULAR` semantics. One-line change.

5. **Add `withTag(string $t): self`, `withoutAttr(string $k): self`, and `withChildren(array $children): self`** to complete the fluent builder API. This would eliminate all direct `new Node(...)` construction outside the class, making `Node` the single authoritative place where the five-argument constructor is called.

6. **Add a depth guard to all recursive methods** (or extract a shared `TreeWalker` with configurable max depth). A simple approach: thread a `$depth` counter defaulting to `0`, throw a `\DomainException` at `$depth > 500`. This prevents fatal stack overflows on adversarial or pathologically nested inputs.

7. **Add a private constructor to `NodeEquality`** (and `HtmlVoidElements`, `Stats`, `TransformEngine`) to communicate that instantiation is meaningless: `private function __construct() {}`.

8. **Add a tag validation guard in `Node.__construct`** — at minimum reject empty strings and strings containing `<`, `>`, `&`, or whitespace. This prevents `xmlEmit` from producing structurally invalid XML without any diagnostic.

---

## Test Coverage

**Breadth is adequate for the happy path.** `NodeTest` covers construction defaults, `withChild` immutability, and `withAttr` insertion order. `NodeAssertTest` covers attribute-order independence and child-order sensitivity. All six `RoundTripTest` cases (G1–G6) pass. `ApplyRulesTest` covers eight scenarios including the dedup guard (E5), rule chaining (E6), attribute-subset matching (E7b), and placeholder carry-through (E8). `ClickOpsTest` covers all six operation types plus error codes.

**Gaps in `NodeTest` directly:**

- `withAppliedRule` deduplication: there is a test that the dedup guard fires (E5 in `ApplyRulesTest`), but no test directly on `Node::withAppliedRule` asserting `$this` is returned.
- `withText(null)` after `withText('foo')` — the null-clearing path is untested at the unit level.
- `withAttr` overwrite semantics — `testWithAttrPreservesInsertionOrder` only tests two distinct keys; it does not test that `->withAttr('class','foo')->withAttr('class','bar')` produces `['class' => 'bar']`.
- No test for `Node` constructed with wrong-type arguments (would require `strict_types=1` to be meaningful).

**No regression tests for the confirmed bugs.** Neither `EmmetEmitTest` nor `RoundTripTest` includes text content with `{` or `}`, nor attribute values with embedded `"` or `\`. The fix for each bug should be paired with at minimum one unit test and one round-trip test.

**`NodeEquality` not tested in isolation for `appliedRules`-ignored contract.** `NodeAssertTest` checks this indirectly (since `NodeAssert` mirrors `NodeEquality`), but there is no explicit test asserting that `NodeEquality::equals` returns `true` when only `appliedRules` differ.

**No property-based or fuzz tests.** The entire round-trip suite consists of six hand-picked abbreviations. The two confirmed escaping bugs were found by code inspection, not by testing. A simple property test — parse an abbreviation, emit it, parse again, assert the two ASTs are structurally equal — would have caught both bugs immediately.

---

## Verdict

`Node` and `NodeEquality` are well-designed for their role: the immutability model is correctly implemented, the equality semantics are sound, and the `with*` fluent API is ergonomic for the operations the codebase actually performs. However, two confirmed round-trip bugs (unescaped `{`/`}` in text and unescaped `"`/`\` in attribute values) mean that any node containing those characters cannot survive an emit-parse cycle — a hard correctness failure for a library whose primary contract is round-tripping. The absence of `declare(strict_types=1)` in every core file is a systemic code-quality problem that silently masks type errors at the call site. The missing fluent API completeness (`withTag`, `withoutAttr`, `withChildren`) forces every structural operation in `ClickOps` and `RulesEngine` to bypass the class and call `new Node(...)` directly, which is fragile against future constructor changes. These issues together make this component **needs-work**: the design is right, but the implementation has correctness holes that must be addressed before the component can be considered reliable.

**Rating: needs-work**
