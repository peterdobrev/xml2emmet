import { render as renderTree } from '../components/tree.js';
import { escHtml, clearErrors } from '../util.js';

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

  // Cache the references we touch on every event — saves ~12 of the 23
  // querySelector lookups the panel used to do per-click.
  const els = {
    xmlInput:    container.querySelector('#xml-input'),
    emmetInput:  container.querySelector('#emmet-input'),
    treeOutput:  container.querySelector('#tree-output'),
    xmlError:    container.querySelector('#xml-error'),
    emmetError:  container.querySelector('#emmet-error'),
    convertErr:  container.querySelector('#convert-error'),
    rulesBox:    container.querySelector('#rules-checkboxes'),
    showText:    container.querySelector('#show-text'),
    showAttrs:   container.querySelector('#show-attrs'),
    statsHost:   container.querySelector('#transform-stats'),
  };

  let mode = 'html';
  let rules = [];

  async function loadRules() {
    const res = await api.rulesList();
    rules = res.ok ? res.data.items : [];
    els.rulesBox.innerHTML = '';
    if (rules.length === 0) {
      els.rulesBox.innerHTML = '<span class="no-rules">No rules saved.</span>';
      return;
    }
    rules.forEach(r => {
      const lbl = document.createElement('label');
      lbl.className = 'rule-check-label';
      lbl.innerHTML = `<input type="checkbox" data-rule-id="${r.id}"> ${escHtml(r.name)}`;
      els.rulesBox.appendChild(lbl);
    });
  }

  function selectedRuleIds() {
    return [...els.rulesBox.querySelectorAll('input:checked')]
      .map(el => parseInt(el.dataset.ruleId, 10));
  }

  /**
   * Run a transform in either direction, render the result, and surface errors
   * to the appropriate inline element. Both arrow buttons funnel through this.
   * `bodyBuilder` is invoked at click time so settings/rule selections are
   * read from the live DOM state, not captured at handler-attach time.
   */
  async function runConversion({ direction, fromEl, toEl, fieldErrEl, bodyBuilder, statsSource }) {
    clearErrors(container);
    const input = fromEl.value;
    const res = await api.transform({ direction, input, ...bodyBuilder() });
    if (res.ok) {
      toEl.value = res.data.output;
      renderTree(els.treeOutput, res.data.tree);
      showStats(typeof statsSource === 'function' ? statsSource(res) : statsSource);
    } else if (res.code === 'parse_error') {
      fieldErrEl.textContent = res.message;
    } else {
      els.convertErr.textContent = res.message;
    }
  }

  container.querySelectorAll('#mode-toggle button').forEach(btn => {
    btn.addEventListener('click', () => {
      mode = btn.dataset.mode;
      container.querySelectorAll('#mode-toggle button').forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
    });
  });

  container.querySelector('#btn-to-emmet').addEventListener('click', () => runConversion({
    direction:  'xml2emmet',
    fromEl:     els.xmlInput,
    toEl:       els.emmetInput,
    fieldErrEl: els.xmlError,
    bodyBuilder: () => {
      const rule_ids = selectedRuleIds();
      return {
        settings: { mode, show_text: els.showText.checked, show_attrs: els.showAttrs.checked },
        ...(rule_ids.length ? { rule_ids } : {}),
      };
    },
    statsSource: () => els.xmlInput.value,
  }));

  container.querySelector('#btn-to-xml').addEventListener('click', () => runConversion({
    direction:  'emmet2xml',
    fromEl:     els.emmetInput,
    toEl:       els.xmlInput,
    fieldErrEl: els.emmetError,
    bodyBuilder: () => ({ settings: { mode } }),
    statsSource: (res) => res.data.output,
  }));

  async function showStats(htmlInput) {
    const res = await api.stats('html', htmlInput);
    if (!res.ok) { els.statsHost.innerHTML = ''; return; }
    const d = res.data;
    const classes = d.top_classes.slice(0, 5).map(c => escHtml(c.name)).join(', ');
    els.statsHost.innerHTML = `
      <div class="transform-stats-bar">
        <span><span class="stats-key">elements</span> ${d.elements}</span>
        <span><span class="stats-key">tags</span> ${d.distinct_tags}</span>
        <span><span class="stats-key">depth</span> ${d.max_depth}</span>
        <span><span class="stats-key">attrs</span> ${d.attributes}</span>
        ${classes ? `<span><span class="stats-key">classes</span> ${classes}</span>` : ''}
      </div>
    `;
  }

  setupExpandToggle(container);
  loadRules();
}

/**
 * Wire the per-column expand/collapse buttons. One column at a time can be
 * expanded; clicking the button on an already-expanded column collapses it.
 */
function setupExpandToggle(container) {
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
