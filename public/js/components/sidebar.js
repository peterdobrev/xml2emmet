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
