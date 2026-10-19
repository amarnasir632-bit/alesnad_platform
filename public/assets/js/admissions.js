(() => {
  document.querySelectorAll('[data-load-admission-history]').forEach((button) => {
    button.addEventListener('click', async () => {
      const details = button.closest('[data-student-id]');
      const target = details.querySelector('.admission-history-content');
      button.disabled = true;
      try {
        const response = await fetch(`/api/v1/admin/admissions/${details.dataset.studentId}`, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload?.error?.message || 'تعذر تحميل سجل الطلب.');
        target.replaceChildren();
        const list = document.createElement('ul');
        for (const event of payload.data.history) {
          const item = document.createElement('li');
          const from = event.previous_status || 'تسجيل جديد';
          item.textContent = `${from} ← ${event.new_status} · ${event.reason || 'دون ملاحظة'} · ${event.created_at}`;
          list.append(item);
        }
        for (const request of payload.data.corrections) {
          const item = document.createElement('li');
          item.textContent = `طلب تصحيح ${request.id}: ${request.status} · ${request.reason}`;
          list.append(item);
        }
        if (!list.children.length) {
          const empty = document.createElement('p');
          empty.textContent = 'لا يوجد سجل سابق.';
          target.append(empty);
        } else {
          target.append(list);
        }
        button.hidden = true;
      } catch (error) {
        target.textContent = error.message;
      } finally {
        button.disabled = false;
      }
    });
  });
})();
