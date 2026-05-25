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
        <label>Emmet</label>
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
        </div>
        <div class="transform-tree" id="tree-output"></div>
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
