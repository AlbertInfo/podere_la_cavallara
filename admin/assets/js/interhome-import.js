document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.import-row[data-row-link]').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (e.target.closest('a, button, input, select, textarea, form, label')) {
        return;
      }
      const href = row.getAttribute('data-row-link');
      if (href) {
        window.location.href = href;
      }
    });
  });

  if (typeof flatpickr !== 'undefined') {
    document.querySelectorAll('.js-date-range').forEach(function (input) {
      if (input.dataset.flatpickrBound === '1') return;
      flatpickr(input, {
        mode: 'range',
        dateFormat: 'd/m/Y',
        allowInput: true,
        locale: 'it'
      });
      input.dataset.flatpickrBound = '1';
    });
  }
});