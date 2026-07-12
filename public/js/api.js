/**
 * Global fetch wrapper. All backend hydration goes through here.
 * Cookies (the HttpOnly JWT) travel automatically via same-origin credentials.
 */
const Api = (() => {
  async function request(method, path, body) {
    const res = await fetch(path, {
      method,
      credentials: 'same-origin',
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
    });

    let data = null;
    try {
      data = await res.json();
    } catch (_) {
      data = null;
    }

    if (!res.ok) {
      const message = (data && data.error) || `Request failed (${res.status})`;
      throw new Error(message);
    }
    return data;
  }

  return {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body || {}),
    patch: (path, body) => request('PATCH', path, body || {}),
    del: (path) => request('DELETE', path),
  };
})();
