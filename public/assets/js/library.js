(() => {
  const kind = document.getElementById('library_kind');
  const resourceType = document.getElementById('library_resource_type');
  const link = document.getElementById('library_link_wrap');
  const file = document.getElementById('library_document');
  const url = document.querySelector('[name="external_url"]');
  const lesson = document.getElementById('library_lesson_id');
  const classSubject = document.getElementById('library_class_subject');
  const typeWrap = document.getElementById('library-resource-type-wrap');
  const lessonWrap = document.getElementById('library-lesson-wrap');
  if (!kind || !resourceType || !link || !file || !url || !lesson || !classSubject) return;
  const form = file.closest('form');
  const feedback = document.getElementById('library-upload-feedback');
  const update = () => {
    const isExam = kind.value === 'exam';
    typeWrap.hidden = isExam;
    lessonWrap.hidden = isExam;
    link.hidden = isExam;
    const selectedType = isExam ? 'PDF' : resourceType.value;
    file.accept = selectedType === 'VIDEO'
      ? 'video/mp4,video/webm,video/ogg,video/quicktime,video/x-matroska'
      : selectedType === 'AUDIO'
        ? 'audio/mpeg,audio/mp4,audio/aac,audio/ogg,audio/wav,audio/webm,audio/flac'
        : 'application/pdf,.pdf';
    lesson.required = !isExam && ['VIDEO', 'AUDIO'].includes(selectedType);
    for (const option of lesson.options) {
      if (!option.value) continue;
      option.disabled = classSubject.value !== '' && option.dataset.classSubjectId !== classSubject.value;
      if (option.disabled && option.selected) lesson.value = '';
    }
    file.required = isExam || (kind.value === 'resource' && url.value.trim() === '');
  };
  kind.addEventListener('change', update);
  resourceType.addEventListener('change', update);
  classSubject.addEventListener('change', update);
  url.addEventListener('input', update);
  form?.addEventListener('submit', async (event) => {
    const selected = file.files?.[0];
    if (kind.value !== 'resource' || url.value.trim() !== '') return;
    const isVideo = resourceType.value === 'VIDEO';
    if (!selected) return;
    if (isVideo !== selected.type.startsWith('video/')) {
      event.preventDefault();
      feedback.textContent = isVideo ? 'اختر ملف فيديو صالحًا.' : 'نوع الملف لا يطابق نوع المورد المختار.';
      return;
    }
    if (!isVideo) return;
    event.preventDefault();
    if (selected.size > 200 * 1024 * 1024) {
      feedback.textContent = 'حجم الفيديو يتجاوز 200 ميغابايت في هذه الواجهة.';
      return;
    }
    if (!lesson.value) {
      feedback.textContent = 'اختر الدرس المرتبط بالفيديو.';
      return;
    }
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    feedback.textContent = 'جارٍ تهيئة الرفع إلى Cloudflare Stream…';
    try {
      const values = new FormData(form);
      const csrfResponse = await fetch('/api/v1/csrf', { credentials: 'same-origin' });
      const csrfResult = await csrfResponse.json();
      if (!csrfResponse.ok) throw new Error(csrfResult?.error?.message || 'تعذر تهيئة الطلب الآمن.');
      const csrfToken = csrfResult?.data?.csrf_token || csrfResult?.csrf_token;
      const metadata = {
        title: values.get('title'), description: values.get('description'),
        class_subject_id: Number(values.get('class_subject_id')),
        lesson_id: Number(values.get('lesson_id')),
        source_owner: values.get('source_owner'), source_reference: values.get('source_reference'),
        copyright_license: values.get('copyright_license'),
      };
      const request = async (path, payload) => {
        const response = await fetch(path, { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify(payload) });
        const result = await response.json();
        if (!response.ok) throw new Error(result?.error?.message || 'تعذر تنفيذ طلب الرفع.');
        return result.data;
      };
      const { upload } = await request('/api/v1/content/video-uploads', metadata);
      feedback.textContent = 'جارٍ رفع الفيديو مباشرة إلى مزود الفيديو…';
      const uploadOptions = upload.upload_method === 'PUT'
        ? { method: 'PUT', body: selected }
        : (() => { const payload = new FormData(); payload.append('file', selected, selected.name); return { method: 'POST', body: payload }; })();
      const providerResponse = await fetch(upload.upload_url, uploadOptions);
      if (!providerResponse.ok) throw new Error('فشل رفع الفيديو إلى مزود الفيديو. أعد المحاولة.');
      feedback.textContent = 'اكتمل الرفع. جارٍ تجهيز الفيديو وإرساله للمراجعة…';
      const { resource } = await request('/api/v1/content/video-uploads/complete', { upload_token: upload.upload_token });
      await request(`/api/v1/content/resources/${resource.id}/submit`, {});
      feedback.textContent = 'تم رفع الفيديو وإرساله للمراجعة. سيظهر للطلاب بعد اعتماده.';
      form.reset(); update();
    } catch (error) {
      feedback.textContent = error instanceof Error ? error.message : 'تعذر رفع الفيديو الآن.';
    } finally { button.disabled = false; }
  });
  update();
})();
