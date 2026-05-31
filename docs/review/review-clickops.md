# Review: ClickOps

**Files:** `src/ClickOps.php`, `src/ClickOpError.php`
**Test file:** `tests/ClickOpsTest.php`
**Verdict:** needs-work

---

## Overview

ClickOps is a static, immutable tree-transformation engine. Callers pass a `Node` root and an `$op` array describing one of six named operations; the engine returns a new `Node` tree without mutating the original. Errors are signalled by `ClickOpError`, a `RuntimeException` subclass that carries a string error code alongside the exception message.

---

## What Exists

### Operations

| Type | Description | Key param |
|------|-------------|-----------|
| `swap` | Exchanges a node with its immediately following sibling | `path` |
| `rename` | Replaces a node's tag name | `path`, `with` (string) |
| `unwrap` | Removes a node, splicing its children into the parent | `path` |
| `wrap` | Wraps a node in a new parent element | `path`, `with` (string) |
| `delete` | Removes a node from the tree | `path` |
| `move` | Relocates a node to a new position, adjusting sibling indices after deletion | `path`, `to` |

### Error codes

| Code | Thrown by |
|------|-----------|
| `unknown_op` | `apply()` — unrecognised `type` |
| `bad_path` | `validatePath()`, `getAt()`, `replaceAt()`, `insertAt()`, `unwrapAt()`, `swap()`, `move()` |
| `missing_with` | `rename()`, `wrap()` |
| `missing_to` | `move()` |
| `root_delete` | `delete()`, `replaceAt()` |
| `unwrap_root` | `unwrap()` |

### Internal helpers

- `getAt(Node, array): Node` — bounds-checked descent, throws `bad_path` with a diagnostic message on any invalid index.
- `replaceAt(Node, array, ?Node): Node` — copy-on-write replacement or deletion (null = delete).
- `unwrapAt(Node, array): Node` — recursive descent; at depth 1, splices the target's children into the parent's child array.
- `insertAt(Node, array, Node): Node` — inserts before `$path[-1]`, or appends if the index equals the current child count.
- `adjustDestinationAfterDelete(array, array): array` — decrements the destination's sibling-level index by one when the source index is strictly less than the destination index at that level.
- `validatePath(array): void` — rejects non-integer path elements; does not check for negative values.

---

## What's Good

**Immutability is end-to-end.** Every operation handler, and every helper it calls (`replaceAt`, `unwrapAt`, `insertAt`), constructs and returns a new `Node`. There are no mutation paths, including in edge cases like unwrap-with-no-children or delete at the leaf level. This is the most important design property and it is consistently upheld.

**Error codes are first-class.** Six distinct string codes map precisely to six distinct failure conditions. Using a dedicated `ClickOpError` class rather than generic `RuntimeException` means callers can catch by type and branch on `$e->code` without parsing message strings. The codes are documented implicitly through consistent use.

**`adjustDestinationAfterDelete` handles the main index-shift case correctly.** When `move path=[0] to=[3]` is called on a three-child parent, deleting index 0 leaves two children; the naive destination 3 would be out of range. The adjustment correctly produces 2. Both `testMoveSourceShiftsDestination` and `testMoveSiblingShift` verify this path.

**`replaceAt`'s null-guard at the empty-path base case prevents internal misuse.** The `delete` operation calls `replaceAt($root, $path, null)` with a pre-validated non-empty path. If that guard were absent, a programming error that passed an empty path would silently return `null` to the caller; instead it throws `root_delete`. This is a correct defensive choice.

**`getAt` produces diagnostic-quality error messages.** The message includes the offending index, the depth at which it failed, the parent tag, and the child count: `Path index 5 out of range at depth 0 (node <div> has 2 children)`. This is enough information to locate the problem without a debugger.

**`unwrapAt`'s base case and recursive case are structurally consistent with `replaceAt`.** Both walk to the target node by descending one index per recursive call, rebuild the child array at each level, and return a new `Node`. The code can be read and reasoned about in the same mental model for both functions.

**The test suite uses try/catch (not `expectException`) for code assertions.** PHPUnit's `expectException` cannot inspect custom exception properties. The try/catch pattern in tests like `testBadPathHasCode` and `testSwapNoNextSiblingThrows` correctly verifies the `$e->code` value, making the test assertions meaningful.

---

## What's Bad

### 🔴 `swap()` does not lower-bound the last path index

`validatePath` checks `is_int($idx)` but not `$idx >= 0`. In `swap()`, the extracted `$idx` is used directly as a PHP array key (`$parent->children[$idx]`), bypassing `getAt` and `replaceAt`, both of which do check for negative indices. When `path = [-1]` is passed:

1. `validatePath` passes (`is_int(-1)` is true).
2. The guard at line 37 passes: `-1 + 1 = 0`, which is not `>= count($parent->children)` for any non-empty parent.
3. `$parent->children[-1]` evaluates to `null` in PHP (undefined key, emits Warning).
4. `$newChildren` ends up with `null` at index 0 and the real node at key `-1`.
5. The returned `Node` has a `null` child; any downstream emit or traversal throws `TypeError`.

