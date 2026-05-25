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
