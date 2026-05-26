# Frontend SPA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a vanilla JS single-page application in `public/` that consumes the existing JSON API, with a retro phosphor-green terminal aesthetic.

**Architecture:** `app.html` is a minimal shell with two mount points (`#auth-root`, `#app-root`). `main.js` boots by calling `/api/auth/me` and renders either the auth panel or the sidebar+panels. `api.js` owns all `fetch()` calls. Each panel is a JS module that renders into a container element and optionally exposes `teardown()`.

**Tech Stack:** HTML5, plain CSS (custom properties), vanilla JS ES modules (`type="module"`), no build step, no frameworks, PHP dev server (`php -S`).

---

## Reference

- Spec: `docs/superpowers/specs/2026-05-25-frontend-design.md`
- API response shapes: `src/Http/Handlers/` (read before each task)
- Running dev server: `XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret php -S 127.0.0.1:8080 -t public`

---

## File map

| File | Responsibility |
|------|---------------|
| `public/app.html` | HTML shell — mount points, loads `app.css` and `js/main.js` |
| `public/app.css` | Global styles, CSS vars, layout, retro theme |
| `public/js/api.js` | All `fetch()` calls — returns `{ok,data}` or `{ok:false,...}` |
| `public/js/main.js` | Boot, auth check, `showPanel(name)` router |
| `public/js/components/sidebar.js` | Nav items, active state, logout |
| `public/js/components/tree.js` | Renders Node tree from API `tree` field |
| `public/js/panels/auth.js` | Full-screen login/register |
| `public/js/panels/transform.js` | 3-col XML/tree/Emmet with convert buttons |
| `public/js/panels/history.js` | Paginated history list |
| `public/js/panels/rules.js` | Rules CRUD |
| `public/js/panels/stats.js` | Stats form + results |

---

## Task 1: HTML shell + CSS foundation

**Files:**
- Create: `public/app.html`
- Create: `public/app.css`

- [ ] **Step 1: Create `public/app.html`**

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>XML2EMMET</title>
  <link rel="stylesheet" href="app.css">
</head>
<body>
  <div id="auth-root"></div>
  <div id="app-root" hidden>
    <nav id="sidebar-root"></nav>
    <main id="panel-root"></main>
  </div>
  <script type="module" src="js/main.js"></script>
</body>
</html>
```

- [ ] **Step 2: Create `public/app.css`**

```css
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:         #0a0a0a;
  --surface:    #000000;
  --border:     #00ff41;
  --border-dim: #004d12;
  --text:       #00ff41;
  --text-muted: #007020;
  --error:      #ff4141;
  --font:       'Courier New', Courier, monospace;
}

html, body {
  height: 100%;
  background: var(--bg);
  color: var(--text);
  font-family: var(--font);
  font-size: 14px;
}

/* App shell layout */
#app-root {
  display: flex;
  height: 100vh;
}

#sidebar-root {
  width: 200px;
  min-width: 200px;
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  padding: 16px 0;
}

#panel-root {
  flex: 1;
  overflow-y: auto;
  padding: 24px;
}

/* Auth root fills screen */
#auth-root {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* Buttons */
button {
  font-family: var(--font);
  font-size: 14px;
  background: var(--surface);
  color: var(--text);
  border: 1px solid var(--border);
  padding: 6px 16px;
  cursor: pointer;
  text-transform: uppercase;
  letter-spacing: 1px;
  border-radius: 0;
}
button:hover { background: var(--border); color: var(--surface); }
button:disabled { border-color: var(--border-dim); color: var(--border-dim); cursor: not-allowed; }
button:disabled:hover { background: var(--surface); color: var(--border-dim); }

/* Inputs + textareas */
input, textarea {
  font-family: var(--font);
  font-size: 13px;
  background: var(--surface);
  color: var(--text);
  border: 1px solid var(--border);
  padding: 8px;
  border-radius: 0;
  caret-color: var(--border);
  outline: none;
  resize: vertical;
}
input:focus, textarea:focus { border-color: var(--text); }
input::placeholder, textarea::placeholder { color: var(--text-muted); }

/* Labels */
label {
  display: block;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-size: 12px;
  color: var(--text-muted);
  margin-bottom: 4px;
}

/* Error text */
.error-msg {
  color: var(--error);
  font-size: 12px;
  margin-top: 4px;
  min-height: 16px;
}

/* Blinking cursor */
@keyframes blink { 0%,100% { opacity: 1; } 50% { opacity: 0; } }
.cursor { animation: blink 1s step-end infinite; }

/* Toggle button group */
.toggle-group { display: flex; gap: 0; }
.toggle-group button { border-right-width: 0; }
.toggle-group button:last-child { border-right-width: 1px; }
.toggle-group button.active { background: var(--border); color: var(--surface); }

