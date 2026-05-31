# Review: XML2EMMET Frontend SPA

## Overview

Single-page application for converting between XML/HTML and Emmet abbreviation syntax. Built with vanilla ES modules, no bundler, no framework, served over a PHP backend. UI presents four panels: Transform, History, Rules, and Stats. Authentication uses session cookies. The aesthetic is a deliberate green-on-black terminal style implemented via CSS custom properties.

This review covers the JavaScript frontend (`public/js/`). The PHP backend and its test suite are out of scope except where they interact with confirmed frontend bugs.

---

## What Exists

| Module | Role |
|---|---|
| `main.js` | Boot, panel routing, teardown infrastructure |
| `transform.js` | XML↔Emmet convert, tree view, stats bar |
| `history.js` | Paginated conversion history, row expansion |
| `rules.js` | CRUD UI for transform rules |
| `stats.js` | On-demand HTML/CSS stats runner |
| `auth.js` | Login/register tab form |
| `sidebar.js` | Nav rail, active state, username display |
| `tree.js` | Recursive ASCII tree renderer |
| `api.js` | Centralized `_fetch` wrapper, all HTTP calls |

No build step. Modules are loaded directly by the browser as ES modules. No test harness for frontend code.

---

## What's Good

**Architecture discipline.** Each panel is a pure render function that owns its DOM subtree via `innerHTML` + event delegation. No accidental global state leaks across panels. Teardown hooks are wired in `main.js` (even if currently inert — see dead code note below).

**XSS posture is mostly sound.** `escHtml()` is defined and consistently applied in every file that projects server data into `innerHTML` — `transform.js`, `rules.js`, `sidebar.js`, `stats.js` (top_classes path). The `api.js` wrapper returns a uniform `{ ok, status, code, message, details }` shape; callers do not parse raw response bodies.

**`tree.js` uses `textContent`.** The ASCII tree renderer assigns via `textContent`, not `innerHTML`, so tag names and attribute values with HTML metacharacters are safe by construction even without escaping.

**Centralized API layer.** `_fetch` handles network errors, JSON parse failures, and 401 auto-reload in one place. No fetch call is scattered ad-hoc across panel files.

**`auth.js` uses correct autocomplete values.** `current-password` vs `new-password` per mode, and `label[for]` is wired to matching input IDs — not cosmetic, it enables password manager autofill.

**Register graceful degradation.** When registration succeeds but auto-login fails, a human-readable error is surfaced rather than a silent failure or uncaught exception.

**History expand state is preserved across pagination.** `expandedId` survives page flips within the History panel — a small but user-perceptible quality-of-life detail.

**`clearErrors()` before every API call in `transform.js`.** Stale error messages do not accumulate across consecutive convert attempts.

---

## What's Bad

### No in-flight state management

Every async operation — convert, rule save/delete, stats run, history page flip — fires with no visual feedback. Buttons remain enabled and clickable. There is no spinner, no disabled state, no text change. This is not a cosmetic issue: it creates concrete race conditions (see Bugs & Risks).

### Dead teardown infrastructure

`main.js` `showPanel()` calls `panelEl.innerHTML = ''` before invoking the previous panel's teardown, but no panel returns a teardown function — they all return `undefined`. The infrastructure is correctly designed but entirely inert. Any future panel that starts a `setInterval` or ongoing fetch will silently leak it.

### `showStats()` coupling and correctness

`transform.js` `showStats()` is called after every successful convert and always passes hardcoded `kind: 'html'` (line 135) regardless of whether the active mode is `xml`. When converting emmet→xml, the XML output is sent to the stats endpoint as if it were HTML. The backend parses it as HTML, produces incorrect element/depth counts, and the stats bar silently clears on parse error with no indication to the user.

### Error swallowing in `rulesDelete`

`rules.js` lines 54–58: the return value of `await api.rulesDelete(btn.dataset.id)` is discarded. `load()` is called unconditionally regardless of whether the delete succeeded. Network errors, 403, and 404 responses are all treated as success — the UI reloads the list unchanged with no error shown.

### No confirmation before destructive delete

`rulesDelete` executes immediately on button click. One misclick permanently deletes a rule with no confirmation dialog, no undo, no toast acknowledgment.

### Loose equality for ID comparison

`history.js` line 52: `existing.dataset.forId == item.id` compares a `dataset` string against a server-returned integer. `'5' == 5` is `true` via JS type coercion, so it works today. `rules.js` line 46 has the same pattern: `items.find(i => i.id == btn.dataset.id)`. Both should use `===` with explicit `String()` normalization or numeric coercion.

### Stale rules list on panel return

`transform.js` `loadRules()` is called once on panel mount. If the user navigates to Rules, creates or modifies a rule, then returns to Transform, the rules list is stale. There is no invalidation trigger.

### Empty structural placeholder div

