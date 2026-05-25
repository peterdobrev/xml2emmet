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
    if (res.status === 401 && path !== '/api/auth/login' && path !== '/api/auth/register' && path !== '/api/auth/me') {
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