/* Table */
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--border-dim); }
th { color: var(--text-muted); text-transform: uppercase; font-size: 11px; letter-spacing: 1px; }
tr:hover td { background: #0d1a0d; cursor: pointer; }

/* Pre blocks */
pre {
  background: var(--surface);
  border: 1px solid var(--border-dim);
  padding: 10px;
  overflow-x: auto;
  font-size: 12px;
  white-space: pre-wrap;
  word-break: break-all;
}

/* Section heading */
h2 {
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 2px;
  margin-bottom: 20px;
  border-bottom: 1px solid var(--border-dim);
  padding-bottom: 8px;
}
```

- [ ] **Step 3: Start dev server and verify the page loads**

```bash
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080/app.html` — expect a black page with no errors in the browser console.

- [ ] **Step 4: Commit**

```bash
git add public/app.html public/app.css
git commit -m "feat: HTML shell and CSS foundation"
```

---

## Task 2: API module (`api.js`)

**Files:**
- Create: `public/js/api.js`

All functions return `{ ok: true, data }` on success or `{ ok: false, status, code, message, details }` on failure. A 401 from any gated call triggers `location.reload()` — this is handled in the shared `_fetch` wrapper.

- [ ] **Step 1: Create `public/js/api.js`**

```js
const BASE = '';

async function _fetch(method, path, body) {
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
  };
  if (body !== undefined) opts.body = JSON.stringify(body);
  let res;
  try {
    res = await fetch(BASE + path, opts);
  } catch {
    return { ok: false, status: 0, code: 'network_error', message: 'Could not reach server.', details: {} };
  }
  let json;
  try { json = await res.json(); } catch { json = {}; }
  if (!res.ok) {
    if (res.status === 401 && path !== '/api/auth/login' && path !== '/api/auth/register') {
      location.reload();
      return { ok: false, status: 401, code: 'unauthenticated', message: 'Session expired.', details: {} };
    }
    return { ok: false, status: res.status, code: json.error ?? 'error', message: json.message ?? 'Unknown error.', details: json.details ?? {} };
  }
  return { ok: true, data: json };
}

export const register = (username, password) =>
  _fetch('POST', '/api/auth/register', { username, password });

export const login = (username, password) =>
  _fetch('POST', '/api/auth/login', { username, password });

export const logout = () =>
  _fetch('POST', '/api/auth/logout');

export const me = () =>
  _fetch('GET', '/api/auth/me');

export const transform = (body) =>
  _fetch('POST', '/api/transform', body);

export const rulesList = () =>
  _fetch('GET', '/api/rules');

export const rulesCreate = (name, pattern, replacement) =>
  _fetch('POST', '/api/rules', { name, pattern, replacement });

export const rulesUpdate = (id, name, pattern, replacement) =>
  _fetch('PUT', `/api/rules/${id}`, { name, pattern, replacement });

export const rulesDelete = (id) =>
  _fetch('DELETE', `/api/rules/${id}`);

export const historyList = (page, perPage) =>
  _fetch('GET', `/api/history?page=${page}&per_page=${perPage}`);

export const stats = (kind, input) =>
  _fetch('POST', '/api/stats', { kind, input });
```

- [ ] **Step 2: Verify no syntax errors**

Open `http://127.0.0.1:8080/app.html`, open DevTools console, run:
```js
import('/js/api.js').then(m => console.log(Object.keys(m)))
```
Expected output: `['register', 'login', 'logout', 'me', 'transform', 'rulesList', 'rulesCreate', 'rulesUpdate', 'rulesDelete', 'historyList', 'stats']`

- [ ] **Step 3: Commit**

```bash
git add public/js/api.js
git commit -m "feat: api.js module with all endpoint wrappers"
```

---

## Task 3: Tree component (`components/tree.js`)

**Files:**
- Create: `public/js/components/tree.js`

The API returns `tree` as `{ tag, attrs, text, children }`. This component renders it as an indented text tree using box-drawing characters.

- [ ] **Step 1: Create `public/js/components/tree.js`**

```js
function nodeToLines(node, prefix, isLast) {
  const connector = isLast ? '└─ ' : '├─ ';
  let label = node.tag;
  if (node.attrs && Object.keys(node.attrs).length > 0) {
    const attrStr = Object.entries(node.attrs)
      .map(([k, v]) => v ? `${k}="${v}"` : k)
      .join(' ');
    label += ` [${attrStr}]`;
  }
  if (node.text) label += ` {${node.text}}`;

  const lines = [prefix + connector + label];
  const childPrefix = prefix + (isLast ? '   ' : '│  ');
  const children = node.children ?? [];
  children.forEach((child, i) => {
    lines.push(...nodeToLines(child, childPrefix, i === children.length - 1));
  });
  return lines;
}

export function render(container, node) {
  if (!node) { container.textContent = ''; return; }
  const lines = nodeToLines(node, '', true);
  container.textContent = lines.join('\n');
}
```

- [ ] **Step 2: Commit**

```bash
git add public/js/components/tree.js
git commit -m "feat: tree component renders Node tree with box-drawing chars"
```

---

## Task 4: Sidebar component (`components/sidebar.js`)

**Files:**
- Create: `public/js/components/sidebar.js`

- [ ] **Step 1: Add sidebar CSS to `public/app.css`**

Open `public/app.css` and append:

```css
/* Sidebar */
.sidebar-header {
  padding: 0 16px 16px;
  border-bottom: 1px solid var(--border-dim);
  margin-bottom: 16px;
  font-size: 13px;
  letter-spacing: 2px;
}

.sidebar-nav {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 0 8px;
}

.sidebar-nav button {
  text-align: left;
  border-color: var(--border-dim);
  color: var(--text-muted);
  padding: 8px 10px;
  width: 100%;
}
.sidebar-nav button:hover { border-color: var(--border); color: var(--text); background: var(--surface); }
.sidebar-nav button.active { background: var(--border); color: var(--surface); border-color: var(--border); }

.sidebar-footer {
  padding: 16px 8px 0;
  border-top: 1px solid var(--border-dim);
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.sidebar-username {
  font-size: 12px;
  color: var(--text-muted);
  padding: 0 8px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
```

