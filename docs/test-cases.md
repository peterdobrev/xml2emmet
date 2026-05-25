# Test cases catalog

The acceptance test set for the transform engine. Every case here corresponds to one or more golden-file pairs (`tests/fixtures/<id>/input.*` and `tests/fixtures/<id>/expected.*`).

Cases are grouped by which function is under test. Round-trip cases test multiple functions in sequence.

## Conventions

- File ids are `kebab-case` and stable — referenced by code, never renamed.
- Each case states the **input**, the **expected output**, and the **mode/options** under which the assertion holds.
- Where output differs by mode (HTML vs XML, detail toggles), each variant is its own row.
- The Tree column is shown only when illuminating; for round-trip cases it is omitted.

---

## A. `emmetParse` — Emmet → Tree

Inputs that must parse cleanly and produce the stated tree shape.

| id              | input                                       | expected tree                                                     |
|-----------------|---------------------------------------------|-------------------------------------------------------------------|
| ep-elem-bare    | `div`                                       | `div`                                                             |
| ep-class        | `div.box`                                   | `div [class=box]`                                                 |
| ep-class-multi  | `div.a.b.c`                                 | `div [class="a b c"]`                                             |
| ep-id           | `div#main`                                  | `div [id=main]`                                                   |
| ep-id-class-mix | `a.btn.primary#go`                          | `a [class="btn primary" id=go]`                                   |
| ep-attr-empty   | `input[required]`                           | `input [required=""]`                                             |
| ep-attr-kv      | `a[href=/x]`                                | `a [href=/x]`                                                     |
| ep-attr-quoted  | `a[href="/x y"]`                            | `a [href="/x y"]`                                                 |
| ep-attr-multi   | `input[type=text name=q required]`          | `input [type=text name=q required=""]`                            |
| ep-text         | `p{Hello}`                                  | `p text=Hello`                                                    |
| ep-text-spaces  | `p{Hello, world!}`                          | `p text="Hello, world!"`                                          |
| ep-text-escape  | `p{a\\{b\\}c}`                              | `p text="a{b}c"`                                                  |
| ep-child        | `ul>li`                                     | `ul > li`                                                         |
| ep-sibling      | `h1+p`                                      | `h1, p` (siblings)                                                |
| ep-repeat       | `li*3`                                      | `li, li, li`                                                      |
| ep-group        | `(h1+p)*2`                                  | two clones of `(h1, p)` as siblings                               |
| ep-climb        | `section>h1+p^footer`                       | `section>(h1,p), footer` (footer is sibling of section)           |
| ep-climb-twice  | `a>b>c^^d`                                  | `a>b>c, d`                                                        |
| ep-combo        | `ul.l>li.i{x}+li.i{y}`                      | `ul[class=l] > (li[class=i]{x}, li[class=i]{y})`                  |
| ep-whitespace   | `ul > li * 3`                               | same as `ep-repeat` parent                                        |
| ep-placeholder  | `E1+E2`                                     | `$E1, $E2` (rule pattern)                                         |
| ep-placeholder-nested | `div>E1`                              | `div > $E1`                                                       |

### Parse errors (must reject with line/col)

| id              | input               | error contains                              |
|-----------------|---------------------|---------------------------------------------|
| ep-err-empty    | (empty string)      | "expected element"                          |
| ep-err-leading  | `>div`              | "unexpected '>'"                            |
| ep-err-trailing | `div>`              | "expected element after '>'"                |
| ep-err-bracket  | `div[unclosed`      | "expected ']'"                              |
| ep-err-brace    | `div{unclosed`      | "expected '}'"                              |
| ep-err-paren    | `(div`              | "expected ')'"                              |
| ep-err-climb    | `^div`              | "cannot climb above root"                   |
| ep-err-star     | `*3`                | "'*' must follow an element or group"       |
| ep-err-implicit | `.foo`              | "missing tag name"                          |

---

## B. `emmetEmit` — Tree → Emmet

Inputs are trees built directly in code; outputs are Emmet strings.

### B.1 HTML mode (`html_mode=true`)

