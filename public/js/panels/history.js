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