- [ ] **Step 2: Create `public/js/components/sidebar.js`**

```js
const PANELS = [
  { id: 'transform', label: '[TRANSFORM]' },
  { id: 'history',   label: '[HISTORY]'   },
  { id: 'rules',     label: '[RULES]'     },
  { id: 'stats',     label: '[STATS]'     },
];

export function render(container, { user, activePanel, onNavigate, onLogout }) {
  container.innerHTML = `
    <div class="sidebar-header">XML2EMMET v1.0 <span class="cursor">▮</span></div>
    <div class="sidebar-nav">
      ${PANELS.map(p => `
        <button data-panel="${p.id}" class="${p.id === activePanel ? 'active' : ''}">${p.label}</button>
      `).join('')}
    </div>
    <div class="sidebar-footer">
      <div class="sidebar-username">${escHtml(user.username)}</div>
      <button id="logout-btn">[LOGOUT]</button>
    </div>
  `;
  container.querySelectorAll('.sidebar-nav button').forEach(btn => {
    btn.addEventListener('click', () => onNavigate(btn.dataset.panel));
  });
  container.querySelector('#logout-btn').addEventListener('click', onLogout);
}

export function setActive(container, panelId) {
  container.querySelectorAll('.sidebar-nav button').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.panel === panelId);
  });
}

function escHtml(str) {
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
```

- [ ] **Step 3: Commit**

```bash
git add public/app.css public/js/components/sidebar.js
git commit -m "feat: sidebar component with nav items and logout"
```

---

## Task 5: Main entry point (`main.js`)

**Files:**
- Create: `public/js/main.js`

- [ ] **Step 1: Create `public/js/main.js`**

```js
import * as api from './api.js';
import { render as renderSidebar, setActive as setSidebarActive } from './components/sidebar.js';
import { render as renderAuth } from './panels/auth.js';
import { render as renderTransform } from './panels/transform.js';
import { render as renderHistory } from './panels/history.js';
import { render as renderRules } from './panels/rules.js';
import { render as renderStats } from './panels/stats.js';

const authRoot   = document.getElementById('auth-root');
const appRoot    = document.getElementById('app-root');
const sidebarEl  = document.getElementById('sidebar-root');
const panelEl    = document.getElementById('panel-root');

let currentUser = null;
let currentPanelTeardown = null;
let currentPanelId = 'transform';

const panelRenderers = {
  transform: renderTransform,
  history:   renderHistory,
  rules:     renderRules,
  stats:     renderStats,
};

export function showPanel(name) {
  if (currentPanelTeardown) { currentPanelTeardown(); currentPanelTeardown = null; }
  panelEl.innerHTML = '';
  currentPanelId = name;
  setSidebarActive(sidebarEl, name);
  const result = panelRenderers[name](panelEl, { user: currentUser, api });
  if (result && typeof result.teardown === 'function') currentPanelTeardown = result.teardown;
}

function bootApp(user) {
  currentUser = user;
  authRoot.innerHTML = '';
  appRoot.removeAttribute('hidden');
  renderSidebar(sidebarEl, {
    user,
    activePanel: 'transform',
    onNavigate: (panel) => showPanel(panel),
    onLogout: async () => { await api.logout(); location.reload(); },
  });
  showPanel('transform');
}

async function boot() {
  const result = await api.me();
  if (result.ok) {
    bootApp(result.data.user);
  } else if (result.status === 401) {
    renderAuth(authRoot, { api, onSuccess: bootApp });
  } else {
    authRoot.innerHTML = `<p style="color:var(--error)">Could not connect. Refresh the page.</p>`;
  }
}

boot();
```

- [ ] **Step 2: Create stub files so imports don't error**

Create `public/js/panels/auth.js`:
```js
export function render(container, ctx) { container.textContent = 'auth panel'; }
```

Create `public/js/panels/transform.js`:
```js
export function render(container, ctx) { container.textContent = 'transform panel'; }
```

Create `public/js/panels/history.js`:
```js
export function render(container, ctx) { container.textContent = 'history panel'; }
```

Create `public/js/panels/rules.js`:
```js
export function render(container, ctx) { container.textContent = 'rules panel'; }
```

Create `public/js/panels/stats.js`:
```js
export function render(container, ctx) { container.textContent = 'stats panel'; }
```

- [ ] **Step 3: Verify boot flow in browser**

Open `http://127.0.0.1:8080/app.html`. If not logged in, expect the page to show "auth panel" stub text (since auth panel is a stub). If already logged in, expect to see sidebar + "transform panel" stub. No console errors.

- [ ] **Step 4: Commit**

```bash
git add public/js/main.js public/js/panels/auth.js public/js/panels/transform.js public/js/panels/history.js public/js/panels/rules.js public/js/panels/stats.js
git commit -m "feat: main.js boot, panel router, panel stubs"
```

---

## Task 6: Auth panel (`panels/auth.js`)

**Files:**
- Modify: `public/js/panels/auth.js`
- Modify: `public/app.css` (auth-specific styles)

