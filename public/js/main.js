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
