(() => {
  const display = document.getElementById('quiz-countdown');
  if (!display) return;
  const deadline = Date.parse(display.dataset.deadline);
  if (!Number.isFinite(deadline)) return;
  const render = () => {
    const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
    if (remaining === 0) {
      display.textContent = 'انتهى الوقت. سلّم الاختبار الآن؛ سيعتمد النظام الإجابات المحفوظة.';
      display.classList.add('expired');
      return false;
    }
    const minutes = Math.floor(remaining / 60);
    const seconds = remaining % 60;
    display.textContent = `الوقت المتبقي: ${minutes}:${String(seconds).padStart(2, '0')}`;
    return true;
  };
  if (render()) {
    const timer = window.setInterval(() => { if (!render()) window.clearInterval(timer); }, 1000);
  }
})();
