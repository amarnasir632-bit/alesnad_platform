(() => {
  const feedback = document.querySelector('#session-feedback');
  const form = document.querySelector('#session-create-form');
  let csrfToken = '';

  const classSubjectSelect = document.querySelector('#admin-class-subject');
  if (classSubjectSelect) {
    api('/api/v1/admin/academics/class-subjects').then(({ class_subjects: assignments }) => {
      classSubjectSelect.replaceChildren(new Option('اختر الصف والمادة', ''));
      assignments.forEach((assignment) => classSubjectSelect.add(new Option(
        `${assignment.stage_name} · ${assignment.class_name} · ${assignment.subject_name}`,
        assignment.class_subject_id,
      )));
    }).catch((error) => {
      classSubjectSelect.replaceChildren(new Option('تعذر تحميل الصفوف والمواد', ''));
      showMessage(feedback, error.message, true);
    });
  }

  async function csrf() {
    if (csrfToken) return csrfToken;
    const response = await fetch('/api/v1/csrf', { credentials: 'same-origin' });
    const result = await response.json();
    if (!response.ok) throw new Error(result?.error?.message || 'تعذر تهيئة الطلب الآمن.');
    csrfToken = result?.data?.csrf_token || result?.csrf_token || '';
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
    const result = await response.json();
    if (!response.ok) throw new Error(result?.error?.message || 'تعذر تنفيذ الطلب.');
    return result.data || {};
  }

  function showMessage(target, message, isError = false) {
    target.replaceChildren();
    const p = document.createElement('p');
    p.className = `message ${isError ? 'error' : 'success'}`;
    p.textContent = message;
    target.append(p);
  }

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(form));
    values.class_subject_id = Number(values.class_subject_id);
    values.duration_minutes = Number(values.duration_minutes);
    if (values.teacher_id) values.teacher_id = Number(values.teacher_id);
    values.scheduled_at = new Date(values.scheduled_at).toISOString();
    try {
      await api('/api/v1/teacher/sessions', 'POST', values);
      showMessage(feedback, 'تمت جدولة الحصة. حدّث الصفحة لبدء إعداد البث.');
      form.reset();
    } catch (error) { showMessage(feedback, error.message, true); }
  });

  document.querySelector('#session-list')?.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const card = button.closest('[data-session-id]');
    const id = card.dataset.sessionId;
    const output = card.querySelector('.session-output');
    const action = button.dataset.action;
    button.disabled = true;
    try {
      if (action === 'provision') {
        const { stream } = await api(`/api/v1/teacher/sessions/${id}/stream`, 'POST', {});
        const box = document.createElement('div');
        box.className = 'stream-credentials';
        const note = document.createElement('p'); note.textContent = 'بيانات البث سرية. انسخها الآن إلى إعدادات البث في OBS أو البرنامج المعتمد.';
        const endpoint = document.createElement('p'); endpoint.textContent = `عنوان البث: ${stream.ingest_url}`;
        const key = document.createElement('p'); key.textContent = `مفتاح البث: ${stream.stream_key}`;
        box.append(note, endpoint, key); output.replaceChildren(box);
      } else if (action === 'sync') {
        const { sync } = await api(`/api/v1/teacher/sessions/${id}/recordings/sync`, 'POST', {});
        showMessage(output, `تم فحص التسجيلات الجاهزة. أضيف ${sync.added_count} تسجيلًا جديدًا.`);
      } else if (action === 'complete') {
        await api(`/api/v1/teacher/sessions/${id}`, 'PATCH', { status: 'COMPLETED' });
        showMessage(output, 'تم إنهاء الحصة. حدّث الصفحة لمشاهدة التسجيلات بعد جاهزيتها.');
      } else if (action === 'recordings') {
        const { recordings } = await api(`/api/v1/sessions/${id}/recordings`);
        if (!recordings.length) { showMessage(output, 'لا توجد تسجيلات جاهزة لهذه الحصة بعد.'); return; }
        const list = document.createElement('ul');
        recordings.forEach((recording) => {
          const item = document.createElement('li');
          const link = document.createElement('a');
          link.href = `/api/v1/session-recordings/${recording.id}/download`;
          link.textContent = `${recording.title} — تشغيل التسجيل`;
          link.target = '_blank'; link.rel = 'noopener';
          item.append(link); list.append(item);
        });
        output.replaceChildren(list);
      } else if (action === 'join') {
        const { join } = await api(`/api/v1/sessions/${id}/join`, 'POST', {});
        const frame = document.createElement('iframe');
        frame.src = join.meeting_url; frame.title = 'بث الحصة'; frame.allow = 'autoplay; fullscreen; picture-in-picture'; frame.allowFullscreen = true;
        output.replaceChildren(frame);
      }
    } catch (error) { showMessage(output, error.message, true); }
    finally { button.disabled = false; }
  });
})();
