document.addEventListener('DOMContentLoaded', function () {
  var countryInputs = document.querySelectorAll('[data-country-code-input]');
  countryInputs.forEach(function (input) {
    input.addEventListener('input', function () {
      input.value = input.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2);
    });
  });

  document.querySelectorAll('[data-anagrafica-reset]').forEach(function (button) {
    button.addEventListener('click', function () {
      var form = button.closest('form');
      if (!form) return;
      window.requestAnimationFrame(function () {
        var first = form.querySelector('input, textarea, select');
        if (first) first.focus();
      });
    });
  });
});