API shapes:
- `POST /api/auth/register` → 201 `{ user: { id, username } }` or 409 `{ error: 'conflict' }`
- `POST /api/auth/login` → 200 `{ user: { id, username } }` or 401

- [ ] **Step 1: Add auth CSS to `public/app.css`**

Append to `public/app.css`:
```css
/* Auth panel */
.auth-box {
  border: 1px solid var(--border);
  padding: 40px;
  width: 360px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.auth-title {
  font-size: 18px;
  text-transform: uppercase;
  letter-spacing: 3px;
  text-align: center;
}

.auth-tabs {
  display: flex;
}
.auth-tabs button {
  flex: 1;
}

.auth-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.auth-form input {
  width: 100%;
}

.auth-form button[type="submit"] {
  width: 100%;
  padding: 10px;
}
```

- [ ] **Step 2: Replace `public/js/panels/auth.js`**

```js
export function render(container, { api, onSuccess }) {
  let mode = 'login';

  function show() {
    container.innerHTML = `
      <div class="auth-box">
        <div class="auth-title">XML2EMMET <span class="cursor">▮</span></div>
        <div class="auth-tabs toggle-group">
          <button id="tab-login" class="${mode === 'login' ? 'active' : ''}">[LOGIN]</button>
          <button id="tab-register" class="${mode === 'register' ? 'active' : ''}">[REGISTER]</button>
        </div>
        <form class="auth-form" id="auth-form">
          <div>
            <label for="auth-username">Username</label>
            <input id="auth-username" type="text" name="username" autocomplete="username" placeholder="username">
          </div>
          <div>
            <label for="auth-password">Password</label>
            <input id="auth-password" type="password" name="password" autocomplete="${mode === 'login' ? 'current-password' : 'new-password'}" placeholder="password">
          </div>
          <div class="error-msg" id="auth-error"></div>
          <button type="submit">[${mode === 'login' ? 'LOGIN' : 'REGISTER'}]</button>
        </form>
      </div>
    `;

    container.querySelector('#tab-login').addEventListener('click', () => { mode = 'login'; show(); });
    container.querySelector('#tab-register').addEventListener('click', () => { mode = 'register'; show(); });
    container.querySelector('#auth-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const username = container.querySelector('#auth-username').value.trim();
      const password = container.querySelector('#auth-password').value;
      const errEl = container.querySelector('#auth-error');
      errEl.textContent = '';

      if (mode === 'login') {
        const res = await api.login(username, password);
        if (res.ok) { onSuccess(res.data.user); return; }
        errEl.textContent = res.status === 401
          ? 'Username or password is incorrect.'
          : res.message;
      } else {
        const res = await api.register(username, password);
        if (res.ok) {
          const loginRes = await api.login(username, password);
          if (loginRes.ok) { onSuccess(loginRes.data.user); return; }
          errEl.textContent = 'Registered but login failed. Please try logging in.';
          return;
        }
        errEl.textContent = res.status === 409
          ? 'Username already taken.'
          : (res.details?.username ?? res.details?.password ?? res.message);
      }
    });
  }

  show();
}
```

- [ ] **Step 3: Test in browser**

Open `http://127.0.0.1:8080/app.html` while logged out. Verify:
- Login/register tab toggle works, fields clear on switch
- Bad login shows error inline
- Registering a duplicate username shows "Username already taken."
- Successful login shows the sidebar + "transform panel" stub

- [ ] **Step 4: Commit**

```bash
git add public/app.css public/js/panels/auth.js
git commit -m "feat: auth panel with login/register and inline errors"
```

---

## Task 7: Transform panel (`panels/transform.js`)

**Files:**
- Modify: `public/js/panels/transform.js`
- Modify: `public/app.css`

API: `POST /api/transform` body: `{ direction, input, settings: { mode, show_text, show_attrs } }`
Response: `{ output, tree: { tag, attrs, text, children }, saved_id }`

- [ ] **Step 1: Add transform CSS to `public/app.css`**

Append to `public/app.css`:
```css
/* Transform panel */
.transform-grid {
  display: grid;
  grid-template-columns: 1fr auto 1fr auto 1fr;
  gap: 0;
  height: calc(100vh - 120px);
  min-height: 400px;
}

.transform-col {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--border-dim);
  padding: 10px;
  gap: 8px;
  min-width: 0;
}

.transform-col textarea {
  flex: 1;
  width: 100%;
  resize: none;
}

.transform-tree {
  flex: 1;
  overflow-y: auto;
  font-size: 12px;
  white-space: pre;
  color: var(--text-muted);
  border: 1px solid var(--border-dim);
  padding: 8px;
  background: var(--surface);
}

.transform-controls {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 0 8px;
}

.transform-controls button {
  font-size: 18px;
  padding: 8px 12px;
  letter-spacing: 0;
}

.transform-col-header {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.tree-checkboxes {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: var(--text-muted);
}

.tree-checkboxes label {
  display: flex;
  align-items: center;
  gap: 4px;
  text-transform: none;
  letter-spacing: 0;
  font-size: 12px;
  color: var(--text-muted);
  cursor: pointer;
  margin: 0;
}

.tree-checkboxes input[type="checkbox"] {
  width: auto;
  padding: 0;
  border: 1px solid var(--border-dim);
  background: var(--surface);
  accent-color: var(--border);
}
```

