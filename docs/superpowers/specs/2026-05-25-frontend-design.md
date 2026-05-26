# Frontend SPA — Design

**Date:** 2026-05-25
**Stack:** HTML, plain CSS, vanilla JS (ES modules), no build step, no frameworks
**Served from:** `public/app.html` alongside the existing PHP API front controller

---

## 1. Scope

In scope:
- A single-page application served from `public/app.html`
- Full-screen login/register screen shown when unauthenticated
- Four panels: Transform, History, Rules, Stats
- Sidebar navigation between panels
- All API calls go through a single `api.js` module

Out of scope:
- Click-ops tree interaction (drag, click-to-edit nodes)
- Rule application via `rule_ids` in the transform call
- `save: true` history saving from the transform panel
- CSRF protection, rate limiting, password reset

---

## 2. File structure

```
public/
  app.html              # HTML shell — loads app.css and js/main.js
  app.css               # global styles, layout, theme variables
  js/
    main.js             # entry point: boot, auth check, panel router
    api.js              # all fetch() calls — one function per endpoint
    panels/
      auth.js           # full-screen login/register form
      transform.js      # XML/Emmet fields + tree + convert buttons
      history.js        # paginated history list
      rules.js          # rules CRUD
      stats.js          # stats form + results
    components/
      tree.js           # renders Node tree from API response
      sidebar.js        # nav items, active state, logout button
```

`api.js` is the only file that calls `fetch()`. Every other module imports from it and receives plain objects.

---

## 3. Visual style — Retro terminal

Inspired by phosphor-green CRT terminals and the Fallout pip-boy aesthetic.

### Color palette

```css
--bg:         #0a0a0a   /* page background */
--surface:    #000000   /* panel/input backgrounds */
--border:     #00ff41   /* active borders, text, accents */
--border-dim: #004d12   /* inactive nav items, muted borders */
--text:       #00ff41   /* primary text */
--text-muted: #007020   /* secondary/muted text */
--error:      #ff4141   /* error messages */
```

### Typography
- Font: `'Courier New', Courier, monospace` throughout — UI and code alike
- Base size: 14px
- Labels: uppercase, letter-spacing: 1–2px

### Component conventions
- Borders: `1px solid var(--border)` — no border-radius (0px everywhere)
- Nav items styled as `[LABEL]` with bracket characters in the text
- Active nav item: full green background (`#00ff41`), black text
- Buttons: bordered, no fill by default; hover inverts (green bg, black text)
- Inputs/textareas: black bg, green border, green text, green caret
- Header shows `XML2EMMET v1.0 ▮` with a blinking cursor animation
- No shadows, no gradients, no icons — Unicode only (`→`, `←`, `└`, `│`, `▮`)

---

## 4. Application shell (`app.html`)

Minimal HTML — no content in `<body>` beyond two mount points:

```html
<div id="auth-root"></div>
<div id="app-root" hidden>
  <nav id="sidebar-root"></nav>
  <main id="panel-root"></main>
</div>
```

`main.js` is loaded as `type="module"`. On boot it calls `api.me()`:
- **401** → render auth panel into `#auth-root`, hide `#app-root`
- **200** → render sidebar into `#sidebar-root`, show `#app-root`, render Transform panel into `#panel-root`
- Any other error → show a static "Could not connect. Refresh the page." message in `#auth-root`

---

## 5. Panels

### 5.1 Auth panel (`panels/auth.js`)

Full-screen centered layout. Two toggle buttons at top: `[LOGIN]` / `[REGISTER]`. Only one form visible at a time.

**Login form:** username, password, submit button `[LOGIN]`. On 401 → inline error "Username or password is incorrect." On 200 → hide `#auth-root`, show `#app-root`, render sidebar + transform panel.

**Register form:** username, password, submit `[REGISTER]`. On 409 → inline error "Username already taken." On 201 → auto-login (call login immediately) then boot app.

Inline errors appear below the form in `var(--error)` color. Fields are cleared on tab switch.

### 5.2 Transform panel (`panels/transform.js`)

Three equal-width columns laid out with CSS Grid:

```
[ XML/HTML textarea ]  [ Tree ]  [ Emmet textarea ]
```

Between column 1 and 2: a `→` convert button (xml2emmet).
Between column 2 and 3: a `←` convert button (emmet2xml).

Above the tree column: two checkboxes — `show_text` (default on) and `show_attrs` (default on). These are sent as `settings` in the transform request and control what the tree and output show.

