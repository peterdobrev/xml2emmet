# Review: RulesEngine (RulesEngine.php, Rule.php, RuleError.php)

**Date:** 2026-05-31
**Verdict:** needs-work
**Files reviewed:** `src/RulesEngine.php`, `src/Rule.php`, `src/RuleError.php`, `src/Node.php`, `src/NodeEquality.php`, `tests/ApplyRulesTest.php`

---

## Overview

The RulesEngine is a pure-functional, immutable term-rewriting engine. It applies an ordered sequence of structural rewrite rules to an XML/HTML node tree via sequential, full-tree passes (left-fold over rules). Each rule holds a `pattern` and a `replacement` — both `Node` trees — plus a string `id`. Pattern nodes whose tags match `/^E\d+$/` (e.g. `E1`, `E2`) act as named wildcards that bind entire candidate subtrees. Substitution replaces corresponding placeholders in the replacement template with the bound subtrees. An `appliedRules` guard on each `Node` prevents a rule from rewriting its own output.

The engine is architecturally clean and the immutable data model is implemented correctly. However, two latent correctness bugs, an undocumented reserved-namespace collision, and the complete absence of input validation at construction time make this not yet production-safe.

---

## What Exists

| File | Role | Lines |
|------|------|-------|
| `RulesEngine.php` | Core engine: `apply()`, `walk()`, `matchNode()`, `substitute()`, `tagSubtree()` | 154 |
| `Rule.php` | Value object: `id`, `pattern`, `replacement` (all `readonly`) | 9 |
| `RuleError.php` | `RuntimeException` subclass for mismatched placeholder errors | 10 |
| `Node.php` | Immutable tree node: `tag`, `attrs`, `children`, `text`, `appliedRules` | 25 |
| `NodeEquality.php` | Deep structural equality, ignoring `appliedRules`, order-independent attrs | 25 |
| `tests/ApplyRulesTest.php` | 9 tests, 64 assertions, all passing | 72 |

**Entry point:**
```php
RulesEngine::apply(Node $root, array $rules): Node
```
All methods are `private static`; the class is `final`. `Rule` and `Node` are both `final` with fully `readonly` properties.

---

## What's Good

**Correct immutability discipline.** `Node` has every property declared `readonly`. `walk()` at line 49 performs the identity optimization `if ($newChildren === $node->children) return $node` — this is correct because PHP array `===` on object arrays checks object identity, which is exactly the right test for "nothing changed" on an immutable node tree.

**Infinite-loop guard is sound.** `walk()` line 31 checks `in_array($rule->id, $node->appliedRules, true)` (strict mode, no type coercion). `tagSubtree()` stamps every node in the replacement subtree with the rule ID before returning it. `testE5` confirms a rule that rewrites `<x/>` to `<x><x/></x>` fires exactly once.

**Backtracking-safe match state.** `matchNode()` passes `$bindings` by value through recursion, not by reference. Failed child matches cannot corrupt bindings collected from earlier siblings. No shared mutable state.

**Repeated-placeholder equality.** A pattern like `<f><E1/><E1/></f>` correctly requires both children to be structurally equal. `matchNode()` line 76 calls `NodeEquality::equals($bindings[$name], $candidate)` when a placeholder name is seen a second time.

**`NodeEquality` is semantically correct.** It `ksort`s both attribute arrays before comparing (order-independent), and ignores `appliedRules` (which is engine metadata, not document content).

**Sequential rule passes.** `apply()` is a plain `foreach` fold. `testE6` confirms `r1: a→b` followed by `r2: b→c` produces `c`, not `b`. Deterministic and easy to reason about.

**Attribute subset semantics.** Pattern attributes are a required subset; candidate nodes may carry additional attributes. `testE7b` covers this. Appropriate for HTML/XML matching where class or data attributes vary.

**`tagSubtree` deduplication.** Line 149 checks `in_array($ruleId, $node->appliedRules, true)` before appending, so a bound node that was already tagged (e.g., because it was previously produced by the same rule in a multi-step rewrite) does not accumulate duplicate IDs.

---

## What's Bad

### 🔴 Duplicate rule IDs silently break correctness

`Rule::__construct()` and `RulesEngine::apply()` impose no uniqueness requirement on rule IDs. Because `appliedRules` is keyed by ID string, two rules sharing the same ID collide: after the first rule fires and tags its output with ID `'r'`, the second rule (also `'r'`) finds the `appliedRules` guard tripped on every node it should match and skips them silently.

**Concrete trace:**
```php
$r1 = new Rule('r', new Node('a'), new Node('b'));
$r2 = new Rule('r', new Node('b'), new Node('c'));
RulesEngine::apply(new Node('a'), [$r1, $r2]); // returns <b/>, not <c/>
```

The `appliedRules` guard was designed for self-loop prevention within a single rule, not cross-rule isolation. Using the same ID across distinct rule objects is a silent footgun with no diagnostic output.

### 🔴 `RuleError` is deferred to match time, not construction time