- [ ] **Step 2: Replace `public/js/panels/transform.js`**

```js
import { render as renderTree } from '../components/tree.js';

export function render(container, { api }) {
  container.innerHTML = `
    <h2>Transform</h2>
    <div class="transform-grid">
      <div class="transform-col">
        <div class="transform-col-header">
          <label>XML / HTML</label>
          <div class="toggle-group" id="mode-toggle">
            <button data-mode="html" class="active">[HTML]</button>
            <button data-mode="xml">[XML]</button>
          </div>
        </div>
        <textarea id="xml-input" placeholder="Paste XML or HTML here..."></textarea>
        <div class="error-msg" id="xml-error"></div>
      </div>

      <div class="transform-controls">
        <button id="btn-to-emmet" title="Convert to Emmet">→</button>
        <button id="btn-to-xml" title="Convert to XML/HTML">←</button>
        <div class="error-msg" id="convert-error"></div>
      </div>

      <div class="transform-col">
        <div class="transform-col-header">
          <label>Tree</label>
          <div class="tree-checkboxes">
            <label><input type="checkbox" id="show-text" checked> text</label>
            <label><input type="checkbox" id="show-attrs" checked> attrs</label>
          </div>
        </div>
        <div class="transform-tree" id="tree-output"></div>
      </div>

      <div class="transform-controls">
      </div>

      <div class="transform-col">
        <label>Emmet</label>
        <textarea id="emmet-input" placeholder="Paste Emmet here..."></textarea>
        <div class="error-msg" id="emmet-error"></div>
      </div>
    </div>
  `;

  let mode = 'html';

  container.querySelectorAll('#mode-toggle button').forEach(btn => {
    btn.addEventListener('click', () => {
      mode = btn.dataset.mode;
      container.querySelectorAll('#mode-toggle button').forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
    });
  });

  container.querySelector('#btn-to-emmet').addEventListener('click', async () => {
    clearErrors();
    const input = container.querySelector('#xml-input').value;
    const showText  = container.querySelector('#show-text').checked;
    const showAttrs = container.querySelector('#show-attrs').checked;
    const res = await api.transform({
      direction: 'xml2emmet',
      input,
      settings: { mode, show_text: showText, show_attrs: showAttrs },
    });
    if (res.ok) {
      container.querySelector('#emmet-input').value = res.data.output;
      renderTree(container.querySelector('#tree-output'), res.data.tree);
    } else if (res.code === 'parse_error') {
      container.querySelector('#xml-error').textContent = res.message;
    } else {
      container.querySelector('#convert-error').textContent = res.message;
    }
  });

  container.querySelector('#btn-to-xml').addEventListener('click', async () => {
    clearErrors();
    const input = container.querySelector('#emmet-input').value;
    const res = await api.transform({
      direction: 'emmet2xml',
      input,
      settings: { mode },
    });
    if (res.ok) {
      container.querySelector('#xml-input').value = res.data.output;
      renderTree(container.querySelector('#tree-output'), res.data.tree);
    } else if (res.code === 'parse_error') {
      container.querySelector('#emmet-error').textContent = res.message;
    } else {
      container.querySelector('#convert-error').textContent = res.message;
    }
  });

  function clearErrors() {
    ['#xml-error', '#emmet-error', '#convert-error'].forEach(sel => {
      container.querySelector(sel).textContent = '';
    });
  }
}
```

- [ ] **Step 3: Test in browser**

Log in, navigate to Transform panel. Verify:
- Three columns render: XML textarea | tree | Emmet textarea
- Clicking `→` with valid XML populates Emmet field and tree
- Clicking `←` with valid Emmet populates XML field and tree
- Invalid input shows parse error below the relevant textarea
- HTML/XML mode toggle works
- `show_text` / `show_attrs` checkboxes affect the tree output

- [ ] **Step 4: Commit**

```bash
git add public/app.css public/js/panels/transform.js
git commit -m "feat: transform panel with 3-col layout, convert buttons, tree"
```

---

## Task 8: History panel (`panels/history.js`)

**Files:**
- Modify: `public/js/panels/history.js`
- Modify: `public/app.css`

API: `GET /api/history?page=N&per_page=20`
Response: `{ items: [{ id, direction, input, output, settings, created_at }], page, per_page, total }`

- [ ] **Step 1: Add history CSS to `public/app.css`**

Append to `public/app.css`:
```css
/* History panel */
.history-expanded td { background: #050f05; }
.history-detail { padding: 12px 10px; border-bottom: 1px solid var(--border-dim); }
.history-detail pre { margin-top: 6px; }
.history-detail .detail-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
.pagination { display: flex; align-items: center; gap: 12px; margin-top: 16px; }
.pagination span { color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
```

- [ ] **Step 2: Replace `public/js/panels/history.js`**

