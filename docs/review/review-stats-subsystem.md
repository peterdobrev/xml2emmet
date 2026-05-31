# Review: Stats Subsystem (Stats.php, Stats/CssClassCounter.php, StatsHandler.php)

## Overview

The stats subsystem computes structural metrics over a parsed Node tree (HTML path) and scans CSS source for class-name frequency (CSS path). Both paths are exposed through a single `POST /api/stats` endpoint that branches on a `kind` parameter. The HTML path runs `Stats::compute()` which walks the immutable Node tree in one O(n) recursive pass accumulating seven metrics. The CSS path runs `CssClassCounter::count()` which uses a regex with lookbehind to identify class tokens in stylesheet text.

**Verdict: needs-work** — two confirmed correctness bugs affect API consumers, a stability risk can crash a worker process with a trivial payload, and a misleading field name is pinned by a test that asserts the wrong semantic.

---

## What Exists

### Files

| File | Role |
|---|---|
| `Stats.php` | Static class; `compute(Node $root): array` entry point; `walk()` recursive accumulator |
| `Stats/CssClassCounter.php` | Static class; `count(string $css): array` regex scanner |
| `StatsHandler.php` | HTTP handler; routes `kind=html` to `Stats::compute()` via `XmlParser`, `kind=css` to `CssClassCounter::count()` |

### HTTP API

| Endpoint | Parameter | Response fields |
|---|---|---|
| `POST /api/stats` | `kind=html`, `input=<html>` | `elements`, `distinct_tags`, `attributes`, `max_depth`, `depth_histogram`, `top_classes` |
| `POST /api/stats` | `kind=css`, `input=<css>` | `class_count`, `top_classes` |
| `POST /api/stats` | `kind=<anything else>` | 422 |

### Internal Stats::compute() return shape

| Key | Type | Computed as |
|---|---|---|
| `nodeCount` | int | All nodes including `#text` |
| `depth` | int | `1 + max(childDepths)`, text nodes count |
| `tagHistogram` | array | Element tag frequency, `#text` excluded |
| `attrCount` | int | Total attribute count across all elements |
| `textLength` | int | `strlen()` sum across all text nodes |
| `classCounts` | array | HTML `class` attribute token frequency |
| `depthHistogram` | array | Element depth distribution, `#text` excluded |

Note: `textLength` is not currently exposed in the HTTP response but exists in the return array.

---

## What's Good

**Single-pass accumulator.** `walk()` uses seven by-reference parameters and a single O(n) traversal with no intermediate allocations. Clean and fast for typical documents.

**Immutable node tree.** `Node` uses readonly constructor promotion, so `Stats` has no aliasing or mutation hazards. No defensive copying needed.

**Class token parsing.** `classCounts` correctly trims, splits on `/\s+/`, and skips empty tokens, handling all HTML whitespace variants including tabs and newlines.

**depthHistogram element-only exclusion.** The guard `if ($n->tag === '#text') continue;` in the histogram accumulation correctly limits the histogram to element structure.

**CssClassCounter lookbehind.** `(?<![A-Za-z0-9_\-])` before the class token pattern successfully rejects float literals (`1.5rem`, `0.5`), decimal values in `calc()`/`transform`, and BEM modifier names where a digit or letter precedes the dot. The pattern does not over-match.

**Deterministic topClasses.** `topClasses` sorts by count descending then name ascending, so output is stable regardless of PHP's non-deterministic hash-map iteration order.

**Type safety.** Both classes declare `strict_types=1` and use PHP 8.0+ trailing-comma parameter lists.

**Test happy-path coverage.** All seven return keys of `Stats::compute()` have dedicated unit tests. Edge cases such as whitespace-only class attributes and `#text` exclusion from `depthHistogram` are explicitly tested.

---

## What's Bad

### 🔴 depth vs depthHistogram semantic inconsistency

`walk()` propagates depth from `#text` leaves — the return value is `1 + max(childDepths)` with no tag check — while `depthHistogram` explicitly skips `#text` nodes. For any tree where the deepest leaf is a `#text` node with no element sibling at that depth, `depth` = N and `max(array_keys($depthHistogram))` = N-1. `StatsHandler` exposes `depth` as `max_depth`. Any frontend that overlays `max_depth` against `depth_histogram` will have an off-by-one error on the rightmost bar.

Verification confirms the repro is real: a manually constructed `Node('p')` with a single `Node('#text')` child returns `depth=2` and `depthHistogram=[1=>1]`.

### 🔴 nodeCount includes #text but HTTP API labels it `elements`

`StatsHandler` maps `nodeCount` to the response key `elements`. For `<p>hello <b>world</b></p>`, the XmlParser emits three nodes: `p`, a `#text('hello ')` node, and `b`. `nodeCount=3` but only 2 are elements. `sum(tagHistogram.values)` equals 2. The discrepancy is invisible to the API consumer because no `textNodeCount` field exists to account for the difference.

