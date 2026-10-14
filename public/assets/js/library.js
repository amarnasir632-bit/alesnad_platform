(() => {
  const kind = document.getElementById('library_kind');
  const link = document.getElementById('library_link_wrap');
  const file = document.getElementById('library_document');
  const url = document.querySelector('[name="external_url"]');
  if (!kind || !link || !file || !url) return;
  const update = () => {
    link.hidden = kind.value === 'exam';
    file.required = kind.value === 'exam' || url.value.trim() === '';
  };
  kind.addEventListener('change', update);
  url.addEventListener('input', update);
  update();
})();
