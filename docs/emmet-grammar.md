# Emmet grammar (xml2emmet subset)

The Emmet syntax this project supports — formalized as BNF, with token rules, precedence, and worked examples. This is what `emmetParse` accepts and `emmetEmit` produces.

We support the **structural** subset: tags, classes, ids, attributes, text, repetition, grouping, child / sibling / climb-up. We do **not** support implicit tags, numbering (`$`), filters (`|`), or HTML5 boilerplate snippets.

## 1. Tokens (lexical)

```
IDENT       ::= [A-Za-z_][A-Za-z0-9_-]*
NUMBER      ::= [0-9]+
DOT         ::= '.'
HASH        ::= '#'
LBRACK      ::= '['
RBRACK      ::= ']'
EQ          ::= '='
LBRACE      ::= '{'      ; opens text
RBRACE      ::= '}'      ; closes text
LPAREN      ::= '('      ; opens group
RPAREN      ::= ')'      ; closes group
GT          ::= '>'      ; child
PLUS        ::= '+'      ; sibling
STAR        ::= '*'      ; repetition
CARET       ::= '^'      ; climb up one level
DQUOTE      ::= '"'
WS          ::= [ \t\r\n]+   ; ignored everywhere except inside {…} and "…"
```

**Quoted strings** (inside `[attr="..."]`):
```
QSTRING     ::= '"' ( ESC | NOT_DQUOTE_OR_BSLASH )* '"'
ESC         ::= '\\' ( '"' | '\\' )
```