```js
export function render(container, { api }) {
  let page = 1;
  const perPage = 20;
  let expandedId = null;

  async function load() {
    const res = await api.historyList(page, perPage);
    if (!res.ok) {
      container.innerHTML = `<h2>History</h2><p class="error-msg">${res.message}</p>`;
      return;
    }
    const { items, total } = res.data;
    const totalPages = Math.max(1, Math.ceil(total / perPage));

    container.innerHTML = `
      <h2>History</h2>
      <table>
        <thead>
          <tr><th>Direction</th><th>Input</th><th>Date</th></tr>
        </thead>
        <tbody id="history-tbody"></tbody>
      </table>
      <div class="pagination">
        <button id="prev-btn" ${page <= 1 ? 'disabled' : ''}>[PREV]</button>
        <span>PAGE ${page} / ${totalPages}</span>
        <button id="next-btn" ${page >= totalPages ? 'disabled' : ''}>[NEXT]</button>
      </div>
    `;

    const tbody = container.querySelector('#history-tbody');
    items.forEach(item => {
      const tr = document.createElement('tr');
      tr.dataset.id = item.id;
      const truncated = (item.input ?? '').slice(0, 50).replace(/\n/g, ' ');
      const date = new Date(item.created_at).toLocaleString();
      tr.innerHTML = `
        <td>${escHtml(item.direction)}</td>
        <td>${escHtml(truncated)}${item.input?.length > 50 ? '…' : ''}</td>
        <td>${escHtml(date)}</td>
      `;
      tr.addEventListener('click', () => toggleExpand(tbody, item, tr));
      tbody.appendChild(tr);
      if (expandedId === item.id) renderDetail(tbody, item, tr);
    });

    container.querySelector('#prev-btn').addEventListener('click', () => { page--; load(); });
    container.querySelector('#next-btn').addEventListener('click', () => { page++; load(); });
  }

  function toggleExpand(tbody, item, tr) {
    const existing = tbody.querySelector('.history-detail-row');
    if (existing && existing.dataset.forId == item.id) {
      existing.remove();
      expandedId = null;
      return;
    }
    if (existing) existing.remove();
    expandedId = item.id;
    renderDetail(tbody, item, tr);
  }

  function renderDetail(tbody, item, tr) {
    const detailRow = document.createElement('tr');
    detailRow.className = 'history-detail-row';
    detailRow.dataset.forId = item.id;
    detailRow.innerHTML = `
      <td colspan="3" class="history-detail">
        <div class="detail-label">Input</div><pre>${escHtml(item.input ?? '')}</pre>
        <div class="detail-label" style="margin-top:10px">Output</div><pre>${escHtml(item.output ?? '')}</pre>
      </td>
    `;
    tr.after(detailRow);
  }

  load();
}

function escHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
```

- [ ] **Step 3: Test in browser**

Navigate to History panel. Verify:
- Table shows history rows with direction, truncated input, date
- Clicking a row expands to show full input/output; clicking again collapses
- PREV/NEXT pagination buttons work and are disabled at boundaries
- Page indicator shows correct current/total

- [ ] **Step 4: Commit**

```bash
git add public/app.css public/js/panels/history.js
git commit -m "feat: history panel with pagination and expandable rows"
```

---

## Task 9: Rules panel (`panels/rules.js`)

**Files:**
- Modify: `public/js/panels/rules.js`
- Modify: `public/app.css`

API shapes:
- `GET /api/rules` → `{ items: [{ id, name, pattern_emmet, replacement_emmet, created_at, updated_at }] }`
- `POST /api/rules` body: `{ name, pattern, replacement }` → `{ id }`
- `PUT /api/rules/{id}` body: `{ name, pattern, replacement }` → `{ ok: true }`
- `DELETE /api/rules/{id}` → `{ ok: true }`
- On 422 parse_error: `{ details: { field: 'pattern'|'replacement' } }`

- [ ] **Step 1: Add rules CSS to `public/app.css`**

Append to `public/app.css`:
```css
/* Rules panel */
.rules-form {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 24px;
  border: 1px solid var(--border-dim);
  padding: 16px;
}

.rules-form-row {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.rules-form-actions {
  grid-column: 1 / -1;
  display: flex;
  gap: 8px;
  align-items: flex-start;
}

.rules-form input {
  width: 100%;
}

.rules-list-row td { vertical-align: middle; }
.rules-list-row .rule-actions { display: flex; gap: 6px; white-space: nowrap; }
```

- [ ] **Step 2: Replace `public/js/panels/rules.js`**

