document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.import-row[data-href]').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (e.target.closest('a, button, input, select, textarea, form, label')) {
        return;
      }
      const href = row.getAttribute('data-href');
      if (href) {
        window.location.href = href;
      }
    });
  });
});
