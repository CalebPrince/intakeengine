(async function () {
  const loginView = document.getElementById('loginView');
  const consoleView = document.getElementById('consoleView');
  const logoutBtn = document.getElementById('logoutBtn');

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
  }

  async function checkSession() {
    const me = await Api.get('/api/v1/me').catch(() => ({ authenticated: false }));
    if (me.authenticated && me.type === 'admin') {
      loginView.classList.add('d-none');
      consoleView.classList.remove('d-none');
      logoutBtn.classList.remove('d-none');
      await loadConsole();
    } else {
      loginView.classList.remove('d-none');
      consoleView.classList.add('d-none');
      logoutBtn.classList.add('d-none');
    }
  }

  document.getElementById('adminLoginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorBox = document.getElementById('loginError');
    errorBox.classList.add('d-none');
    try {
      await Api.post('/api/v1/admin/login', {
        email: document.getElementById('adminEmail').value.trim(),
        password: document.getElementById('adminPassword').value,
      });
      await checkSession();
    } catch (err) {
      errorBox.textContent = err.message;
      errorBox.classList.remove('d-none');
    }
  });

  logoutBtn.addEventListener('click', async () => {
    await Api.post('/api/v1/logout');
    await checkSession();
  });

  document.querySelectorAll('#consoleView .nav-tabs .nav-link').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#consoleView .nav-tabs .nav-link').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      document.querySelectorAll('#consoleView .tab-pane').forEach((p) => p.classList.add('d-none'));
      document.getElementById(`tab-${btn.dataset.tab}`).classList.remove('d-none');
    });
  });

  async function loadConsole() {
    const metrics = await Api.get('/api/v1/admin/metrics');
    document.getElementById('metricsRow').innerHTML = [
      ['Total tenants', metrics.total_tenants],
      ['Active', metrics.active_tenants],
      ['Suspended', metrics.suspended_tenants],
      ['MRR', `$${metrics.mrr_dollars.toFixed(2)}`],
      ['Disk used', formatBytes(metrics.disk_usage_bytes)],
      ['Webhook events', metrics.webhook_events_total],
    ].map(([label, value]) => `
      <div class="col-md-2 col-6">
        <div class="card card-metric p-3">
          <div class="value">${value}</div>
          <div class="label">${label}</div>
        </div>
      </div>
    `).join('');

    await loadTenants();
    await loadMessages();
    await loadLogs();
  }

  async function loadTenants() {
    const { tenants } = await Api.get('/api/v1/admin/tenants');
    const body = document.getElementById('tenantsBody');
    if (!tenants.length) {
      body.innerHTML = '<tr><td colspan="7" class="text-muted">No tenants registered yet.</td></tr>';
      return;
    }
    body.innerHTML = tenants.map((t) => `
      <tr data-id="${t.id}">
        <td>${escapeHtml(t.subdomain)}</td>
        <td>${escapeHtml(t.business_name)}</td>
        <td class="small text-muted">${escapeHtml(t.owner_email)}</td>
        <td>
          <select class="form-select form-select-sm tier-select" style="width:auto;">
            <option value="starter" ${t.tier_code === 'starter' ? 'selected' : ''}>Starter</option>
            <option value="pro" ${t.tier_code === 'pro' ? 'selected' : ''}>Pro</option>
            <option value="enterprise" ${t.tier_code === 'enterprise' ? 'selected' : ''}>Enterprise</option>
          </select>
        </td>
        <td><span class="badge bg-${t.status === 'active' ? 'success' : 'danger'}">${t.status}</span></td>
        <td class="small">${formatBytes(t.disk_usage_bytes)}</td>
        <td><button class="btn btn-sm btn-outline-app suspend-btn">${t.status === 'active' ? 'Suspend' : 'Activate'}</button></td>
      </tr>
    `).join('');

    body.querySelectorAll('tr').forEach((row) => {
      const id = row.dataset.id;
      row.querySelector('.tier-select').addEventListener('change', async (e) => {
        await Api.patch(`/api/v1/admin/tenants/${id}`, { tier_code: e.target.value });
      });
      row.querySelector('.suspend-btn').addEventListener('click', async (e) => {
        const currentlyActive = e.target.textContent.trim() === 'Suspend';
        await Api.patch(`/api/v1/admin/tenants/${id}`, { status: currentlyActive ? 'suspended' : 'active' });
        await loadTenants();
        const metrics = await Api.get('/api/v1/admin/metrics');
        document.querySelector('#metricsRow .col-md-2:nth-child(2) .value').textContent = metrics.active_tenants;
        document.querySelector('#metricsRow .col-md-2:nth-child(3) .value').textContent = metrics.suspended_tenants;
      });
    });
  }

  async function loadMessages() {
    const { messages } = await Api.get('/api/v1/admin/messages');
    const box = document.getElementById('messagesList');

    box.innerHTML = messages.length
      ? messages.map((m) => `
        <div class="card-message">
          <div class="d-flex justify-content-between">
            <strong>${escapeHtml(m.name)}</strong>
            <span class="text-muted small">${new Date(m.created_at).toLocaleString()}</span>
          </div>
          <div class="text-muted small mb-1">${escapeHtml(m.email)}${m.subject ? ' · ' + escapeHtml(m.subject) : ''}</div>
          <div>${escapeHtml(m.message)}</div>
        </div>
      `).join('')
      : '<p class="text-muted small">No messages yet.</p>';
  }

  async function loadLogs() {
    const { system_logs, webhook_logs } = await Api.get('/api/v1/admin/logs');
    const sysBox = document.getElementById('systemLogs');
    const hookBox = document.getElementById('webhookLogs');

    sysBox.innerHTML = system_logs.length
      ? system_logs.map((l) => `<pre class="log-line">[${l.level}] ${l.created_at} — ${escapeHtml(l.message)}</pre>`).join('')
      : '<p class="text-muted small">No system log entries yet.</p>';

    hookBox.innerHTML = webhook_logs.length
      ? webhook_logs.map((w) => `<pre class="log-line">${w.created_at} — ${escapeHtml(w.subdomain)} · ${escapeHtml(w.event_type)} (${w.payload_bytes}B, HTTP ${w.status_code})</pre>`).join('')
      : '<p class="text-muted small">No webhook activity yet.</p>';
  }

  await checkSession();
})();
