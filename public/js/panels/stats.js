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