`Rule::__construct()` at lines 4-8 of `Rule.php` stores `pattern` and `replacement` without validation. `RulesEngine::substitute()` at lines 112-116 of `RulesEngine.php` throws `RuleError` only when the rule fires on a matching node. A rule with pattern `<E1/>` and replacement `<E2/>` (E2 is unbound) silently constructs. If the tree contains no nodes matching `<E1/>`, the defect is invisible forever.

### 🟡 Persistent `appliedRules` across `apply()` calls

`Node` instances are immutable, so a node tagged with rule ID `'r'` during one `apply()` call retains that tag in subsequent calls. If the same `Node` tree is passed to a second `apply()` call that includes a rule with the same ID, the guard incorrectly prevents that rule from firing on nodes it legitimately should match. This is a misuse scenario rather than a defect in single-pass usage, but `Node` exposes no `resetAppliedRules()` or equivalent and the behaviour is undocumented.

### 🟡 `E[0-9]+` tag names are permanently reserved with no escape

`PLACEHOLDER_RE = '/^E\d+$/'` is a `private const` in `RulesEngine`. Any XML or HTML vocabulary that uses element tags matching this pattern (e.g. `E1`, `E2`, `E10`) cannot be matched literally in a pattern: the engine will always interpret such tags as wildcards. There is no escape mechanism, alternative sigil, or constructor parameter to override the pattern. This is undetectable at configuration time.

### 🟡 No depth guard — naive recursion throughout

All five recursive methods (`walk`, `matchNode`, `substitute`, `tagSubtree`, `NodeEquality::equals`) have no depth limit or iterative fallback. With xdebug enabled (default `max_nesting_level=512`), a tree 512 nodes deep triggers a fatal error with no application-level message. Without xdebug, the PHP process crashes at the OS stack limit. There are no depth-related keywords anywhere in the source.

---

## Bugs & Risks

| Severity | Category | Location | Description |
|----------|----------|----------|-------------|
| 🔴 | Correctness bug | `RulesEngine.php:31`, `Rule.php:4-8` | Duplicate rule IDs cause silent second-rule skip. `appliedRules` guard collides across distinct `Rule` objects sharing an ID string. |
| 🔴 | Correctness bug | `Rule.php:4-8`, `RulesEngine.php:112-116` | `RuleError` deferred to match time. Unbound placeholder in replacement is undetected at construction. Invisible if the rule never fires. |
| 🟡 | Production stability | `RulesEngine.php` (all recursive methods) | Stack overflow on deep trees. Zero depth guards. Fatal crash with no recoverable exception at ~512 levels with xdebug or at OS stack limit without. |
| 🟡 | Correctness, silent | `RulesEngine.php:6` | `E[0-9]+` tags permanently reserved as placeholder syntax. No escape mechanism. XML vocabularies using these tag names cannot be targeted by literal-match rules. |
| 🟡 | Persistent state | `Node.php`, `RulesEngine.php:29-33` | `appliedRules` survives across `apply()` calls. Re-using a `Node` tree in a second pass with overlapping rule IDs silently suppresses matches. Undocumented. |
| 🟢 | Code clarity | `RulesEngine.php:39`, `RulesEngine.php:40` | `substitute()` returns the same `Node` instance for each placeholder occurrence. If the same placeholder appears twice in the replacement, the output is a DAG, not a tree. `tagSubtree()` immediately copies each instance, so no mutation hazard exists today. Inserting code between lines 39-40 that assumes tree structure (e.g. a path tracker) would observe DAG semantics silently. Note: downgraded from a risk — `Node` is `final` with `readonly` properties throughout, making future mutation impossible by PHP semantics. |

---

## Missing Features

**1. Construction-time rule validation.**
`Rule::__construct()` should walk the `$pattern` tree, collect all placeholder names, then walk `$replacement` and assert every placeholder it contains is in that set. This converts a deferred `RuleError` at match time into an immediate `\InvalidArgumentException` at construction time.

```php
public function __construct(string $id, Node $pattern, Node $replacement) {
    $bound   = self::collectPlaceholders($pattern);
    $used    = self::collectPlaceholders($replacement);
    $unbound = array_diff($used, $bound);
    if ($unbound) {
        throw new \InvalidArgumentException(
            "Replacement references unbound placeholders: " . implode(', ', $unbound)
        );
    }
    $this->id = $id; $this->pattern = $pattern; $this->replacement = $replacement;
}
```

**2. Unique rule ID enforcement.**
`apply()` should validate that all supplied rule IDs are unique before beginning the fold. A minimum viable fix is a `trigger_error(E_USER_WARNING, ...)` on duplicate detection; throwing `\InvalidArgumentException` is preferable.

**3. Depth limit or iterative traversal.**
A configurable `$maxDepth` parameter in `walk()` throwing a domain exception, or an `SplStack`-based iterative implementation, prevents fatal crashes on production documents.