```js
export function render(container, { api }) {
  let editingId = null;

  function setForm(name, pattern, replacement) {
    container.querySelector('#rule-name').value = name;
    container.querySelector('#rule-pattern').value = pattern;
    container.querySelector('#rule-replacement').value = replacement;
    container.querySelector('#save-btn').textContent = editingId ? '[UPDATE]' : '[SAVE]';
    container.querySelector('#cancel-btn').style.display = editingId ? '' : 'none';
    clearFormErrors();
  }

  function clearFormErrors() {
    ['#name-error', '#pattern-error', '#replacement-error', '#form-error'].forEach(sel => {
      const el = container.querySelector(sel);
      if (el) el.textContent = '';
    });
  }

  async function load() {
    const res = await api.rulesList();
    const items = res.ok ? res.data.items : [];
    const tbody = container.querySelector('#rules-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    if (items.length === 0) {
      tbody.innerHTML = '<tr><td colspan="3" style="color:var(--text-muted);text-align:center">No rules yet.</td></tr>';
      return;
    }
    items.forEach(item => {
      const tr = document.createElement('tr');
      tr.className = 'rules-list-row';
      tr.innerHTML = `
        <td>${escHtml(item.name)}</td>
        <td>${escHtml(item.pattern_emmet)} → ${escHtml(item.replacement_emmet)}</td>
        <td class="rule-actions">
          <button class="edit-btn" data-id="${item.id}">[EDIT]</button>
          <button class="delete-btn" data-id="${item.id}">[DELETE]</button>
        </td>
      `;
      tbody.appendChild(tr);
    });

    tbody.querySelectorAll('.edit-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const item = items.find(i => i.id == btn.dataset.id);
        if (!item) return;
        editingId = item.id;
        setForm(item.name, item.pattern_emmet, item.replacement_emmet);
      });
    });

    tbody.querySelectorAll('.delete-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        await api.rulesDelete(btn.dataset.id);
        if (editingId == btn.dataset.id) { editingId = null; setForm('', '', ''); }
        load();
      });
    });
  }

  container.innerHTML = `
    <h2>Rules</h2>
    <div class="rules-form">
      <div class="rules-form-row">
        <label for="rule-name">Name</label>
        <input id="rule-name" type="text" placeholder="rule name">
        <div class="error-msg" id="name-error"></div>
      </div>
      <div></div>
      <div class="rules-form-row">
        <label for="rule-pattern">Pattern (Emmet)</label>
        <input id="rule-pattern" type="text" placeholder="div>span">
        <div class="error-msg" id="pattern-error"></div>
      </div>
      <div class="rules-form-row">
        <label for="rule-replacement">Replacement (Emmet)</label>
        <input id="rule-replacement" type="text" placeholder="section>p">
        <div class="error-msg" id="replacement-error"></div>
      </div>
      <div class="rules-form-actions">
        <button id="save-btn">[SAVE]</button>
        <button id="cancel-btn" style="display:none">[CANCEL]</button>
        <div class="error-msg" id="form-error"></div>
      </div>
    </div>
    <table>
      <thead><tr><th>Name</th><th>Rule</th><th></th></tr></thead>
      <tbody id="rules-tbody"></tbody>
    </table>
  `;

  container.querySelector('#save-btn').addEventListener('click', async () => {
    clearFormErrors();
    const name        = container.querySelector('#rule-name').value.trim();
    const pattern     = container.querySelector('#rule-pattern').value.trim();
    const replacement = container.querySelector('#rule-replacement').value.trim();

    const res = editingId
      ? await api.rulesUpdate(editingId, name, pattern, replacement)
      : await api.rulesCreate(name, pattern, replacement);

    if (res.ok) {
      editingId = null;
      setForm('', '', '');
      load();
    } else if (res.code === 'parse_error' && res.details?.field) {
      container.querySelector(`#${res.details.field}-error`).textContent = res.message;
    } else if (res.code === 'validation_failed') {
      if (res.details?.name) container.querySelector('#name-error').textContent = res.details.name;
      if (res.details?.pattern) container.querySelector('#pattern-error').textContent = res.details.pattern;
      if (res.details?.replacement) container.querySelector('#replacement-error').textContent = res.details.replacement;
    } else {
      container.querySelector('#form-error').textContent = res.message;
    }
  });

  container.querySelector('#cancel-btn').addEventListener('click', () => {
    editingId = null;
    setForm('', '', '');
  });

  load();
}

function escHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
```

- [ ] **Step 3: Test in browser**

Navigate to Rules panel. Verify:
- Create a rule with valid pattern and replacement — it appears in the list
- Create a rule with invalid Emmet — parse error appears next to the correct field
- Edit a rule — form populates, [UPDATE] button saves changes, [CANCEL] clears
- Delete a rule — row disappears

- [ ] **Step 4: Commit**

```bash
git add public/app.css public/js/panels/rules.js
git commit -m "feat: rules panel with CRUD and inline validation errors"
```

---

## Task 10: Stats panel (`panels/stats.js`)

**Files:**
- Modify: `public/js/panels/stats.js`
- Modify: `public/app.css`

API: `POST /api/stats` body `{ kind: 'html'|'css', input }`
HTML response: `{ kind, elements, distinct_tags, attributes, max_depth, top_classes: [{name,count}], depth_histogram: { '0': N, '1': N, ... } }`
CSS response: `{ kind, class_count, top_classes: [{name,count}] }`

- [ ] **Step 1: Add stats CSS to `public/app.css`**

Append to `public/app.css`:
```css
/* Stats panel */
.stats-layout {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 800px;
}

.stats-layout textarea {
  width: 100%;
  height: 180px;
  resize: vertical;
}