### 🔴 CssClassCounter matches inside CSS comments

`preg_match_all` runs on raw CSS with no comment-stripping step. The input `/* .hidden { } */` yields `'hidden'` as a match. This false-positive category is not documented in the inline comment at the top of `CssClassCounter.php`, which lists string literals and `url()` paths as known v1 limitations. The test suite does not pin or acknowledge this behavior.

### 🟡 class_count is total occurrences, not distinct count

`StatsHandler` line 27: `'class_count' => array_sum($counts)`. For `.btn {} .btn:hover {} .other .btn {}` this returns `4` (3 `btn` + 1 `other`), not `2` (distinct names). The field name strongly implies distinct count to any consumer who has not read the implementation. The HTTP integration test explicitly asserts the value `4`, which pins the misleading semantic rather than correcting it.

### 🟡 textLength uses strlen() (byte count, not character count)

`Stats::walk()` line 45 accumulates `strlen($n->text ?? '')`. `strlen('é')` = 2, `mb_strlen('é', 'UTF-8')` = 1. Any non-ASCII content silently inflates the metric. The field is not currently in the HTTP response, but it exists in the return array and will mislead callers if it is ever exposed or consumed internally. There is no documentation of the byte-count choice.

### 🟡 Documentation error in CssClassCounter.php comment

Lines 11-12 state `url(./img.png)` yields `'png'` as a known false-positive. This is wrong. In `img.png`, the `.` is preceded by `g`, which is in the negative lookbehind set `[A-Za-z0-9_\-]`, so the pattern does not match `png`. The actual false-positive from `url()` paths would be something like `url(./foo-class)` where `.` is preceded by `(`. An inaccurate documented example will mislead anyone auditing the known limitations.

### 🔴 Unbounded recursion — no depth guard in walk() or XmlParser::parseElement()

Neither `Stats::walk()` nor `XmlParser::parseElement()` has a recursion depth guard. PHP's default call stack overflows at roughly 200-500 frames. A document with ~300 singly-nested single-char elements is approximately 2KB — well within the 2MB body size limit enforced by `Validation`. Because `XmlParser::parseElement()` is called first in the HTML branch (`StatsHandler` line 32), the fatal `E_ERROR` fires in the parser before `Stats::walk()` is even reached. A depth guard added only to `Stats::walk()` would not fix the root exposure.

---

## Bugs & Risks

| Severity | Category | Description |
|---|---|---|
| 🔴 Critical | Stability/Security | Unbounded recursion in `XmlParser::parseElement()` and `Stats::walk()`. ~2KB adversarial input crashes worker process with fatal `E_ERROR`, bypassing error handling. |
| 🔴 Correctness | Semantic inconsistency | `depth` includes `#text` depth, `depthHistogram` excludes it. `max_depth` and `depth_histogram` disagree on trees with text-node deepest leaves. |
| 🔴 Correctness | `elements` field overcounts | `nodeCount` includes `#text` nodes; `StatsHandler` exposes it as `elements`. No `textNodeCount` field to let callers derive true element count. |
| 🔴 Correctness | CSS comment false positives | `CssClassCounter` matches class tokens inside `/* ... */` comments. Undocumented and untested. |
| 🟡 Misleading API | `class_count` semantics | `class_count` is `array_sum($counts)` (total occurrences), not `count($counts)` (distinct names). Pinned incorrectly by test. |
| 🟡 Data quality | `textLength` in bytes | `strlen()` counts bytes; UTF-8 multibyte characters inflate the value silently. Undocumented. |
| 🟢 Minor | Documentation error | `url(./img.png)` false-positive example in `CssClassCounter.php` comment is factually wrong. |

**Retracted finding from analysis:** The claim that CssClassCounter's leading-hyphen match (`-foo`) is non-standard is incorrect. A single leading hyphen followed by a letter is valid CSS vendor-prefix syntax per the Selectors spec. The regex behavior is correct.

---

## Missing Features

**textNodeCount.** Without this field, callers cannot derive a clean element count from `elements` (which is actually `nodeCount`), nor can they compute the text-node fraction of the tree.

**distinct_class_count for the CSS path.** `class_count` currently returns total occurrences. Consumers who need the number of unique class names must client-side compute `top_classes.length`, which only works up to the `top_classes` return limit.

**attrHistogram.** Which attribute names appear most frequently (`href`, `class`, `id`, `data-*`, `aria-*`). Highly useful for any tooling that normalises or rewrites attributes.

**leafNodeCount and average branching factor.** Useful structural metrics for understanding tree shape independently of depth.

**Cross-mode HTML+CSS analysis.** Given HTML with embedded `<style>` blocks, the system cannot correlate CSS-defined class names with HTML `class` attributes to identify unused or undefined classes. The two paths are isolated silos with no combined mode.

---

## Improvement Ideas

### Fix depth/depthHistogram inconsistency

