import { render as renderTree } from '../components/tree.js';

export function render(container, { api }) {
  container.innerHTML = `
    <h2>Transform</h2>
    <div class="transform-panel">
      <div class="transform-grid">
      <div class="transform-col">
        <div class="transform-col-header">
          <label>XML / HTML</label>
          <div class="toggle-group" id="mode-toggle">
            <button data-mode="html" class="active">[HTML]</button>
            <button data-mode="xml">[XML]</button>
          </div>
          <button class="expand-btn" data-col="xml">⤢</button>
        </div>
        <textarea id="xml-input" placeholder="Paste XML or HTML here..."></textarea>
        <div class="error-msg" id="xml-error"></div>
      </div>

      <div class="transform-controls">
        <button id="btn-to-emmet" title="Convert to Emmet">→</button>
        <button id="btn-to-xml" title="Convert to XML/HTML">←</button>
        <div class="error-msg" id="convert-error"></div>
        <div class="rules-select" id="rules-select">
          <label>Rules</label>
          <div id="rules-checkboxes" class="rules-checkboxes"></div>
        </div>
      </div>

      <div class="transform-col">
        <div class="transform-col-header">
          <label>Emmet</label>
          <button class="expand-btn" data-col="emmet">⤢</button>
        </div>
        <textarea id="emmet-input" placeholder="Paste Emmet here..."></textarea>
        <div class="error-msg" id="emmet-error"></div>
      </div>

      <div class="transform-controls">
      </div>

      <div class="transform-col">
        <div class="transform-col-header">
          <label>Tree</label>
          <div class="tree-checkboxes">
            <label><input type="checkbox" id="show-text" checked> text</label>
            <label><input type="checkbox" id="show-attrs" checked> attrs</label>
          </div>
          <button class="expand-btn" data-col="tree">⤢</button>
        </div>
        <div class="transform-tree" id="tree-output"></div>
      </div>
    </div>
      <div id="transform-stats"><div class="transform-stats-bar">&nbsp;</div></div>
    </div>
  `;

  let mode = 'html';
  let rules = [];

  async function loadRules() {
    const res = await api.rulesList();
    rules = res.ok ? res.data.items : [];
    const box = container.querySelector('#rules-checkboxes');
    box.innerHTML = '';
    if (rules.length === 0) {
      box.innerHTML = '<span class="no-rules">No rules saved.</span>';
      return;
    }
    rules.forEach(r => {
      const lbl = document.createElement('label');
      lbl.className = 'rule-check-label';
      lbl.innerHTML = `<input type="checkbox" data-rule-id="${r.id}"> ${escHtml(r.name)}`;
      box.appendChild(lbl);
    });
  }

  function selectedRuleIds() {
    return [...container.querySelectorAll('#rules-checkboxes input:checked')]
      .map(el => parseInt(el.dataset.ruleId, 10));
  }

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
    const rule_ids  = selectedRuleIds();
    const res = await api.transform({
      direction: 'xml2emmet',
      input,
      settings: { mode, show_text: showText, show_attrs: showAttrs },
      ...(rule_ids.length ? { rule_ids } : {}),
    });
    if (res.ok) {
      container.querySelector('#emmet-input').value = res.data.output;
      renderTree(container.querySelector('#tree-output'), res.data.tree);
      showStats(input);
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
      showStats(res.data.output);
    } else if (res.code === 'parse_error') {
      container.querySelector('#emmet-error').textContent = res.message;
    } else {
      container.querySelector('#convert-error').textContent = res.message;
    }
  });

  async function showStats(htmlInput) {
    const el = container.querySelector('#transform-stats');
    const res = await api.stats('html', htmlInput);
    if (!res.ok) { el.innerHTML = ''; return; }
    const d = res.data;
    const classes = d.top_classes.slice(0, 5).map(c => escHtml(c.name)).join(', ');
    el.innerHTML = `
      <div class="transform-stats-bar">
        <span><span class="stats-key">elements</span> ${d.elements}</span>
        <span><span class="stats-key">tags</span> ${d.distinct_tags}</span>
        <span><span class="stats-key">depth</span> ${d.max_depth}</span>
        <span><span class="stats-key">attrs</span> ${d.attributes}</span>
        ${classes ? `<span><span class="stats-key">classes</span> ${classes}</span>` : ''}
      </div>
    `;
  }

  function clearErrors() {
    ['#xml-error', '#emmet-error', '#convert-error'].forEach(sel => {
      container.querySelector(sel).textContent = '';
    });
  }

  loadRules();

  container.querySelectorAll('.expand-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const col = btn.closest('.transform-col');
      const grid = container.querySelector('.transform-grid');
      const isExpanded = col.classList.contains('expanded');
      if (isExpanded) {
        col.classList.remove('expanded');
        grid.classList.remove('has-expanded');
        btn.textContent = '⤢';
      } else {
        container.querySelectorAll('.transform-col.expanded').forEach(c => {
          c.classList.remove('expanded');
          c.querySelector('.expand-btn').textContent = '⤢';
        });
        col.classList.add('expanded');
        grid.classList.add('has-expanded');
        btn.textContent = '⤡';
      }
    });
  });
}

function escHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
