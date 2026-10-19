(() => {
  const dialog = document.getElementById('staff-delete-dialog');
  const form = document.getElementById('staff-delete-form');
  const targetId = document.getElementById('staff-delete-user-id');
  const targetName = document.getElementById('staff-delete-user-name');
  const password = document.getElementById('staff-delete-password');

  if (!dialog || !form || !targetId || !targetName || !password) return;

  let opener = null;
  document.querySelectorAll('.staff-delete-trigger').forEach((button) => {
    button.addEventListener('click', () => {
      opener = button;
      targetId.value = button.dataset.userId || '';
      targetName.textContent = button.dataset.userName || '';
      password.value = '';
      dialog.showModal();
      password.focus();
    });
  });

  dialog.querySelector('[data-close-staff-delete]')?.addEventListener('click', () => {
    dialog.close();
  });

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });

  dialog.addEventListener('close', () => {
    form.reset();
    if (opener?.isConnected) opener.focus();
    opener = null;
  });
})();