The resulting corruption is silent — no `ClickOpError` is thrown. The fix is one line: change `validatePath`'s check from `!is_int($idx)` to `!is_int($idx) || $idx < 0`.

### 🔴 `move()` into a descendant silently corrupts the tree

When the destination path is a strict descendant of the source path **and** the first diverging index equals the source index, `adjustDestinationAfterDelete` does not adjust and `insertAt` navigates into the wrong subtree. Concrete case:

- Tree: `div > [header, main > [section > [p], aside]]`
- Op: `move path=[1,0] to=[1,0,0]`

After deleting `[1,0]` (section), `main`'s children become `[aside]`. `adjustDestinationAfterDelete([1,0], [1,0,0])` returns `[1,0,0]` unchanged because `srcIdx=0`, `dstIdx=0`, and `0 < 0` is false. `insertAt` then navigates `main > children[0]` = `aside` and appends `section` inside `aside`. The result is `<div><header/><main><aside><section><p/></section></aside></main></div>` — no error is raised.

The existing protection is incidental: `move [1] to [1,0]` happens to throw `bad_path` because after deletion the destination path is out of range. Any configuration where the destination path survives deletion via a sibling filling the vacated slot will silently corrupt.

Fix: before executing the move, add an explicit prefix check:

```php
private static function isPrefixOf(array $prefix, array $path): bool {
    if (count($prefix) >= count($path)) return false;
    for ($i = 0; $i < count($prefix); $i++) {
        if ($path[$i] !== $prefix[$i]) return false;
    }
    return true;
}
```

Call it in `move()` after validating `$to`:

```php
if (self::isPrefixOf($path, $to)) {
    throw new ClickOpError('bad_path', 'cannot move a node into its own subtree');
}
```

### 🟡 `ClickOpError::$code` shadows `Exception::$code` via `__get`

`ClickOpError` stores the string error code in `$this->errorCode` and exposes it through a `__get('code')` accessor. This produces two divergent representations:

- `$e->getCode()` returns `0` (the integer passed to `parent::__construct`).
- `$e->code` returns the string code correctly via `__get`.
- `isset($e->code)` returns `false` because PHP does not call `__get` when evaluating `isset()` and `__isset` is not implemented.

Any caller writing a defensive guard like `if (isset($e->code)) { ... $e->code ... }` will silently skip the code. The test suite works around this by reading `$e->code` directly in `catch` blocks, which obscures the problem.

Fix: replace the `__get` pattern with a public readonly property (PHP 8.1+):

```php
public function __construct(
    public readonly string $code,
    string $message,
    ?\Throwable $previous = null,
) {
    parent::__construct($message, 0, $previous);
}
```

This eliminates the shadow, makes `isset($e->code)` return `true`, enables static analysis, and removes 7 lines of magic accessor code.

### 🟡 `rename()` and `wrap()` do not type-check `$op['with']`

The guard `!isset($op['with']) || $op['with'] === ''` does not include `!is_string($op['with'])`. Two outcomes:

- Integer `42`: passes the guard (is_int not checked; `42 !== ''` is true). Because `ClickOps.php` lacks `declare(strict_types=1)`, PHP coerces `42` to `'42'` via `Node::__construct`'s `string $tag` hint, silently creating a numerically-named tag with no error.
- Array value: passes the guard (non-empty array `!== ''`). PHP throws a raw `TypeError` from `Node::__construct` rather than a `ClickOpError`, breaking the contract that all input errors surface as `ClickOpError`.

Fix: add `!is_string($op['with'])` to the guard in both `rename()` and `wrap()`.

### 🟢 `ClickOps.php` is missing `declare(strict_types=1)`

`ClickOpError.php` has it at line 2; `ClickOps.php` does not. Passing a non-array `$path` (e.g. the string `'0'`) throws `TypeError: ClickOps::validatePath(): Argument #1 ($path) must be of type array`, bypassing the `ClickOpError` contract. Adding the declaration and catching type errors explicitly before they reach PHP's engine would close this gap.

---

## Bugs & Risks

| Severity | Location | Description |
|----------|----------|-------------|
| 🔴 Critical | `move()` + `adjustDestinationAfterDelete()` | Move into a descendant when `srcIdx === dstIdx` at the fork level produces a silently corrupted tree. No error is raised. |
| 🔴 High | `swap()` + `validatePath()` | Negative last-path index bypasses the sibling-count guard, reads an undefined array key, stores `null` into the new children array, and returns a `Node` with a `null` child. |
| 🟡 Moderate | `ClickOpError.__get` | `isset($e->code)` returns `false` even though `$e->code` evaluates correctly. Defensive callers checking `isset` first will silently skip error-code handling. |
| 🟡 Moderate | `rename()`, `wrap()` | Non-string `with` values either silently coerce (integer) or throw a raw `TypeError` (array) instead of a `ClickOpError`. |
| 🟢 Low | `ClickOps.php` header | Missing `declare(strict_types=1)`. Non-array path throws `TypeError` instead of `ClickOpError`. |
| 🟢 Low | `unwrapAt()` | Unwrapping a node that has `$text` but no children silently discards the text. No error, no documentation, no test. |
| 🟢 Low | `validatePath()` | Does not check `$idx >= 0`. Only `swap()` is exploitable from this gap today (all other operations flow through `getAt`/`replaceAt` which do check negative indices), but the gap will recur for any future operation that extracts an index without going through those helpers. |

