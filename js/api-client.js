/**
 * api-client.js — every page's JS calls window.API.get()/post() instead of
 * raw fetch(), so CSRF headers and session-expiry handling are consistent
 * everywhere instead of copy-pasted per page.
 *
 * Expects window.CSRF_TOKEN to already be set (each protected .php page
 * embeds it inline from the session — see e.g. dashboard.php).
 */
window.API = {
  async call(method, path, body) {
    const prefix = window.API_PREFIX || '';
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json' },
    };
    if (method !== 'GET') {
      opts.headers['X-CSRF-Token'] = window.CSRF_TOKEN || '';
    }
    if (body !== undefined) {
      opts.body = JSON.stringify(body);
    }

    const res = await fetch(prefix + path, opts);

    if (res.status === 401) {
      const base = window.APP_BASE_PATH || '';
      window.location.href = base + '/index.php?reason=session_expired';
      return null;
    }

    let json = null;
    try {
      json = await res.json();
    } catch (e) {
      // non-JSON response — treat as a hard failure below
    }

    if (!res.ok || !json || json.success !== true) {
      const message = (json && json.error) || `Request failed (HTTP ${res.status}).`;
      const err = new Error(message);
      err.status = res.status;
      err.data = json;
      throw err;
    }

    return json.data;
  },

  get(path) {
    return this.call('GET', path);
  },
  post(path, body) {
    return this.call('POST', path, body);
  },
};
