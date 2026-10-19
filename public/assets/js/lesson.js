(() => {
  const status = document.querySelector('[data-view-status]');
  const recordView = async (resourceId) => {
    try {
      const csrfResponse = await fetch('/api/v1/csrf', { credentials: 'same-origin' });
      const csrfResult = await csrfResponse.json();
      if (!csrfResponse.ok) throw new Error(csrfResult?.error?.message || 'تعذر تسجيل المشاهدة.');
      const csrfToken = csrfResult?.data?.csrf_token || csrfResult?.csrf_token;
      const response = await fetch(`/api/v1/content/resources/${encodeURIComponent(resourceId)}/views`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: '{}',
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result?.error?.message || 'تعذر تسجيل المشاهدة.');
    } catch {
      if (status) {
        status.textContent = 'تعذر تسجيل المشاهدة، لكن يمكنك متابعة الدرس.';
        status.classList.remove('visually-hidden');
      }
    }
  };

  document.querySelectorAll('[data-media-audio]').forEach((audio) => {
    audio.addEventListener('play', () => {
      if (audio.dataset.viewStarted) return;
      audio.dataset.viewStarted = 'true';
      void recordView(audio.dataset.resourceId);
    });
  });

  document.querySelectorAll('[data-media-video-start]').forEach((button) => {
    button.addEventListener('click', () => {
      const frame = button.closest('.learning-video-frame');
      const player = frame?.querySelector('[data-media-player]');
      if (!player || !button.dataset.src || !button.dataset.resourceId) return;
      player.src = button.dataset.src;
      player.hidden = false;
      button.hidden = true;
      void recordView(button.dataset.resourceId);
    });
  });
})();
