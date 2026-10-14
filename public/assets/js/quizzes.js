(() => {
  document.querySelectorAll('.quiz-question-choice').forEach((row) => {
    const selected = row.querySelector('input[type="checkbox"]');
    const mark = row.querySelector('[data-mark-for]');
    const sync = () => { mark.disabled = !selected.checked; };
    selected.addEventListener('change', sync);
    sync();
  });
})();
