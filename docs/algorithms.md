# Algorithms — click-ops protocol and rule matching

Two pieces of the system that the spec mentions but doesn't fully formalize: the **click-op protocol** (how the browser asks the server to mutate the parsed tree) and the **rule-matching algorithm** (how `applyRules` finds and substitutes patterns).

---

## 1. Click-ops protocol

### 1.1 What a click-op is

A **click-op** is a single, declarative tree mutation issued from the browser's tree view. It travels alongside a normal `/api/transform` request:

```http
POST /api/transform
{
  "direction": "xml2emmet",
  "input":     "<ul><li>a</li><li>b</li></ul>",
  "settings":  { "html_mode": true, "show_text": true, "show_attrs": true, "show_attr_values": true },
  "rule_ids":  [],
  "click_ops": [ { "type": "swap", "path": [0, 0] } ],
  "save":      false
}
```

The server parses the input, applies any `rule_ids` first, then applies `click_ops` **in order**, then emits. Click-ops are not stored — they live in the request only.

### 1.2 Path addressing

A `path` is a JSON array of zero-based child indices identifying a single node, walking from the document root down. The empty array `[]` is the document root.

```
<ul>            path = []
  <li>a</li>    path = [0]
  <li>b</li>    path = [1]
  <li>          path = [2]
    <span/>     path = [2, 0]
  </li>
</ul>
```

If a `path` does not resolve to a node (out of range, child of a leaf), the server returns `422` with `{error: "bad_path", message: "...", op_index: N}`.

### 1.3 The six operations

| type     | required fields            | effect                                                                                |
|----------|----------------------------|---------------------------------------------------------------------------------------|
| `swap`   | `path`                     | Swap the node at `path` with its **next** sibling. Error if no next sibling exists.   |
| `rename` | `path`, `with`             | Rename the tag of the node at `path` to `with`. Attrs/children/text preserved.        |
| `wrap`   | `path`, `with`             | Wrap the node at `path` in a new parent with tag `with`. Wrapper has no attrs.        |
| `unwrap` | `path`                     | Replace the node at `path` with its children, in order. Root cannot be unwrapped.     |
| `delete` | `path`                     | Remove the node at `path`. The root cannot be deleted; error if `path == []`.         |
| `move`   | `path`, `to` (`int[]`)     | Move the node at `path` to position `to`. `to` is interpreted **after** the source is removed; the engine handles same-parent index shifting. |

### 1.4 Examples

**Swap two `<li>` siblings:**
```json
{ "type": "swap", "path": [0, 0] }
```

**Rename a tag:**
```json
{ "type": "rename", "path": [0], "with": "section" }
```
Before: `<body><div>...</div></body>` → after: `<body><section>...</section></body>`

**Unwrap a `<div>` wrapper:**
```json
{ "type": "unwrap", "path": [0] }
```
Before: `<body><div><h1/><p/></div></body>` → after: `<body><h1/><p/></body>`

**Wrap an element:**
```json
{ "type": "wrap", "path": [0, 1], "with": "section" }
```

**Delete a node:**
```json
{ "type": "delete", "path": [1, 2] }
```

**Move a node to a new position:**
```json
{ "type": "move", "path": [0], "to": [1, 0] }
```
Move the first child of root into the second child as its first grandchild.

### 1.5 Ordering and error semantics

- Click-ops apply **in order**. Each op sees the tree shape produced by the previous ops.
- If any op fails (bad path, root-delete, etc.), processing stops and the server returns `422` with the offending `op_index` and a description. Already-applied ops in the same request are discarded — the request is atomic.
- Click-ops apply **after** rules. If the user wants the click-op to act before rules, they should issue a transform without rules first.

### 1.6 Validation rules (all server-side)

- `path` must be an array of non-negative integers.
- `with` (for `rename` and `wrap`) must match the `IDENT` regex from the Emmet grammar. Missing `with` returns `422 missing_with`.
- `to` (for `move`) must be an array of non-negative integers. Missing `to` returns `422 missing_to`.
- Unknown `type` values return `422 unknown_op`.
- A `path` that does not resolve to a node returns `422 bad_path`.
- `delete` with `path == []` returns `422 root_delete`. `unwrap` with `path == []` returns `422 unwrap_root`.

All click-op error responses include `details.op_index`.

---

## 2. Rule matching algorithm

### 2.1 Inputs