| id            | tree                                                | options                              | expected output                       |
|---------------|-----------------------------------------------------|--------------------------------------|---------------------------------------|
| eh-bare       | `div`                                               | text/attrs/values all on             | `div`                                 |
| eh-class      | `div [class="box"]`                                 | text/attrs/values on                 | `div.box`                             |
| eh-class-multi| `div [class="a b"]`                                 | text/attrs/values on                 | `div.a.b`                             |
| eh-id         | `div [id=main]`                                     | text/attrs/values on                 | `div#main`                            |
| eh-id-class   | `a [class="btn" id=go]`                             | text/attrs/values on                 | `a.btn#go`                            |
| eh-attr-other | `a [href=/x]`                                       | text/attrs/values on                 | `a[href=/x]`                          |
| eh-attr-quote | `a [href="/x y"]`                                   | text/attrs/values on                 | `a[href="/x y"]`                      |
| eh-text       | `p text=Hi`                                         | text on                              | `p{Hi}`                               |
| eh-no-text    | `p text=Hi`                                         | text **off**                         | `p`                                   |
| eh-no-attrs   | `a [href=/x class=btn]`                             | attrs **off**                        | `a`                                   |
| eh-no-values  | `a [href=/x]`                                       | attrs on, values **off**             | `a[href]`                             |
| eh-child      | `ul > li`                                           | text/attrs/values on                 | `ul>li`                               |
| eh-sibling    | `h1, p`                                             | text/attrs/values on                 | `h1+p`                                |
| eh-repeat     | three identical `li` clones                         | text/attrs/values on                 | `li*3`                                |
| eh-group      | `(h1, p), (h1, p)`                                  | text/attrs/values on                 | `(h1+p)*2`                            |
| eh-climb      | `section > (h1, p), footer`                         | text/attrs/values on                 | `section>h1+p^footer`                 |

### B.2 XML mode (`html_mode=false`)

Same trees as above, but classes/ids stay as bracket attrs and values are always quoted.

| id            | tree                          | expected                                          |
|---------------|-------------------------------|---------------------------------------------------|
| ex-class      | `div [class="box"]`           | `div[class="box"]`                                |
| ex-id         | `div [id="main"]`             | `div[id="main"]`                                  |
| ex-mixed      | `a [href=/x class=btn]`       | `a[href="/x" class="btn"]`                        |

### B.3 Repetition collapsing

The emitter collapses **structurally identical adjacent siblings** into `*N`. Cases:

| id              | tree                                       | expected                  |
|-----------------|--------------------------------------------|---------------------------|
| eh-collapse-3   | three identical `li` siblings              | `li*3`                    |
| eh-no-collapse  | `li.a, li.b`                               | `li.a+li.b`  (different)  |
| eh-collapse-2of3| `li.a, li.a, li.b`                         | `li.a*2+li.b`             |

---

## C. `xmlParse` — XML/HTML → Tree

Inputs are markup strings; outputs are trees. Many of these reuse the same trees as Section B.

### C.1 XML mode

| id            | input                                       | expected tree                |
|---------------|---------------------------------------------|------------------------------|
| xp-elem       | `<div/>`                                    | `div`                        |
| xp-attr       | `<a href="/x"/>`                            | `a [href=/x]`                |
| xp-text       | `<p>Hi</p>`                                 | `p text=Hi`                  |
| xp-children   | `<ul><li/></ul>`                            | `ul > li`                    |
| xp-mixed      | `<p>Hi <b>there</b></p>`                    | `p > (text=Hi, b text=there)`*|
| xp-namespaces | `<x:foo xmlns:x="urn:y"/>`                  | `x:foo` (xmlns attr ignored) |

\* Mixed-content nodes are represented as text-only synthetic children to keep the Node shape uniform.

### C.2 HTML mode (lenient)

| id            | input                                       | expected tree                                  |
|---------------|---------------------------------------------|------------------------------------------------|
| xp-html-class | `<div class="a b">x</div>`                  | `div [class="a b"] text=x`                     |
| xp-html-void  | `<br>`                                      | `br` (no children, no error)                   |
| xp-html-implicit-close | `<ul><li>a<li>b</ul>`              | `ul > (li text=a, li text=b)`                  |

### Parse errors

| id            | input               | error                            |
|---------------|---------------------|----------------------------------|
| xp-err-malformed | `<div><span></div>`  | "tag mismatch" (XML mode)     |
| xp-err-unbalanced | `<<>`              | "malformed XML" (XML mode)    |

---

## D. `xmlEmit` — Tree → XML/HTML

Symmetric to `emmetEmit`. Same trees, same options, but output is markup.

### D.1 HTML mode

| id           | tree                              | options                  | expected output                         |
|--------------|-----------------------------------|--------------------------|------------------------------------------|
| xh-bare      | `div`                             | all on                   | `<div></div>`                            |
| xh-class     | `div [class=box]`                 | all on                   | `<div class="box"></div>`                |
| xh-text      | `p text=Hi`                       | all on                   | `<p>Hi</p>`                              |
| xh-no-text   | `p text=Hi`                       | text off                 | `<p></p>`                                |
| xh-no-attrs  | `a [href=/x]`                     | attrs off                | `<a></a>`                                |
| xh-no-values | `a [href=/x]`                     | attrs on, values off     | `<a href></a>`                           |
| xh-void      | `br`                              | all on                   | `<br/>`                                  |

### D.2 XML mode

| id           | tree                              | expected output                     |
|--------------|-----------------------------------|--------------------------------------|
| xx-bare      | `div`                             | `<div/>`                             |
| xx-attrs     | `a [href=/x class=btn]`           | `<a href="/x" class="btn"/>`         |
| xx-children  | `ul > li`                         | `<ul><li/></ul>`                     |

