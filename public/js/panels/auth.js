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
