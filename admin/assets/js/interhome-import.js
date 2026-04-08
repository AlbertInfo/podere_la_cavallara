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


document.addEventListener('DOMContentLoaded', function () {
  const toggle = document.querySelector('[data-pdf-toggle]');
  const workspace = document.querySelector('[data-pdf-workspace]');
  if (toggle && workspace) {
    toggle.addEventListener('click', function () {
      workspace.classList.toggle('is-collapsed');
      const collapsed = workspace.classList.contains('is-collapsed');
      toggle.setAttribute('aria-expanded', String(!collapsed));
      toggle.textContent = collapsed ? 'Apri PDF' : 'Chiudi PDF';
    });
  }
});