`transform.js` lines 40–41: a second `.transform-controls` div is emitted with no content. The grid is `grid-template-columns: 1fr auto 1fr auto 1fr`; the empty div occupies the 4th `auto` column. Because it has no content the column collapses to near-zero — it does not currently push the Tree column as originally suspected — but it is dead markup that will confuse future maintainers and break if content is ever added to it inadvertently.

### Tab switch destroys form input

`auth.js` lines 27–28: clicking the LOGIN or REGISTER tab calls `show()` which does `container.innerHTML = '...'`, immediately wiping any partially-typed username or password. No attempt is made to preserve field values.

### One `escHtml` function copied six times

`escHtml` is a four-line function copy-pasted into `transform.js`, `history.js`, `rules.js`, `stats.js`, `sidebar.js`, and `auth.js`. It is not in a shared utility module. The history.js copy is present but not called in the error path (see XSS finding below).

---

## Bugs & Risks

| Severity | Location | Description |
|---|---|---|
| 🔴 Critical | `history.js` line 9 | **XSS via unescaped server error message.** `container.innerHTML = '<h2>History</h2><p class="error-msg">${res.message}</p>'` — `res.message` is server-controlled and not passed through `escHtml()`. `escHtml` is defined in the same file (line 78) but unused here. A malicious or compromised server response can inject arbitrary HTML. Fix: `escHtml(res.message)`. |
| 🔴 Critical | `transform.js` lines 91–131 | **Race condition on convert buttons.** No in-flight lock. Rapid double-clicks fire two parallel requests. Whichever settles last wins the output textarea and tree column. `showStats()` is fired without `await` and without cancellation — a slow stats response from an earlier convert can overwrite stats from a later one. |
| 🔴 Critical | `transform.js`, `history.js`, `stats.js` | **Stale-container crash after panel switch.** All async callbacks close over the `container` argument, which is the shared `panelEl` DOM node. When `showPanel()` calls `panelEl.innerHTML = ''` and renders a new panel, any in-flight async from the previous panel that resolves after the switch will call methods like `container.querySelector('#xml-input').value` on the new panel's DOM. Those IDs do not exist in the new panel; `querySelector` returns `null`; `.value` assignment throws an uncaught `TypeError`. |
| 🟡 Moderate | `rules.js` lines 54–58 | **Silent swallow of delete errors.** `api.rulesDelete()` return value is discarded; `load()` is called unconditionally. Network errors, 403, and 404 are all treated as success. |
| 🟡 Moderate | `transform.js` line 135 | **Wrong `kind` sent to stats API for XML mode.** Hardcoded `'html'` kind is passed regardless of active convert mode. XML input produces incorrect or zero stats; the stats bar silently clears. |
| 🟡 Moderate | `auth.js` lines 27–28 | **Tab switch wipes partially typed credentials.** Clicking the inactive auth tab triggers a full `innerHTML` re-render with no state preservation. |
| 🟡 Moderate | `tree.js` line 16 | **Unbounded recursion in `nodeToLines()`.** No depth guard, no iterative fallback, no `MAX_DEPTH` clamp. A sufficiently deep or adversarially crafted XML document overflows the JS call stack. The PHP `XmlParser` has no depth limit either. |
| 🟡 Moderate | `stats.js` line 30 | **No in-flight guard on [RUN] button.** Rapid repeated clicks fire multiple concurrent `POST /api/stats` requests. Last response wins the results panel. |
| 🟢 Minor | `history.js` line 52 | **Loose `==` for ID comparison.** `existing.dataset.forId == item.id` relies on JS type coercion. Works today; fragile under refactoring. |
| 🟢 Minor | `rules.js` line 46 | **Same loose `==` pattern.** `items.find(i => i.id == btn.dataset.id)` — same type coercion dependency. |
| 🟢 Minor | `stats.js` line 55 | **Variable shadowing.** `Object.entries(d.depth_histogram).map(([d, c]) => ...)` — destructuring parameter `d` silently shadows the outer `const d = res.data` from line 39. Works correctly by coincidence of scope; triggers linter warnings; maintenance hazard. |
| 🟢 Minor | `main.js` lines 26, 31 | **Teardown infrastructure is dead code.** `currentPanelTeardown` is checked and called, but no panel returns a teardown function. Future panels that start polling or fetching will silently leak. |

**False positive from initial analysis:** The `stats.js` depth histogram injection path was flagged as a latent risk. Inspection of `StatsHandler.php` line 37 shows depth histogram keys are PHP integers cast to strings — always numeric, never containing HTML metacharacters. The server-side contract makes this safe by construction; the risk is theoretical only.

---

## Missing Features

