<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$flash = function_exists('get_flash') ? get_flash() : null;
$roomOptions = [
    'Casa Domenico 1',
    'Casa Domenico 2',
    'Casa Domenico 1-2',
    'Casa Riccardo 3',
    'Casa Riccardo 4',
    'Casa Alessandro 5',
    'Casa Alessandro 6',
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(ADMIN_APP_NAME) ?> - Nuova prenotazione</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="<?= e(admin_url('assets/css/admin-modern.css')) ?>">
</head>
<body class="admin-modern">
<div class="admin-shell">
  <div class="admin-wrap">
    <div class="admin-topbar">
      <a class="brand" href="<?= e(admin_url('index.php')) ?>">
        <img src="<?= e(admin_url('assets/img/logo_sticky.png')) ?>" alt="Podere La Cavallara">
        <div class="brand-copy">
          <strong>Area Admin</strong>
          <span>Gestione prenotazioni</span>
        </div>
      </a>
      <div class="topbar-actions">
        <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alla dashboard</a>
        <a class="btn btn-primary" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Vedi prenotazioni</a>
      </div>
    </div>

    <div class="admin-card">
      <div class="admin-card-header">
        <div class="page-kicker">Prenotazioni</div>
        <h1 class="page-title">Aggiungi una nuova prenotazione</h1>
        <p class="page-subtitle">Compila manualmente la prenotazione e salvala direttamente nel database, con un’esperienza più ordinata e veloce anche da mobile.</p>
        
      </div>
      <div class="admin-card-body">
        <?php if ($flash): ?>
          <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        
<form method="post" action="<?= e(admin_url('actions/create-prenotazione.php')) ?>">
  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
  <div class="form-grid">
    <div class="col-12 section-card">
      <h3>Soggiorno</h3>
      <p>Inserisci il periodo e la soluzione prenotata. Il campo data usa un datepicker con selezione intervallo.</p>
      <div class="form-grid">
        <div class="col-6 field">
          <label for="stay_period">Check in / Check out</label>
          <input type="text" id="stay_period" name="stay_period" placeholder="Seleziona il periodo" required>
          <div class="field-hint">Formato automatico: gg/mm/aaaa - gg/mm/aaaa</div>
        </div>
        <div class="col-6 field">
          <label for="room_type">Soluzione</label>
          <select id="room_type" name="room_type" required>
            <option value="">Scegli soluzione</option>
            <?php foreach ($roomOptions as $option): ?>
              <option value="<?= e($option) ?>"><?= e($option) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 field">
          <label for="adults">Adulti</label>
          <input type="number" id="adults" name="adults" min="1" step="1" required>
        </div>
        <div class="col-6 field">
          <label for="children_count">Bambini</label>
          <input type="number" id="children_count" name="children_count" min="0" step="1" value="0" required>
        </div>
      </div>
    </div>

    <div class="col-12 section-card">
      <h3>Cliente</h3>
      <p>Dati principali per contattare l’ospite e riconoscere la prenotazione.</p>
      <div class="form-grid">
        <div class="col-6 field">
          <label for="customer_name">Nome e cognome</label>
          <input type="text" id="customer_name" name="customer_name" required>
        </div>
        <div class="col-6 field">
          <label for="customer_email">Email</label>
          <input type="email" id="customer_email" name="customer_email" required>
        </div>
        <div class="col-12 field">
          <label for="customer_phone">Telefono</label>
          <input type="text" id="customer_phone" name="customer_phone" placeholder="Facoltativo">
        </div>
      </div>
    </div>

    <div class="col-12 section-card">
      <h3>Gestione</h3>
      <p>Campi interni per stato, origine della prenotazione e note operative.</p>
      <div class="form-grid">
        <div class="col-4 field">
          <label for="status">Stato</label>
          <select id="status" name="status" required>
            <option value="confermata">Confermata</option>
            <option value="in_attesa">In attesa</option>
            <option value="annullata">Annullata</option>
          </select>
        </div>
        <div class="col-4 field">
          <label for="source">Origine</label>
          <input type="text" id="source" name="source" value="manual_admin" required>
        </div>
        <div class="col-4 field">
          <label for="external_reference">Riferimento esterno</label>
          <input type="text" id="external_reference" name="external_reference" placeholder="Facoltativo">
        </div>
        <div class="col-12 field">
          <label for="notes">Note</label>
          <textarea id="notes" name="notes" placeholder="Aggiungi note interne, preferenze, dettagli pagamento..."></textarea>
        </div>
      </div>
    </div>
  </div>
  <div class="form-actions">
    <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Annulla</a>
    <button class="btn btn-primary" type="submit">Salva prenotazione</button>
  </div>
</form>

      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
  flatpickr('#stay_period', {
    mode: 'range',
    dateFormat: 'd/m/Y',
    allowInput: true,
    locale: { firstDayOfWeek: 1 },
    conjunction: ' - '
  });
</script>
</body>
</html>
