# Project Status

## What Has Been Done

### Engine Core (`feat/engine-core` — merged)
- Immutable `Node` value object
- Emmet parser: elements, classes, IDs, attributes, text, groups, repetition (`*N`), climb-up (`^`)
- Emmet emitter: HTML and XML modes, sibling collapsing
- XML/HTML parser and emitter with void elements and mixed content
- Rules engine: pattern matching with `E1..En` placeholders, attribute-subset matching, multi-rule sequential application
- Click-ops: swap, wrap, unwrap, delete, move, rename on tree nodes
- Stats: element count, depth, tag histogram, class counts, depth histogram, CSS class counter
- Full PHPUnit test suite covering all of the above

### HTTP API (`feat/http-api`)
- PHP front controller (`public/index.php`) with router, auth gate, JSON responses
- Session-based authentication (register, login, logout, `/api/auth/me`)
- MySQL schema, migrations, PDO factory
- Endpoints: `POST /api/transform`, `GET/POST/PUT/DELETE /api/rules`, `GET /api/history`, `POST /api/stats`
- Transform pipeline: parse → apply rules → apply click-ops → filter → emit
- History auto-save on every transform
- Integration test suite (`AuthHttpTest`, `TransformHttpTest`, `RulesHttpTest`, `HistoryHttpTest`, `StatsHttpTest`, `SmokeHttpTest`) — each spawns a real `php -S` server

### Frontend SPA (`feat/http-api`)
- Single HTML file (`public/app.html`) — no build step, no frameworks
- Retro terminal aesthetic: phosphor green (`#00ff41`) on black, monospace font, `[LABEL]` bracket buttons
- `api.js` — centralised fetch wrapper, all 11 endpoints, 401 auto-reload
- Auth panel — full-screen login/register, tab toggle, inline errors, auto-login after register
- Sidebar — nav items with active state, username display, logout
- **Transform panel**
  - 3-column layout: XML/HTML ↔ arrows ↔ Emmet, with Tree on the right
  - HTML/XML mode toggle
  - `show_text` / `show_attrs` checkboxes controlling tree and output
  - Rule selection: checkboxes loaded from user's saved rules, sent as `rule_ids` on `→`
  - Expand button (`⤢` / `⤡`) on each column for full-width view
  - Inline stats bar at the bottom after every conversion (elements, tags, depth, attrs, top classes)
- **History panel** — paginated table (20/page), expandable rows showing full input/output
- **Rules panel** — create/edit/delete rules (name, pattern Emmet, replacement Emmet), inline field errors
- **Stats panel** — standalone HTML/CSS analyser with full results table

---

## What Has Not Been Done

### Known Gaps
- **Click-ops UI** — the backend supports tree node operations (swap, wrap, unwrap, delete, move, rename) but there is no UI to trigger them. The tree is read-only.
- **Rule application on `←` (emmet2xml)** — rules are only sent when converting XML→Emmet. Converting Emmet→XML ignores selected rules.
- **Round-trip with text nodes** — pasting HTML with whitespace between tags produces `#text` nodes in Emmet output that cannot be parsed back. Workaround: uncheck `text` before converting.
- **CSRF protection** — all state-mutating API calls are unprotected against cross-site request forgery.
- **Rate limiting** — no throttling on auth or transform endpoints.
- **Password reset** — no forgot-password flow.
- **Rule ordering** — rules are applied in creation order; there is no UI to reorder them.
- **`rule_ids` refresh** — the rules list in the transform panel is loaded once on panel render. If you add a rule in the Rules panel and return to Transform, the new rule does not appear until you navigate away and back.

### Out of Scope (by design)
- CSRF protection, rate limiting, password reset — explicitly excluded from the design spec
- Click-ops tree interaction — excluded from the frontend spec