.stats-results {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.stats-kv {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 4px 16px;
  font-size: 13px;
}

.stats-kv .key { color: var(--text-muted); text-transform: uppercase; font-size: 11px; letter-spacing: 1px; }
.stats-kv .val { color: var(--text); }
```

- [ ] **Step 2: Replace `public/js/panels/stats.js`**

```js
export function render(container, { api }) {
  let kind = 'html';

  container.innerHTML = `
    <h2>Stats</h2>
    <div class="stats-layout">
      <div class="toggle-group">
        <button data-kind="html" class="active">[HTML]</button>
        <button data-kind="css">[CSS]</button>
      </div>
      <div>
        <label for="stats-input">Input</label>
        <textarea id="stats-input" placeholder="Paste HTML or CSS here..."></textarea>
      </div>
      <button id="run-btn">[RUN]</button>
      <div class="error-msg" id="stats-error"></div>
      <div class="stats-results" id="stats-results"></div>
    </div>
  `;

  container.querySelectorAll('.toggle-group button').forEach(btn => {
    btn.addEventListener('click', () => {
      kind = btn.dataset.kind;
      container.querySelectorAll('.toggle-group button').forEach(b => b.classList.toggle('active', b.dataset.kind === kind));
      container.querySelector('#stats-results').innerHTML = '';
      container.querySelector('#stats-error').textContent = '';
    });
  });

  container.querySelector('#run-btn').addEventListener('click', async () => {
    const input = container.querySelector('#stats-input').value;
    const errEl = container.querySelector('#stats-error');
    const resultsEl = container.querySelector('#stats-results');
    errEl.textContent = '';
    resultsEl.innerHTML = '';

    const res = await api.stats(kind, input);
    if (!res.ok) { errEl.textContent = res.message; return; }
    const d = res.data;

    if (d.kind === 'html') {
      resultsEl.innerHTML = `
        <div class="stats-kv">
          <span class="key">Elements</span><span class="val">${d.elements}</span>
          <span class="key">Distinct tags</span><span class="val">${d.distinct_tags}</span>
          <span class="key">Attributes</span><span class="val">${d.attributes}</span>
          <span class="key">Max depth</span><span class="val">${d.max_depth}</span>
        </div>
        <div>
          <label>Top classes</label>
          ${tableHtml(['Class', 'Count'], d.top_classes.map(c => [escHtml(c.name), c.count]))}
        </div>
        <div>
          <label>Depth histogram</label>
          ${tableHtml(['Depth', 'Nodes'], Object.entries(d.depth_histogram).map(([d, c]) => [d, c]))}
        </div>
      `;
    } else {
      resultsEl.innerHTML = `
        <div class="stats-kv">
          <span class="key">Class count</span><span class="val">${d.class_count}</span>
        </div>
        <div>
          <label>Top classes</label>
          ${tableHtml(['Class', 'Count'], d.top_classes.map(c => [escHtml(c.name), c.count]))}
        </div>
      `;
    }
  });
}

function tableHtml(headers, rows) {
  if (rows.length === 0) return '<p style="color:var(--text-muted);font-size:12px">No data.</p>';
  return `<table>
    <thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead>
    <tbody>${rows.map(r => `<tr>${r.map(c => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody>
  </table>`;
}

function escHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
```

- [ ] **Step 3: Test in browser**

Navigate to Stats panel. Verify:
- HTML mode: paste `<div class="foo"><span class="foo bar">text</span></div>`, click [RUN] — shows element count, top classes, depth histogram
- CSS mode: paste `.foo { color: red; } .foo .bar { }`, click [RUN] — shows class count and top classes
- Invalid HTML shows parse error inline

- [ ] **Step 4: Commit**

```bash
git add public/app.css public/js/panels/stats.js
git commit -m "feat: stats panel with HTML and CSS modes"
```

---

## Task 11: Final wiring check and polish

**Files:**
- Modify: `public/app.css` (any remaining polish)

- [ ] **Step 1: Verify full app flow end-to-end**

With the dev server running:
1. Open `http://127.0.0.1:8080/app.html` logged out — login screen appears
2. Register a new user — auto-logs in, lands on Transform panel
3. Convert `<div><span>hello</span></div>` → Emmet — tree and output populate
4. Navigate to Rules — create a rule `div` → `section`, verify it appears
5. Navigate to History — row appears (if transform was called while logged in)
6. Navigate to Stats — run HTML stats on the XML from step 3
7. Click [LOGOUT] — returns to login screen

- [ ] **Step 2: Fix any visual issues found in step 1**

Common things to check:
- Transform panel columns overflow on small viewports — add `overflow: hidden` or `min-width: 0` to `.transform-col` if needed
- Tree column not tall enough — adjust `height: calc(100vh - 120px)` in `.transform-grid`
- Button hover states look right on all panels

- [ ] **Step 3: Commit**

```bash
git add public/app.css
git commit -m "fix: frontend polish after end-to-end walkthrough"
```

---

## Self-Review

**Spec coverage check:**

| Spec section | Covered by task |
|---|---|
| §1 Single HTML file, ES modules, no frameworks | Task 1 |
| §2 File structure | Tasks 1–10 create all listed files |
| §3 Retro terminal visual style | Tasks 1, 4, 6, 7, 8, 9, 10 (CSS) |
| §4 app.html shell with mount points | Task 1 |
| §4 Boot: `api.me()` → auth or app | Task 5 |
| §5.1 Auth panel: login/register tabs, inline errors | Task 6 |
| §5.2 Transform: 3-col grid, convert buttons, settings | Task 7 |
| §5.3 History: table, expand rows, pagination | Task 8 |
| §5.4 Rules: CRUD, edit/delete, field errors | Task 9 |
| §5.5 Stats: HTML/CSS toggle, results tables | Task 10 |
| §6.1 Tree component | Task 3 |
| §6.2 Sidebar component | Task 4 |
| §7 api.js all 11 endpoints | Task 2 |
| §8 showPanel routing, teardown | Task 5 |
| §9 Error handling (401 reload, inline errors) | Tasks 2, 5, 6, 7, 8, 9, 10 |

All spec requirements covered. No placeholders. Type/name consistency verified: `render(container, ctx)` signature used consistently across all panels; `api.*` function names match Task 2 exports throughout.