| Priority | Feature |
|---|---|
| High | Loading indicators on all async operations (button disabled + spinner or text change) |
| High | Confirmation dialog or undo mechanism before rule delete |
| High | AbortController cancellation for `showStats()` — cancel on new convert |
| Medium | Copy-to-clipboard on Emmet output and XML output textareas |
| Medium | Keyboard shortcut for convert (Ctrl+Enter in the active textarea) |
| Medium | URL/hash-based routing — refresh always drops back to Transform; deep-linking to a panel is impossible |
| Medium | Panel state persistence — textarea contents are wiped on every panel switch |
| Medium | History search/filter — pagination alone is unusable for finding specific past conversions |
| Medium | History delete / bulk delete — history grows unbounded |
| Low | `save: false` option on Transform — no way to convert without creating a history entry |
| Low | Rules drag-to-reorder for application priority |
| Low | Visual histograms in Stats — the depth data is ideal for a CSS bar chart |
| Low | Keyboard navigation between panels (Tab to sidebar, Enter to activate) |
| Low | Selectable `perPage` in History (10/20/50), persisted to `localStorage` |

---

## Improvement Ideas

**Shared in-flight guard utility.** A higher-order function `withLoading(btn, asyncFn)` that disables the trigger button, changes its text to a loading state, and re-enables on resolution. Apply it to every async event handler across all panels. This eliminates the race conditions and gives the user feedback in one change.

**Lift panel state to a cache in `main.js`.** A `Map<panelName, DOMString>` that saves `panelEl.innerHTML` before each panel switch and restores it on return would preserve textarea contents and partially filled forms without any panel-level refactoring. This also fixes the stale-container crash — in-flight async from the previous panel can check a generation counter before writing to the DOM.

**AbortController for `showStats()`.** Store the controller in module scope. On each new convert, abort the previous stats fetch before firing the next one. This resolves the out-of-order stats issue and removes the silent clear on mismatch.

**Extract `escHtml` into `public/js/utils/html.js`.** Import it everywhere instead of maintaining six copies. This also makes the `history.js` XSS fix obvious — `escHtml` would be a named import at the top of the file.

**Iterative `nodeToLines()` with depth cap.** Replace the recursive implementation with a stack-based loop and add a `MAX_DEPTH = 200` constant. Emit a `'... (truncated)'` leaf when the limit is hit rather than crashing.

**Fix `showStats()` kind selection.** Read the active mode from the same state that drives the convert button. Pass `kind: 'xml'` when in XML mode (or hold the stats call until an HTML round-trip is available).

**`<dialog>` for rule delete confirmation and history detail.** Native `<dialog>` provides focus trapping and Escape-key handling for free, with no custom JS required. Replaces the current no-confirmation delete and avoids implementing a custom modal system.

**Label the tree checkboxes as server-side options.** Either add a "(re-convert to apply)" note next to show-text/show-attrs, or move them into the controls column alongside the convert button so their scope is unambiguous.

**Implement panel teardown contracts.** Have each panel render function return a cleanup object `{ teardown() { ... } }`. Until panels actually need cleanup this is zero-cost, but it makes the existing infrastructure in `main.js` active rather than aspirational.

---

## Test Coverage

No frontend unit tests exist. The test suite is entirely PHP/PHPUnit: `SmokeTest`, `RoundTripTest`, `EmmetParseTest`, `XmlParseTest`, and related classes. `ClickOpsTest.php` appears to be a Playwright-driven browser smoke test hosted in the PHP suite.

The following frontend modules have zero test coverage:

| Module | Risk without tests |
|---|---|
| `escHtml()` (6 copies) | The XSS bug in `history.js` would have been caught by a one-line unit test |
| `tree.js` `nodeToLines()` | Unbounded recursion bug undetectable without a deep-nesting test case |
| `api.js` `_fetch` | Error shape contract has no regression protection |
| `transform.js` convert flow | Race condition and `kind` mismatch invisible to PHP tests |
| `history.js` toggle/expand | Loose `==` type bug invisible without a typed ID fixture |

Recommended starting point: Vitest with jsdom. `escHtml`, `tree.js`, and the `api.js` response-shape normalization are pure functions that can be unit tested with no DOM dependency. The convert and stats race conditions are best covered by integration tests using `msw` to intercept fetch calls with controllable timing.

---

## Verdict

The codebase is deliberate and well-scoped for a single-developer project: the panel-as-render-function architecture is clean, the API layer is properly centralized, and XSS hygiene is almost complete. However, the absence of any in-flight state management is a systemic gap that manifests as three separate confirmed bugs — a race condition on the convert buttons, a race condition on the stats runner, and a crash when an in-flight async resolves after a panel switch. The `history.js` XSS via unescaped `res.message` is the only true injection vector in the codebase and is a one-line fix, but it is a real vulnerability. Taken together — one XSS, two crash-level race conditions, silent error swallowing on destructive delete, and unbounded recursion — the frontend requires targeted fixes before it can be considered production-ready.

**Rating: needs-work.** The issues are well-defined and individually fixable; none require architectural changes. Prioritize: (1) `escHtml(res.message)` in `history.js`, (2) in-flight guard on all async handlers with an AbortController for stats, (3) stale-container crash protection via a generation counter or panel-state cache, (4) error surfacing in `rulesDelete`.