In `walk()`, add a tag check before incrementing depth, matching the `depthHistogram` exclusion:

```php
// Option A: exclude #text from depth calculation
if ($n->tag !== '#text') {
    $childDepth = self::walk($child, ...);
    $maxChildDepth = max($maxChildDepth, $childDepth);
}
```

Alternatively, include `#text` in `depthHistogram`. Either is acceptable — they must agree.

### Add recursion depth guard

At the top of `Stats::walk()`:

```php
private static function walk(Node $n, ..., int $currentDepth = 0): int {
    if ($currentDepth > 500) {
        throw new StatsLimitException('Document nesting exceeds maximum depth');
    }
    ...
}
```

`StatsHandler` catches `StatsLimitException` and returns a 422. The same guard (or an equivalent pre-check on tree depth) must be applied to `XmlParser::parseElement()` or at the HTTP layer before parsing begins, because `XmlParser` recurses first and will overflow before `Stats::walk()` is called.

An iterative implementation using `SplStack` would eliminate the risk entirely and is straightforward given the immutable tree.

### Fix textLength to use character count

```php
$textLength += mb_strlen($n->text ?? '', 'UTF-8');
```

If byte count is intentional, rename the field to `textBytes` and document the choice.

### Fix class_count semantics

```php
'class_count' => count($counts),           // distinct names
'total_class_occurrences' => array_sum($counts),  // total occurrences
```

Update the integration test assertion from `4` to `2` for the existing fixture. This is a breaking change to the HTTP API and requires a version bump or migration note.

### Strip CSS comments before scanning

```php
$css = preg_replace('/\/\*.*?\*\//s', '', $css);
```

Add this as the first step in `CssClassCounter::count()`. Also consider stripping string literals:

```php
$css = preg_replace('/(["\']).*?\1/', '""', $css);
```

Both are single cheap passes that eliminate the documented (string literals) and undocumented (comments) false-positive categories simultaneously.

### Expose textNodeCount

In `Stats::walk()`, increment a separate counter when `$n->tag === '#text'`. Expose both `elementCount` and `textNodeCount` in the return array and rename the HTTP field from `elements` to `element_count`.

### Fix the CssClassCounter.php documentation comment

Replace the incorrect `url(./img.png)` example with a correct one (e.g., `url(./foo-class)` where the dot is preceded by `(`).

---

## Test Coverage

### StatsTest (unit)

Comprehensive on happy paths. All seven return keys are tested. Dedicated tests for depth, histogram, `attrCount`, `textLength`, `classCounts`, and `depthHistogram` including edge cases (whitespace-only class attribute, `#text` exclusion from `depthHistogram`, multi-child depth).

**Gap:** No test combines a tree where text nodes are the deepest leaves with no element sibling. This is the exact condition that exposes the `depth`/`depthHistogram` inconsistency. The bug is not caught.

### CssClassCounterTest (unit)

Covers simple selectors, multi-occurrence, nested selectors, hyphens/underscores, and empty input. The string-literal false-positive is explicitly pinned as accepted v1 behavior.

**Gap:** No test for input containing `/* CSS comments */`. The comment-match false-positive is therefore undetected and unacknowledged.

### StatsHttpTest (integration)

Covers HTML and CSS happy paths. Invalid `kind` returns 422. `top_classes` structure is validated. The `class_count=4` assertion pins the total-occurrences semantic without documenting the intent.

**Gaps:** No test for deeply nested input (recursion overflow), no multibyte content test, no test for the `depth`/`depth_histogram` discrepancy.

### Overall assessment

| Area | Coverage |
|---|---|
| Happy paths | Good |
| `depth`/`depthHistogram` inconsistency | Not covered |
| CSS comment false-positive | Not covered |
| Recursion overflow | Not covered |
| UTF-8 `textLength` | Not covered |
| `class_count` semantics | Covered but pinning wrong behavior |
| Property-based / fuzz tests | None |

No property-based or fuzz tests exist. Given the recursive tree-walker and the regex scanner, fuzz tests on `Stats::compute()` and `CssClassCounter::count()` would have high expected value for surfacing the recursion and regex edge cases.

---

## Verdict

The stats subsystem has a solid O(n) design and clean immutable-tree semantics, but it ships with two correctness bugs that will surface in production (`depth`/`depthHistogram` disagreement, `elements` overcounting text nodes), a stability risk that turns a 2KB adversarial input into a worker crash, a misleading API field whose wrong behavior is locked in by a test, and an undocumented false-positive class in `CssClassCounter`. None of these are hypothetical — each has a concrete repro. The fixes are all small and localised: a depth-check consistency change, a recursion guard, a `mb_strlen` swap, a field rename with test update, and a single `preg_replace` comment-strip. The subsystem should not be considered stable for production use until at minimum the recursion overflow (🔴 critical) and the `elements`/`class_count` semantic issues are resolved.

**Rating: needs-work**
