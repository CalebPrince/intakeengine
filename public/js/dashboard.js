(async function () {
  const me = await Api.get('/api/v1/me').catch(() => ({ authenticated: false }));
  if (!me.authenticated || me.type !== 'tenant') {
    location.href = '/login.html';
    return;
  }

  document.getElementById('subdomainLabel').textContent = `· ${me.subdomain}`;
  document.getElementById('bookLink').href = `${location.protocol}//${location.host}/book.html`;
  document.getElementById('logoutBtn').addEventListener('click', async () => {
    await Api.post('/api/v1/logout');
    location.href = '/login.html';
  });

  // --- Tabs -----------------------------------------------------------
  document.querySelectorAll('#mainTabs .nav-link').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#mainTabs .nav-link').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      document.querySelectorAll('.tab-pane').forEach((p) => p.classList.add('d-none'));
      document.getElementById(`tab-${btn.dataset.tab}`).classList.remove('d-none');
      if (btn.dataset.tab === 'forms') loadForms();
    });
  });

  // --- Overview ---------------------------------------------------------
  async function loadOverview() {
    const data = await Api.get('/api/v1/tenant/overview');
    document.getElementById('metricUpcoming').textContent = data.upcoming_bookings;
    document.getElementById('metricMonthly').textContent = data.monthly_intake_forms;
    document.getElementById('metricNewCustomers').textContent = data.new_customers_this_month;
  }

  // --- Registry -----------------------------------------------------------
  const bookingsBody = document.getElementById('bookingsBody');
  const statusFilter = document.getElementById('statusFilter');
  statusFilter.addEventListener('change', loadBookings);

  const STATUS_COLORS = { pending: 'secondary', accepted: 'success', rescheduled: 'warning', cancelled: 'danger', completed: 'primary' };

  async function loadBookings() {
    bookingsBody.innerHTML = '<tr><td colspan="6" class="text-muted">Loading…</td></tr>';
    const q = statusFilter.value ? `?status=${encodeURIComponent(statusFilter.value)}` : '';
    const { bookings } = await Api.get(`/api/v1/tenant/bookings${q}`);

    if (!bookings.length) {
      bookingsBody.innerHTML = '<tr><td colspan="6" class="text-muted">No bookings yet.</td></tr>';
      return;
    }

    bookingsBody.innerHTML = bookings.map((b) => `
      <tr data-id="${b.id}">
        <td>${escapeHtml(b.customer_name)}<div class="text-muted small">${escapeHtml(b.customer_email || '')}</div></td>
        <td>${escapeHtml(b.form_name)}</td>
        <td><span class="badge bg-${STATUS_COLORS[b.status] || 'secondary'} badge-status">${b.status}</span></td>
        <td class="small">${b.scheduled_at ? new Date(b.scheduled_at).toLocaleString() : '—'}</td>
        <td class="small text-muted">${new Date(b.created_at).toLocaleString()}</td>
        <td class="d-flex gap-1">
          <select class="form-select form-select-sm status-select" style="width:auto;">
            ${Object.keys(STATUS_COLORS).map((s) => `<option value="${s}" ${s === b.status ? 'selected' : ''}>${s}</option>`).join('')}
          </select>
          <button class="btn btn-sm btn-outline-app view-btn">View</button>
        </td>
      </tr>
    `).join('');

    bookingsBody.querySelectorAll('tr').forEach((row) => {
      const id = row.dataset.id;
      row.querySelector('.status-select').addEventListener('change', async (e) => {
        await Api.patch(`/api/v1/tenant/bookings/${id}`, { status: e.target.value });
        loadBookings();
        loadOverview();
      });
      row.querySelector('.view-btn').addEventListener('click', () => showDetail(id));
    });
  }

  async function showDetail(id) {
    const modalEl = document.getElementById('detailModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('detailBody').innerHTML = 'Loading…';
    modal.show();

    const b = await Api.get(`/api/v1/tenant/bookings/${id}`);
    document.getElementById('detailBody').innerHTML = `
      <p><strong>${escapeHtml(b.customer_name)}</strong> — ${escapeHtml(b.customer_email || '')} ${escapeHtml(b.customer_phone || '')}</p>
      <p class="text-muted small">Form: ${escapeHtml(b.form_name)} · Status: ${b.status}</p>
      <hr class="border-secondary">
      ${b.responses.map((r) => `<div class="mb-2"><div class="text-muted small">${escapeHtml(r.label)}</div><div>${escapeHtml(String(r.value ?? '—'))}</div></div>`).join('') || '<p class="text-muted">No responses recorded.</p>'}
    `;
  }

  // --- Form builder -----------------------------------------------------------
  const formsList = document.getElementById('formsList');
  const fieldsPanel = document.getElementById('fieldsPanel');
  let activeFormId = null;

  document.getElementById('createFormBtn').addEventListener('click', async () => {
    const input = document.getElementById('newFormName');
    const name = input.value.trim();
    if (!name) return;
    const res = await Api.post('/api/v1/tenant/forms', { name });
    input.value = '';
    await loadForms();
    selectForm(res.id);
  });

  async function loadForms() {
    const { forms } = await Api.get('/api/v1/tenant/forms');
    if (!forms.length) {
      formsList.innerHTML = '<p class="text-muted small">No forms yet — add one below.</p>';
      return;
    }
    formsList.innerHTML = forms.map((f) => `
      <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
              style="background:var(--app-input-bg);color:var(--app-text);border-color:var(--app-border);" data-id="${f.id}">
        <span>${escapeHtml(f.name)} ${f.is_active ? '' : '<span class=\"badge bg-secondary\">inactive</span>'}</span>
        <span class="badge bg-dark">${f.field_count} fields</span>
      </button>
    `).join('');
    formsList.querySelectorAll('button').forEach((btn) => {
      btn.addEventListener('click', () => selectForm(btn.dataset.id));
    });
  }

  const FIELD_TYPES = ['text', 'textarea', 'dropdown', 'file', 'date', 'email', 'phone'];

  async function selectForm(id) {
    activeFormId = id;
    await renderFieldsPanel();
  }

  async function renderFieldsPanel() {
    if (!activeFormId) return;
    const form = await Api.get(`/api/v1/tenant/forms/${activeFormId}`);

    fieldsPanel.innerHTML = `
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <h6 class="mb-0">${escapeHtml(form.name)}</h6>
          <span class="text-muted small">${form.is_active ? 'Active — accepting submissions' : 'Inactive'}</span>
        </div>
        <div class="d-flex gap-1">
          <button class="btn btn-sm btn-outline-app" id="toggleActiveBtn">${form.is_active ? 'Deactivate' : 'Activate'}</button>
          <button class="btn btn-sm btn-outline-danger" id="deleteFormBtn">Delete form</button>
        </div>
      </div>
      <div id="fieldRows" class="d-flex flex-column gap-2 mb-3"></div>
      <div class="border-top border-secondary pt-3">
        <h6 class="small text-muted">Add a question</h6>
        <div class="row g-2 align-items-end">
          <div class="col-4"><input class="form-control form-control-sm" id="newFieldLabel" placeholder="Question label"></div>
          <div class="col-3">
            <select class="form-select form-select-sm" id="newFieldType">
              ${FIELD_TYPES.map((t) => `<option value="${t}">${t}</option>`).join('')}
            </select>
          </div>
          <div class="col-3"><input class="form-control form-control-sm" id="newFieldOptions" placeholder="Options (comma sep, dropdown only)"></div>
          <div class="col-1 form-check">
            <input type="checkbox" class="form-check-input" id="newFieldRequired" checked>
          </div>
          <div class="col-1"><button class="btn btn-sm btn-brand w-100" id="addFieldBtn">Add</button></div>
        </div>
      </div>
    `;

    document.getElementById('toggleActiveBtn').addEventListener('click', async () => {
      await Api.patch(`/api/v1/tenant/forms/${activeFormId}`, { is_active: !form.is_active });
      await loadForms();
      await renderFieldsPanel();
    });
    document.getElementById('deleteFormBtn').addEventListener('click', async () => {
      if (!confirm('Delete this form and all its questions?')) return;
      await Api.del(`/api/v1/tenant/forms/${activeFormId}`);
      activeFormId = null;
      fieldsPanel.innerHTML = '<p class="text-muted">Select a form to edit its questions.</p>';
      await loadForms();
    });
    document.getElementById('addFieldBtn').addEventListener('click', async () => {
      const label = document.getElementById('newFieldLabel').value.trim();
      if (!label) return;
      const type = document.getElementById('newFieldType').value;
      const optionsRaw = document.getElementById('newFieldOptions').value.trim();
      const options = type === 'dropdown' && optionsRaw ? optionsRaw.split(',').map((s) => s.trim()).filter(Boolean) : undefined;
      const required = document.getElementById('newFieldRequired').checked;
      try {
        await Api.post(`/api/v1/tenant/forms/${activeFormId}/fields`, { label, field_type: type, options, is_required: required });
        await renderFieldsPanel();
        await loadForms();
      } catch (err) {
        alert(err.message);
      }
    });

    const fieldRows = document.getElementById('fieldRows');
    if (!form.fields.length) {
      fieldRows.innerHTML = '<p class="text-muted small">No questions yet.</p>';
    } else {
      fieldRows.innerHTML = form.fields.map((f) => `
        <div class="field-row p-2 d-flex align-items-center gap-2" data-id="${f.id}">
          <span class="text-muted small" style="width:1.5rem;">${f.order_index}</span>
          <input class="form-control form-control-sm field-label" value="${escapeAttr(f.label)}" style="max-width:220px;">
          <span class="badge bg-dark">${f.field_type}</span>
          <span class="form-check small d-flex align-items-center gap-1 mb-0">
            <input type="checkbox" class="form-check-input field-required" ${f.is_required ? 'checked' : ''}> required
          </span>
          <span class="text-muted small flex-grow-1">${(f.options || []).join(', ')}</span>
          <button class="btn btn-sm btn-outline-app save-field-btn">Save</button>
          <button class="btn btn-sm btn-outline-danger delete-field-btn">✕</button>
        </div>
      `).join('');

      fieldRows.querySelectorAll('.field-row').forEach((row) => {
        const fieldId = row.dataset.id;
        row.querySelector('.save-field-btn').addEventListener('click', async () => {
          await Api.patch(`/api/v1/tenant/forms/${activeFormId}/fields/${fieldId}`, {
            label: row.querySelector('.field-label').value.trim(),
            is_required: row.querySelector('.field-required').checked,
          });
          await renderFieldsPanel();
        });
        row.querySelector('.delete-field-btn').addEventListener('click', async () => {
          await Api.del(`/api/v1/tenant/forms/${activeFormId}/fields/${fieldId}`);
          await renderFieldsPanel();
          await loadForms();
        });
      });
    }
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function escapeAttr(str) {
    return escapeHtml(str);
  }

  await loadOverview();
  await loadBookings();
})();
