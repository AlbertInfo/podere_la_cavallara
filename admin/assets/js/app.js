document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-table-filter]').forEach(function (input) {
    input.addEventListener('input', function () {
      const selector = input.getAttribute('data-table-filter');
      const table = document.querySelector(selector);
      if (!table) return;
      const term = input.value.trim().toLowerCase();
      table.querySelectorAll('tbody tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
      });
    });
  });

  document.addEventListener('submit', async function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.hasAttribute('data-ajax-action')) return;

    event.preventDefault();
    const message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) return;

    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        body: new FormData(form)
      });

      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Operazione non riuscita.');
      }

      showToast(data.message || 'Operazione completata.', 'success');
      if (form.getAttribute('data-success-remove-row') === '1') {
        const row = form.closest('tr');
        if (row) {
          row.style.opacity = '0';
          setTimeout(() => row.remove(), 250);
        }
      }
    } catch (error) {
      showToast(error.message || 'Errore imprevisto.', 'error');
      if (button) button.disabled = false;
    }
  });

  function showToast(message, type) {
    let wrap = document.querySelector('.toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'toast-wrap';
      document.body.appendChild(wrap);
    }
    const el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.textContent = message;
    wrap.appendChild(el);
    setTimeout(() => {
      el.classList.add('hide');
      setTimeout(() => el.remove(), 250);
    }, 2800);
  }
});
