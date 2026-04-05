document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      const message = form.getAttribute('data-confirm') || 'Confermi questa operazione?';
      if (!window.confirm(message)) e.preventDefault();
    });
  });
});
