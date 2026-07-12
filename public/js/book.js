(async function () {
  const root = document.getElementById('root');

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function fieldInput(field) {
    const req = field.is_required ? 'required' : '';
    const name = `f_${field.id}`;
    if (field.field_type === 'textarea') {
      return `<textarea class="form-control" name="${name}" rows="3" ${req}></textarea>`;
    }
    if (field.field_type === 'dropdown') {
      const opts = (field.options || []).map((o) => `<option value="${escapeHtml(o)}">${escapeHtml(o)}</option>`).join('');
      return `<select class="form-select" name="${name}" ${req}><option value="">Select…</option>${opts}</select>`;
    }
    if (field.field_type === 'file') {
      return `<input type="text" class="form-control" name="${name}" ${req} placeholder="Attach via link (file upload not enabled in this demo)">`;
    }
    const typeMap = { date: 'date', email: 'email', phone: 'tel' };
    return `<input type="${typeMap[field.field_type] || 'text'}" class="form-control" name="${name}" ${req}>`;
  }

  let form;
  try {
    form = await Api.get('/api/v1/public/form');
  } catch (err) {
    root.innerHTML = `<p class="text-muted">${escapeHtml(err.message)}</p>`;
    return;
  }

  root.innerHTML = `
    <h4>${escapeHtml(form.name)}</h4>
    ${form.description ? `<p class="text-muted">${escapeHtml(form.description)}</p>` : ''}
    <form id="bookingForm" class="mt-3">
      <div class="mb-3">
        <label class="form-label">Your name</label>
        <input type="text" class="form-control" name="customer_name" required>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input type="email" class="form-control" name="customer_email">
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input type="tel" class="form-control" name="customer_phone">
        </div>
      </div>
      <hr class="border-secondary">
      ${form.fields.map((f) => `
        <div class="mb-3">
          <label class="form-label">${escapeHtml(f.label)}${f.is_required ? ' *' : ''}</label>
          ${fieldInput(f)}
        </div>
      `).join('')}
      <div id="submitError" class="alert alert-danger py-2 small d-none"></div>
      <div id="submitSuccess" class="alert alert-success py-2 small d-none"></div>
      <button type="submit" class="btn btn-brand w-100">Request appointment</button>
    </form>
  `;

  document.getElementById('bookingForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const responses = {};
    form.fields.forEach((f) => { responses[f.id] = fd.get(`f_${f.id}`) || ''; });

    const errorBox = document.getElementById('submitError');
    const successBox = document.getElementById('submitSuccess');
    errorBox.classList.add('d-none');

    try {
      await Api.post('/api/v1/public/bookings', {
        form_id: form.id,
        customer: { name: fd.get('customer_name'), email: fd.get('customer_email'), phone: fd.get('customer_phone') },
        responses,
      });
      e.target.classList.add('d-none');
      successBox.textContent = 'Thanks! Your request has been sent — the business will follow up shortly.';
      successBox.classList.remove('d-none');
      root.appendChild(successBox);
    } catch (err) {
      errorBox.textContent = err.message;
      errorBox.classList.remove('d-none');
    }
  });
})();