**Convert flow:**
1. User edits XML/HTML textarea and clicks `→`
2. Calls `api.transform({ direction: 'xml2emmet', input, settings })`
3. On success: writes `data.output` into Emmet textarea, calls `tree.render(data.tree)` into tree column
4. On parse error (422 `parse_error`): show error message below the input textarea
5. On other error: show message below the button

The `←` button works symmetrically (direction: `emmet2xml`, reads Emmet textarea, writes XML textarea).

Mode (`xml` / `html`) is a toggle above the XML textarea, defaults to `html`.

### 5.3 History panel (`panels/history.js`)

Calls `api.historyList({ page, perPage: 20 })` on render.

Displays a table: columns — direction, truncated input (50 chars), date. Click a row to expand it inline showing full input and output in `<pre>` blocks. Click again to collapse.

Pagination: `[PREV]` / `[NEXT]` buttons at the bottom. Disabled when at first/last page. Current page shown as `PAGE 2 / 5`.

### 5.4 Rules panel (`panels/rules.js`)

Top: a create/edit form with two fields — `pattern` (Emmet) and `replacement` (Emmet) — and a `[SAVE]` button. On 422 with `details.field` → show error next to the relevant field.

Below: list of existing rules. Each row shows pattern → replacement with `[EDIT]` and `[DELETE]` buttons. `[EDIT]` populates the form fields and changes `[SAVE]` to update mode. `[DELETE]` removes immediately (no confirmation for simplicity).

Calls `api.rulesList()` on render and after every create/update/delete.

### 5.5 Stats panel (`panels/stats.js`)

Two toggle buttons: `[HTML]` / `[CSS]`. A textarea for input. A `[RUN]` button.

**HTML results:** element count, unique tag count, attribute count, max depth, top classes table (class | count), depth histogram table (depth | nodes).

**CSS results:** class count, top classes table (class | count).

Results rendered as plain text tables using monospace alignment. On error: inline error message.

---

## 6. Components

### 6.1 Tree component (`components/tree.js`)

`render(container, node)` — recursively builds an indented text representation of the API's `tree` field using `└`, `│`, and space characters. Each node shows `tag`, attrs as `[key=val]`, and text content if present.

Exported as a single function. No state.

### 6.2 Sidebar component (`components/sidebar.js`)

`render(container, { user, activePanel, onNavigate, onLogout })` — renders the nav list and bottom user/logout section.

Nav items: `[TRANSFORM]`, `[HISTORY]`, `[RULES]`, `[STATS]`. Active item has inverted green/black style. Click calls `onNavigate(panelName)`.

Bottom: username displayed, `[LOGOUT]` button. Logout calls `api.logout()` then reloads the page (simplest way to reset all state).

---

## 7. API module (`api.js`)

All functions return `{ ok: true, data }` or `{ ok: false, status, code, message, details }`. Never throws.

| Function | Method | Endpoint |
|---|---|---|
| `register(username, password)` | POST | `/api/auth/register` |
| `login(username, password)` | POST | `/api/auth/login` |
| `logout()` | POST | `/api/auth/logout` |
| `me()` | GET | `/api/auth/me` |
| `transform(body)` | POST | `/api/transform` |
| `rulesList()` | GET | `/api/rules` |
| `rulesCreate(pattern, replacement)` | POST | `/api/rules` |
| `rulesUpdate(id, pattern, replacement)` | PUT | `/api/rules/{id}` |
| `rulesDelete(id)` | DELETE | `/api/rules/{id}` |
| `historyList(page, perPage)` | GET | `/api/history?page=&per_page=` |
| `stats(kind, input)` | POST | `/api/stats` |

A 401 response from any gated call triggers a page reload (forces re-auth). This is handled centrally inside `api.js`'s shared fetch wrapper.

---

## 8. Routing / panel switching

`main.js` exports `showPanel(name)`. It:
1. Calls `currentPanel.teardown()` if the current panel exposes it (removes event listeners)
2. Clears `#panel-root`
3. Instantiates and calls `panel.render(document.getElementById('panel-root'), context)`
4. Updates sidebar active state via `sidebar.setActive(name)`

No URL hash routing — panel state is in-memory only. Refresh always lands on Transform.

---

## 9. Error handling summary

| Scenario | Behaviour |
|---|---|
| 401 on boot | Show auth panel |
| 401 on any gated call | `location.reload()` |
| 422 `parse_error` | Inline below input textarea |
| 422 `validation_failed` with `details` | Inline next to field |
| Any other API error | Inline below triggering button |
| Network failure | Inline "Could not reach server." |
