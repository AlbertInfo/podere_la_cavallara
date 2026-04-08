document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.interhome-import-row[data-row-href]').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (e.target.closest('a, button, form, input, select, textarea, label')) return;
      const href = row.getAttribute('data-row-href');
      if (href) window.location.href = href;
    });
  });

  const modal = document.querySelector('[data-interhome-modal]');
  const confirmBtn = document.querySelector('[data-interhome-modal-confirm]');
  if (modal && confirmBtn) {
    confirmBtn.addEventListener('click', function () {
      modal.style.display = 'none';
    });
  }
});