**Text content** (inside `{...}`): everything between the matching `{` and `}` is taken verbatim, with `{`/`}`/`\` escapable as `\{` / `\}` / `\\`. Text bodies do **not** allow nested `{` unless escaped. Whitespace is preserved.

**Placeholders** (only inside rule patterns/replacements, not in regular Emmet input):
```
PLACEHOLDER ::= 'E' [1-9][0-9]*
```
A token that *is* `E` followed by digits is a placeholder. `Em`, `E1x`, `e1` are regular identifiers.

## 2. Grammar (BNF)

```
expr        ::= sibling

sibling     ::= child  ( ( '+' child ) | ( '^' child ) )*
child       ::= primary ( '>' primary )*
primary     ::= atom ( '*' NUMBER )?
atom        ::= '(' expr ')'
              | element

element     ::= ( IDENT | PLACEHOLDER ) qualifier*
qualifier   ::= '.' IDENT
              | '#' IDENT
              | '[' attr-list ']'
              | '{' text '}'

attr-list   ::= attr ( WS+ attr )*
attr        ::= IDENT ( '=' attr-value )?
attr-value  ::= IDENT | NUMBER | QSTRING

text        ::= ( <any char except '{' '}' '\\' > | '\\{' | '\\}' | '\\\\' )*
```

### Precedence (lowest → highest)

| Level | Operator        | Associativity |
|-------|-----------------|---------------|
| 1     | `+`  `^`        | left          |
| 2     | `>`             | left          |
| 3     | `*N`            | postfix       |
| 4     | `()`            | grouping      |
| 5     | `.` `#` `[]` `{}` (qualifiers — bind tightest, to the element) | n/a |

**Reading rule:** `a>b+c` parses as `a>b` then `+c` at the parent level → `(a>b)+c`. Use `()` to override.

### Climb-up `^`

`^` ends the current child context and continues at the parent's sibling level. `a>b+c^d` means: under `a`, siblings `b` and `c`; then climb up out of `a`, sibling `d` at root.

```
a>b+c^d
└── parses as: (a>(b+c)) + d
```

Each `^` climbs exactly one level. `^^` climbs two. Climbing past the root is an error.

## 3. Element semantics

When parsing an `element`:
- The `IDENT` (or `PLACEHOLDER`) becomes the **tag**.
- Each `.foo` qualifier appends `foo` to the element's `class` attribute (space-separated, in source order, deduped).
- Each `#bar` qualifier sets the element's `id` attribute. Last wins if multiple are given.
- Each `[k=v k2 k3="x y"]` sets attributes by name. Attributes without `=` are valueless (empty string). Last wins on duplicate names within one bracket group; multiple bracket groups merge (last wins across groups).
- Each `{text}` sets text content. Multiple `{...}` on one element concatenate.

### HTML mode vs XML mode (affects emit, not parse)

Both modes parse the same grammar. They differ in what `emmetEmit` produces:

- **HTML mode:** the element's `class` attribute emits as `.foo.bar`, `id` as `#baz`, text as `{...}`, other attributes as `[k=v]`. Self-closing tags (`<br/>`, `<img/>`) emit without children syntax.
- **XML mode:** every attribute emits as `[k="v"]` with quoted values. No `.`/`#` shortcuts. Text still uses `{...}`.

`xmlEmit` is symmetric: HTML mode emits `<tag class="..." id="...">`, XML mode emits `<tag k="v" k2="v2">` for everything.

## 4. Repetition `*N`

`element*N` produces `N` siblings, each a *deep clone* of the element. `(group)*N` produces `N` deep clones of the entire group as siblings.

```
li*3              → li + li + li
(li.item>span)*2  → (li.item>span) + (li.item>span)
```

Clones are independent — applying a rewrite rule to one does not affect the others.

## 5. Worked examples (input → tree)

Each example shows the source Emmet, then the resulting tree as nested lists.

### 5.1 Trivial element

```
div
```
```
- div
```

### 5.2 Class and id shorthand

```
div.box#main
```
```
- div  attrs={class:"box", id:"main"}
```

### 5.3 Attributes

```
input[type=text name="full name" required]
```
```
- input  attrs={type:"text", name:"full name", required:""}
```

### 5.4 Text

```
p{Hello, world}
```
```
- p  text="Hello, world"
```

### 5.5 Child

```
ul>li
```
```
- ul
  - li
```

### 5.6 Sibling

```
h1+p
```
```
- h1
- p
```

### 5.7 Repetition

```
ul>li*3
```
```
- ul
  - li
  - li
  - li
```

### 5.8 Grouping

```
(header>h1)+(main>p)
```
```
- header
  - h1
- main
  - p
```

### 5.9 Climb-up

```
section>h1+p^footer
```
```
- section
  - h1
  - p
- footer
```

### 5.10 Combination

```
ul.list>li.item.first{one}+li.item{two}+li.item{three}
```
```
- ul  attrs={class:"list"}
  - li  attrs={class:"item first"}, text="one"
  - li  attrs={class:"item"},        text="two"
  - li  attrs={class:"item"},        text="three"
```

### 5.11 Rule with placeholders

Pattern:
```
E1+E2
```
```
- $E1   (placeholder)
- $E2   (placeholder)
```

Pattern:
```
div>E1
```
```
- div
  - $E1   (placeholder)
```

## 6. What we reject

- Implicit tag inference (writing `.foo` without a tag, or `>span` after a class). Always require an explicit tag at every position. (Standard Emmet would infer `div`/`li`/`span` based on parent context — we don't.)
- Numbering placeholders `$`, `$$`, `@N`.
- Filters `|e`, `|c`, etc.
- The "lorem" snippet generator.
- HTML5 boilerplate keywords like `!`, `html:5`.

If `emmetParse` encounters one of these, it raises a parse error with the offending position.

## 7. Whitespace and case

- All whitespace outside of `{...}` and quoted attribute values is **insignificant** and ignored. `ul > li * 3` parses identically to `ul>li*3`.
- Tag and attribute names are case-sensitive at the parser level — we preserve whatever the user wrote. The HTML emitter may lowercase tags when emitting HTML; the XML emitter preserves case.

## 8. Round-trip property

For any input `x` in the supported subset:
- `emmetEmit(emmetParse(x))` is **canonical** — it normalizes whitespace, deduplicates classes, and quotes attribute values consistently. It does not necessarily equal `x` byte-for-byte but parses to the same tree.
- `emmetParse(emmetEmit(emmetParse(x)))` always equals `emmetParse(x)`. (Idempotent after one round-trip.)

This is the property the test suite checks.