- A **data tree** `D` (the user's parsed input).
- An ordered list of **rules** `[R1, R2, …]`. Each rule has a `pattern` tree `P` and a `replacement` tree `R`, both produced by `emmetParse`. Both may contain placeholder nodes `$E1`, `$E2`, ….

### 2.2 Output

The transformed data tree `D'`.

### 2.3 Top-level driver (pseudocode)

```
function applyRules(D, rules):
  current = D
  for R in rules:                        # one full pass per rule, in order
    current = applyOneRule(current, R)
  return current

function applyOneRule(D, rule):
  return walk(D, root=true, rule)

# pre-order walk with skip-into-replacement
function walk(node, root, rule):
  bindings = match(rule.pattern, node)
  if bindings != null:
    replacement = substitute(rule.replacement, bindings)
    # Replace node with replacement, but DO NOT recurse into the replacement's
    # top-level structure with this same rule -- prevents infinite loops on
    # rules like  div -> div>div
    return descendIntoChildrenOnly(replacement, rule)
  else:
    node.children = [ walk(c, root=false, rule) for c in node.children ]
    return node

function descendIntoChildrenOnly(replacement, rule):
  replacement.children = [ walk(c, root=false, rule) for c in replacement.children ]
  return replacement
```

### 2.4 `match(pattern, subtree)` → bindings | null

Returns either a `bindings` map (placeholder name → bound subtree) on success, or `null` on failure.

```
function match(pattern, node):
  bindings = {}
  if matchNode(pattern, node, bindings):
    return bindings
  return null

function matchNode(pat, node, bindings):
  if isPlaceholder(pat):                 # e.g. pat.tag == "$E1"
    name = pat.tag
    if name in bindings:
      return treeEqual(bindings[name], node)
    bindings[name] = node
    return true

  # literal element: tag, attrs, children must all match exactly
  if pat.tag != node.tag:                  return false
  if not attrsEqual(pat.attrs, node.attrs):return false
  if pat.text != node.text:                return false
  if length(pat.children) != length(node.children): return false
  for i in 0..length(pat.children)-1:
    if not matchNode(pat.children[i], node.children[i], bindings): return false
  return true
```

**Notes:**
- A pattern element with no qualifiers and no children (e.g. `div`) only matches a node whose own children are also empty. To match "any `div` regardless of children," the pattern must use a placeholder for children: `div>E1` (or, for "any number of children," see §2.7 — not in scope for v1).
- `attrsEqual` compares two attribute maps as multisets of `(name, value)` pairs. Class lists are compared as sets of class names (order-independent).
- `treeEqual` is recursive structural equality with the same `attrsEqual` semantics. Placeholders cannot appear inside a value being compared (they'd already be bound by the time we re-encounter `EN`).

### 2.5 `substitute(replacement, bindings)` → tree

Walks the replacement tree, deep-cloning every literal node, and replacing each placeholder occurrence with a deep clone of the bound subtree.

```
function substitute(node, bindings):
  if isPlaceholder(node):
    bound = bindings[node.tag]
    if bound == null:
      throw "unbound placeholder " + node.tag
    return deepClone(bound)
  return Node {
    tag:      node.tag,
    attrs:    copy(node.attrs),
    text:     node.text,
    children: [ substitute(c, bindings) for c in node.children ]
  }
```

If the replacement references a placeholder that the pattern never bound, that's a **rule-definition error**, surfaced when the rule is saved (the Rules form validates this). At apply-time we throw to fail loud — but the form should make it impossible to reach.

### 2.6 Termination guarantee

The walk is pre-order over the data tree, and after a successful match we recurse only into the replacement's *children*, not its root. So a rule like `div → div>div`:

```
input:  div
pass:   div  matches the rule  → replace with  div>div
        recurse into children of replacement (the inner div)
        inner div matches the rule  → replace with  div>div
        recurse into children of THAT replacement
        ... still keeps replacing.
```

Wait — that still loops. Let me fix the algorithm to be safer:

**Updated rule:** after substituting at a node, mark the *entire replacement subtree* as "rule-applied for R" and skip nodes flagged this way during the rest of this pass. Implementation: track a per-node Set of rule ids that have already been considered at that node within this pass.

```
function walk(node, rule):
  if rule.id in node.appliedRules: return node     # short-circuit
  bindings = match(rule.pattern, node)
  if bindings != null:
    replacement = substitute(rule.replacement, bindings)
    markAllNodes(replacement, rule.id)             # tag every node
    replacement.children = [ walk(c, rule) for c in replacement.children ]
    return replacement
  node.children = [ walk(c, rule) for c in node.children ]
  return node
```

Now `div → div>div` produces exactly one application: the outer `div` becomes `div>div`, both nodes are tagged with rule.id, the recursion descends into the inner `div` but short-circuits immediately. Pass terminates.

The `appliedRules` flag is **per pass** — it's reset between rules in `applyRules`, and reset entirely on the next user-initiated apply.

### 2.7 Out of scope (v1)

- **Variadic placeholders.** A pattern like `div>E1*` to match "any number of children of div" is not supported. Workaround: write multiple rules.
- **Conditional rules.** No "match only if `E1.tag == 'span'`."
- **Bindings across rules.** Each rule has its own fresh `bindings` map.
- **Confluence.** If two rules can both match at the same node, the user-specified rule order wins. We do not detect or resolve overlap.

### 2.8 Worked example

Rule: `E1+E2 → E2+E1`
Input: `h1+p+span` (three siblings under an implicit root)

Pass:
1. Walk root. No match (root isn't a `+`-pattern at this representation). Recurse into children.
2. Child 0 = `h1`. `match(E1+E2, h1)`? The pattern is itself a sibling pair, but `h1` is a single node — no. Recurse into `h1`'s children (none).
3. Same for `p` and `span`.

Result: tree unchanged — patterns are matched against **subtrees rooted at a node**, but `E1+E2` describes a *list of siblings*, not a subtree. To match siblings we extend the pattern semantics:

**Extension for sibling patterns:** if a rule pattern is itself a list of siblings (i.e. the top level has `+` operators), the matcher tries to bind it against contiguous slices of any sibling list in the data tree. So `match(E1+E2, [h1, p, span])` succeeds with `E1=h1, E2=p`, replacement `[p, h1, span]`. Pre-order ensures we attack the leftmost slice first.

This is the only place sibling lists are special-cased. Single-node patterns work as described in §2.4.

---

## 3. How these algorithms relate to existing docs

- **Design** (`design.md`): introduces the transform engine at a user-facing level; does not formalize matching or click-ops.
- **Grammar** (`emmet-grammar.md`): defines what `pattern` and `replacement` strings parse to.
- **Test cases** (`test-cases.md`): exercises these algorithms (Sections A, E, and the click-op cases that should be added under §H when smoke tests come into scope).

If a future change makes one of these docs disagree with another, this file is the authoritative source for the click-op protocol and rule-matching algorithm.
