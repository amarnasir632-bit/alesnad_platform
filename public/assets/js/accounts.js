(() => {
  const list = document.querySelector('#account-list');
  const feedback = document.querySelector('#accounts-feedback');
  const classTemplate = document.querySelector('#account-class-options');
  let csrfToken = '';
  let roles = [];

  function notify(message, isError = false) {
    feedback.replaceChildren();
    const item = document.createElement('p');
    item.className = `message ${isError ? 'error' : 'success'}`;
    item.textContent = message;
    feedback.append(item);
  }

  async function csrf() {
    if (csrfToken) return csrfToken;
    const response = await fetch('/api/v1/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || 'تعذر تهيئة الطلب الآمن.');
    csrfToken = payload?.data?.csrf_token || '';
    if (!csrfToken) throw new Error('تعذر تهيئة الطلب الآمن.');
    return csrfToken;
  }

  async function api(path, method = 'GET', body = undefined) {
    const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = await csrf();
      options.body = JSON.stringify(body);
    }
    const response = await fetch(path, options);
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || 'تعذر تنفيذ الطلب.');
    return payload.data;
  }

  function field(labelText, name, value, type = 'text') {
    const label = document.createElement('label');
    label.className = 'account-field';
    label.append(document.createTextNode(labelText));
    const input = document.createElement('input');
    input.name = name;
    input.type = type;
    input.value = value ?? '';
    label.append(input);
    return label;
  }

  function createRow(account) {
    const row = document.createElement('tr');
    row.dataset.accountId = account.id;

    const identity = document.createElement('td');
    identity.append(field('الاسم', 'full_name', account.full_name));
    identity.append(field('البريد الإلكتروني', 'email', account.email, 'email'));
    identity.append(field('الهاتف', 'phone', account.phone || ''));
    const id = document.createElement('small');
    id.className = 'muted';
    id.textContent = `رقم الحساب: ${account.id}`;
    identity.append(id);
    row.append(identity);

    const classCell = document.createElement('td');
    const classSelect = document.createElement('select');
    classSelect.name = 'class_id';
    classSelect.replaceChildren(classTemplate.content.cloneNode(true));
    classSelect.value = account.class_id === null ? '' : String(account.class_id);
    classSelect.disabled = !account.roles.includes('student');
    classCell.append(classSelect);
    row.append(classCell);

    const activeCell = document.createElement('td');
    const activeLabel = document.createElement('label');
    activeLabel.className = 'account-active';
    const active = document.createElement('input');
    active.type = 'checkbox';
    active.name = 'is_active';
    active.checked = account.is_active;
    activeLabel.append(active, document.createTextNode(account.is_active ? 'نشط' : 'معطل'));
    activeCell.append(activeLabel);
    row.append(activeCell);

    const roleCell = document.createElement('td');
    const roleGroup = document.createElement('fieldset');
    roleGroup.className = 'account-role-list';
    const legend = document.createElement('legend');
    legend.textContent = 'الأدوار';
    roleGroup.append(legend);
    roles.forEach((role) => {
      const label = document.createElement('label');
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.name = 'roles';
      checkbox.value = role.code;
      checkbox.checked = account.roles.includes(role.code);
      label.append(checkbox, document.createTextNode(role.display_name));
      roleGroup.append(label);
    });
    roleCell.append(roleGroup);
    row.append(roleCell);

    const actionCell = document.createElement('td');
    const save = document.createElement('button');
    save.type = 'button';
    save.className = 'button button-small';
    save.dataset.action = 'save-account';
    save.textContent = 'حفظ البيانات';
    const saveRoles = document.createElement('button');
    saveRoles.type = 'button';
    saveRoles.className = 'button button-small button-secondary';
    saveRoles.dataset.action = 'save-roles';
    saveRoles.textContent = 'حفظ الأدوار';
    actionCell.append(save, saveRoles);
    row.append(actionCell);
    return row;
  }

  async function load() {
    const [accounts, roleData] = await Promise.all([
      api('/api/v1/admin/users?limit=100&offset=0'),
      api('/api/v1/admin/roles'),
    ]);
    roles = roleData.roles;
    list.replaceChildren();
    if (!accounts.length) {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = 5;
      cell.textContent = 'لا توجد حسابات في القائمة.';
      row.append(cell);
      list.append(row);
      return;
    }
    accounts.forEach((account) => list.append(createRow(account)));
  }

  list.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const row = button.closest('tr[data-account-id]');
    button.disabled = true;
    try {
      if (button.dataset.action === 'save-account') {
        const value = (name) => row.querySelector(`[name="${name}"]`);
        const classSelect = value('class_id');
        const body = {
          full_name: value('full_name').value,
          email: value('email').value,
          phone: value('phone').value,
          is_active: value('is_active').checked,
        };
        if (!classSelect.disabled) body.class_id = classSelect.value ? Number(classSelect.value) : null;
        await api(`/api/v1/admin/users/${row.dataset.accountId}`, 'PATCH', body);
        notify('تم حفظ بيانات الحساب.');
      } else {
        const selected = Array.from(row.querySelectorAll('input[name="roles"]:checked'), (input) => input.value);
        await api(`/api/v1/admin/users/${row.dataset.accountId}/roles`, 'PUT', { roles: selected });
        notify('تم تحديث أدوار الحساب.');
        await load();
      }
    } catch (error) { notify(error.message, true); }
    finally { button.disabled = false; }
  });

  load().catch((error) => {
    list.replaceChildren();
    notify(error.message, true);
  });
})();