---

## E. `applyRules`

Each case is `(input tree, [rules…], expected output tree)`.

| id            | rules                                  | input              | expected output       |
|---------------|----------------------------------------|--------------------|-----------------------|
| ar-swap-sib   | `E1+E2 → E2+E1`                        | `h1+p`             | `p+h1`                |
| ar-swap-many  | `E1+E2 → E2+E1` (single pass)          | `a+b+c`            | `b+a+c`*              |
| ar-rename     | `div>E1 → section>E1`                  | `div>ul>li`        | `section>ul>li`       |
| ar-no-match   | `div>E1 → section>E1`                  | `span>p`           | `span>p` (unchanged)  |
| ar-multi-rule | `div>E1 → section>E1`, `E1+E2 → E2+E1` | `div>(h1+p)`       | `section>(p+h1)`      |
| ar-binding    | `E1>E1 → E1` (same placeholder twice)  | `div>div`          | `div`                 |
| ar-binding-no | `E1>E1 → E1`                           | `div>span`         | `div>span` (unchanged — bindings don't unify) |
| ar-no-recurse | `div → div>div`                        | `div`              | `div>div` (one pass — does not infinitely recurse into its own output) |

\* Single pre-order pass: `a+b` matches first → swapped to `b+a`; pass continues past replacement, so `+c` is not re-matched against `a+c`. User can re-click Apply to run again.

### Rule binding semantics (referenced by tests)

- A placeholder `EN` matches **any single subtree**.
- The same `EN` appearing more than once in a pattern must bind to **structurally equal** subtrees (same tag, same attrs, same children — recursive equality).
- A literal element in a pattern (e.g. `div`) matches only nodes with that exact tag. Attributes/children of pattern literals must also match (exact attrs, recursive on children).
- Once a match is found at a node, the matched subtree is substituted with the rendered replacement, and traversal continues into the replacement *without re-applying the same rule at that node* (avoids `div → div>div` looping).

---

## F. `stats`

Fixture documents are stored under `tests/fixtures/stats/`.

| id              | input file        | kind | expected                                                                |
|-----------------|-------------------|------|-------------------------------------------------------------------------|
| st-html-small   | `small.html`      | html | `{elements:7, attributes:5, max_depth:3, top_classes:[…]}`              |
| st-html-deep    | `deep.html`       | html | `max_depth:9`                                                           |
| st-html-classes | `classy.html`     | html | top-3 classes equals `[btn(47), container(31), row(28)]`                |
| st-css-small    | `small.css`       | css  | `top_classes` returns counts of class selectors                         |
| st-css-nested   | `nested.css`      | css  | nested selectors counted at every level (`.a .b` counts both `a` and `b`)|
| st-empty        | `empty.html`      | html | `{elements:0, attributes:0, max_depth:0, top_classes:[]}`               |

---

## G. Round-trip properties (multi-function)

For inputs in the HTML-mode subset:

| id              | property                                                                          |
|-----------------|-----------------------------------------------------------------------------------|
| rt-emmet-stable | `emmetParse(emmetEmit(emmetParse(x)))` equals `emmetParse(x)`                     |
| rt-xml-stable   | `xmlParse(xmlEmit(xmlParse(x)))` equals `xmlParse(x)`                             |
| rt-cross        | `xmlEmit(emmetParse(emmetEmit(xmlParse(x))))` parses to a tree equal to `xmlParse(x)` modulo whitespace |

These are exercised over a corpus of ~10 representative HTML snippets (one for each Section A.5 example, plus the assignment's `<ul><li class="item">…` case).

---

## H. Auth & API smoke (out of scope for this milestone)

Listed for completeness but not implemented per the testing decision in the spec ("engine unit tests only"). Recorded here so a future implementer knows what was deferred:

- register → login → me round-trip
- transform endpoint with `save:true` writes a row
- restore endpoint with each of `data`, `settings`, `both` returns the right keys
- rules CRUD as the owning user; 404 for other users' rules
- session expiry returns 401 on the next call

---

## File layout in the repository

```
tests/
  fixtures/
    emmet-parse/
      ep-class/         input.emmet,   expected.tree.json
      ep-text-escape/   input.emmet,   expected.tree.json
      …
    emmet-emit/
      eh-bare/          input.tree.json, options.json, expected.emmet
      …
    xml-parse/
      …
    xml-emit/
      …
    apply-rules/
      ar-swap-sib/      input.tree.json, rules.json, expected.tree.json
      …
    stats/
      small.html
      classy.html
      …
  EmmetParseTest.php
  EmmetEmitTest.php
  XmlParseTest.php
  XmlEmitTest.php
  ApplyRulesTest.php
  StatsTest.php
  RoundTripTest.php
```

One PHPUnit class per function. Each test method loads its fixture by id, runs the function, and asserts deep equality.