**4. Innermost (bottom-up) traversal option.**
The current strategy is outermost (pre-order, top-down): when a node matches, its subtree is not re-walked for the same rule. Users porting rules from XSLT, which defaults to innermost `apply-templates`, will expect inner matches to fire first. A flag or strategy enum would cover this.

**5. Explicit "text must be absent" pattern constraint.**
`matchNode()` line 86: `null` text in the pattern unconditionally means "wildcard" — it matches nodes with any text and nodes with no text. There is no way to write a pattern that requires `text === null`. A sentinel constant (`Node::NO_TEXT`) or a dedicated `$requireNoText` flag would resolve the ambiguity.

**6. Public documentation of the placeholder namespace.**
`PLACEHOLDER_RE` is `private const` with no entry in any public docblock, README, or type contract. The reservation of the entire `E[0-9]+` tag namespace is a breaking constraint for any XML integrator; it must appear at the API boundary.

---

## Improvement Ideas

**`Rule::validate()` static helper.** A non-throwing `Rule::validate(Node $pattern, Node $replacement): string[]` that returns a list of problem strings (unbound placeholders, unreferenced but bound pattern placeholders) is useful both for rule-authoring tooling and for surfacing partial errors in bulk-import scenarios.

**`Rule::renameTag()` factory.** The most common rule type — rename a tag while keeping its subtree — requires constructing two `Node` objects manually. A named constructor `Rule::renameTag(string $from, string $to, string $id): Rule` would reduce boilerplate and make intent explicit.

**Configurable placeholder sigil.** Make `PLACEHOLDER_RE` a `protected` constant or a constructor parameter (with default `/^E\d+$/`) so integrators with clashing XML vocabularies can substitute their own sigil (e.g. `/__\d+$/`).

**Outermost strategy documented in class docblock.** The class-level PHPDoc should state prominently that traversal is outermost (pre-order top-down) and contrast it with bottom-up. Users unfamiliar with term rewriting will otherwise discover this only through unexpected test failures.

**Richer `RuleError` messages.** Include the rule `id`, the unbound placeholder name, and the matched node's tag path in the exception message. The current message `"Replacement references unbound placeholder 'E2'."` provides no context about which rule or where in the tree the error occurred.

**`appliedRules` reset mechanism.** Provide a `Node::withoutAppliedRules(): self` helper (or have `apply()` strip `appliedRules` at entry) to make it safe to pass the same `Node` tree through multiple `apply()` calls with potentially overlapping rule IDs.

---

## Test Coverage

**Overall: 9 tests, 64 assertions, all passing.** The happy path is well covered.

| Test | What it covers |
|------|---------------|
| `testE1IdentityWhenNoMatch` | No match — tree unchanged |
| `testE2SingleSubstitution` | Single tag replacement |
| `testE3SinglePlaceholder` | Bind one placeholder, substitute |
| `testE4MultiplePlaceholders` | Swap two sibling subtrees via E1/E2 |
| `testE5DoesNotReapplyWithinSameRule` | Infinite-loop guard |
| `testE6RulesAppliedInOrder` | Sequential passes, r1 then r2 |
| `testE7AttributeLiteralMustMatch` | Attribute mismatch rejects match |
| `testE7bAttributeSubsetMatches` | Attribute subset semantics |
| `testE8AttributesAndTextCarryThroughPlaceholders` | Attrs/text preserved via placeholder |

**Critical gaps:**

| Gap | Risk |
|-----|------|
| No test for duplicate rule IDs | Correctness bug is completely invisible |
| No test for `RuleError` on unbound replacement placeholder | Positive error path never exercised |
| No test for outermost vs. innermost distinction | A tree where the pattern matches at both parent and child; only the outer should fire — not confirmed |
| No test for a placeholder used twice in the replacement template | DAG output path untested |
| No test for an empty `$rules` array | Trivial but confirms `apply()` returns root unchanged |
| No test for an `E[0-9]+` tag in the candidate tree | Ensures the engine does not misidentify candidate tags as placeholders during `walk()` (it does not, because `PLACEHOLDER_RE` is only checked on the _pattern_ side in `matchNode()`, but this is not explicitly tested) |
| No stress test approaching PHP stack depth | Stack overflow risk has no regression coverage |

---

## Verdict

The RulesEngine has a sound architectural foundation: fully immutable nodes, value-parameter binding, correct identity optimization, and a deterministic sequential-pass strategy. The core algorithm is correct for all tested cases. However, two latent correctness bugs — duplicate rule IDs silently suppressing rewrites, and deferred `RuleError` that hides mismatched rules when the tree contains no matching nodes — are undetectable without either code inspection or tests that do not exist. The `E[0-9]+` placeholder namespace collision is an undocumented breaking constraint for XML integrators, and the absence of any depth guard makes the engine unsafe for production documents of arbitrary depth. None of these require architectural changes to fix; they are all tractable additions. The engine is **needs-work**: it should not be deployed in a production path until construction-time validation, duplicate-ID rejection, and a depth guard are in place.

**Rating: needs-work**