---

## Missing Features

- **No `copy` operation.** Duplicating a node requires external construction; there is no in-engine clone-and-insert.
- **No `reorder` / bulk-swap.** Moving a node multiple positions requires chaining `swap` calls with no atomic guarantee.
- **`wrap` accepts no attributes for the wrapper.** The wrapper is always created with `[]` attrs. Adding `id` or `class` to the wrapper requires a second operation, and there is no `setAttribute` to use for that second step.
- **No `setAttribute` / `removeAttribute`.** Node attributes cannot be changed through ClickOps at all. Only the tag name and structural position are modifiable.
- **No `setText`.** The `$text` field of a `Node` is read-only from ClickOps's perspective.
- **No batch / compound operation.** Multiple ops must be chained as separate `apply()` calls with no atomicity. On failure mid-sequence, the caller is responsible for discarding the partially-transformed tree.
- **No `addChild` / `insertChild` for new nodes.** The only path to adding a new node is via `wrap` (which requires an existing node to wrap) or `move` (which requires an existing node elsewhere). There is no operation to insert a brand-new leaf at an arbitrary path.

---

## Improvement Ideas

**Unify the three tree-descent implementations.** `replaceAt`, `unwrapAt`, and `insertAt` share identical structure: recurse to the target level, rebuild the child array, return a new `Node`. They could share a single parameterised primitive:

```php
private static function transformAt(Node $root, array $path, callable $leafTransform): Node
```

where `$leafTransform` receives the current children array and the target index and returns the new children array. This eliminates the triplicated bounds-check and `new Node(...)` construction logic, and ensures any future guard (e.g. the descendant-move prefix check) is applied consistently across all descent paths.

**Eliminate the double traversal in `swap()`.** `swap()` calls `getAt($root, $parentPath)` to read the parent, then calls `replaceAt($root, $parentPath, $newParent)` which descends the same path again. A shared descent primitive (above) would eliminate the second traversal.

**Add `canApply(Node $root, array $op): bool`.** A dry-run validation method would let UIs check operation legality before committing, without needing to catch exceptions or copy the tree.

**Document the unwrap-with-text behaviour.** Whether `unwrapAt` silently discarding a node's `$text` is intentional or an oversight is not clear from code or tests. A doc comment on `unwrapAt` stating the semantics (text is dropped, children are spliced) would prevent future confusion. If text retention is desired, emit a synthetic `#text` Node or throw an error.

---

## Test Coverage

| Area | Status |
|------|--------|
| All six happy paths | Covered |
| All six error codes (`unknown_op`, `bad_path`, `missing_with`, `missing_to`, `root_delete`, `unwrap_root`) | Covered |
| `testInvalidPathThrows` + `testBadPathHasCode` | Redundant — both use `path=[5]` on the same tree. `testInvalidPathThrows` uses `expectException` and cannot check the code; `testBadPathHasCode` uses try/catch and does check it. The first adds no coverage beyond the second. |
| `move()` into descendant (`path=[1,0] to=[1,0,0]`) | Not covered — this is the silent-corruption bug. Adding one test would immediately expose it. |
| `swap()` with negative path index (`path=[-1]`) | Not covered — `testSwapNoNextSiblingThrows` and `testSwapRootThrows` do not reach the negative-index code path. |
| `unwrap()` on a leaf node (no children, has text) | Not covered — behaviour is undocumented and untested. |
| `rename`/`wrap` with non-string `with` (integer or array) | Not covered — the `TypeError`-instead-of-`ClickOpError` gap is not exposed by any test. |
| `isset($e->code)` returning `false` | Not covered — the test suite reads `$e->code` directly in `catch` blocks and never tests the `isset` footgun. |
| `move` with empty `to` path (`to=[]`) | Not covered — currently throws `bad_path` from `insertAt`, but there is no test asserting the code or the message. |

---

## Verdict

ClickOps has a sound architectural foundation: the immutability contract is genuine and consistent, error codes are typed and reliable for the operations they cover, and the index-adjustment logic for same-level sibling moves is correct and tested. However, two confirmed bugs make it unsafe for production use without fixes. The move-into-descendant case silently produces a structurally plausible but semantically wrong tree — the worst kind of bug because callers receive no signal that anything went wrong. The negative-index case in `swap()` produces a `Node` with a `null` child that will throw `TypeError` downstream. Both bugs are one to three lines to fix. The `ClickOpError.__get` design flaw is a practical hazard for any caller who guards with `isset` before reading the code. Address the two critical bugs, add a `validatePath` non-negative check, replace `__get` with a `readonly` property, and add `declare(strict_types=1)` to `ClickOps.php` before treating this component as stable.

**Rating: needs-work**
