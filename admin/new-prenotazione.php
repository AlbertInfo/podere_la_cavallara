<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$roomOptions = [
    'Casa Domenico 1',
    'Casa Domenico 2',
    'Casa Domenico 1-2',
    'Casa Riccardo 3',
    'Casa Riccardo 4',
    'Casa Alessandro 5',
    'Casa Alessandro 6',
];

$statuses = [
    'confermata' => 'Confermata',
    'in_attesa' => 'In attesa',
    'annullata' => 'Annullata',
];

$old = [
    'stay_period' => '',
    'room_type' => '',
    'adults' => '2',
    'children_count' => '0',
    'customer_name' => '',
    'customer_email' => '',
    'customer_phone' => '',
    'status' => 'confermata',
    'source' => 'manual_admin',
    'external_reference' => '',
    'notes' => '',
];

require_once __DIR__ . '/includes/header.php';
?>
<style>
.pren-form-shell { max-width: 980px; margin: 0 auto; }
.pren-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:20px; flex-wrap:wrap; }
.pren-head h1 { margin:0; }
.pren-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:18px; }
.pren-card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:22px; box-shadow:0 10px 30px rgba(17,24,39,.05); }
.pren-card h3 { margin:0 0 16px; font-size:18px; }
.form-grid-2 { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; }
.field { display:flex; flex-direction:column; gap:8px; }
.field label { font-size:13px; font-weight:700; color:#374151; }
.field input, .field select, .field textarea {
    width:100%; border:1px solid #d1d5db; border-radius:12px; padding:12px 14px; font:inherit; background:#fff;
}
.field textarea { min-height:120px; resize:vertical; }
.field input:focus, .field select:focus, .field textarea:focus {
    outline:none; border-color:#111827; box-shadow:0 0 0 4px rgba(17,24,39,.08);
}
.span-2 { grid-column:span 2; }
.form-actions { display:flex; gap:12px; justify-content:flex-end; margin-top:20px; flex-wrap:wrap; }
.btn { border:0; border-radius:12px; padding:12px 18px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
.btn-primary { background:#111827; color:#fff; }
.btn-light { background:#f3f4f6; color:#111827; }
.help { color:#6b7280; font-size:13px; margin-top:6px; }
@media (max-width: 900px){ .pren-grid, .form-grid-2 { grid-template-columns:1fr; } .span-2 { grid-column:span 1; } }
</style>

<div class="pren-form-shell">
    <div class="pren-head">
        <div>
            <h1>Nuova prenotazione</h1>
            <p class="muted">Inserisci manualmente una prenotazione e salvala direttamente nel database.</p>
        </div>
        <div class="actions">
            <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alle prenotazioni</a>
        </div>
    </div>

    <form method="post" action="<?= e(admin_url('actions/create-prenotazione.php')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

        <div class="pren-grid">
            <section class="pren-card">
                <h3>Soggiorno</h3>
                <div class="form-grid-2">
                    <div class="field span-2">
                        <label for="stay_period">Check in / Check out</label>
                        <input type="text" id="stay_period" name="stay_period" value="<?= e($old['stay_period']) ?>" placeholder="Es. 12/08/2026 - 18/08/2026" required>
                        <div class="help">Manteniamo lo stesso formato già usato dal form pubblico.</div>
                    </div>

                    <div class="field span-2">
                        <label for="room_type">Soluzione</label>
                        <select id="room_type" name="room_type" required>
                            <option value="">Scegli soluzione</option>
                            <?php foreach ($roomOptions as $option): ?>
                                <option value="<?= e($option) ?>"><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="adults">Adulti</label>
                        <input type="number" id="adults" name="adults" min="1" step="1" value="<?= e($old['adults']) ?>" required>
                    </div>

                    <div class="field">
                        <label for="children_count">Bambini</label>
                        <input type="number" id="children_count" name="children_count" min="0" step="1" value="<?= e($old['children_count']) ?>" required>
                    </div>
                </div>
            </section>

            <section class="pren-card">
                <h3>Cliente</h3>
                <div class="form-grid-2">
                    <div class="field span-2">
                        <label for="customer_name">Nome e cognome</label>
                        <input type="text" id="customer_name" name="customer_name" value="<?= e($old['customer_name']) ?>" required>
                    </div>

                    <div class="field">
                        <label for="customer_email">Email</label>
                        <input type="email" id="customer_email" name="customer_email" value="<?= e($old['customer_email']) ?>" required>
                    </div>

                    <div class="field">
                        <label for="customer_phone">Telefono</label>
                        <input type="text" id="customer_phone" name="customer_phone" value="<?= e($old['customer_phone']) ?>" placeholder="Facoltativo">
                    </div>
                </div>
            </section>

            <section class="pren-card span-2">
                <h3>Gestione prenotazione</h3>
                <div class="form-grid-2">
                    <div class="field">
                        <label for="status">Stato</label>
                        <select id="status" name="status" required>
                            <?php foreach ($statuses as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $old['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="source">Origine</label>
                        <input type="text" id="source" name="source" value="<?= e($old['source']) ?>" required>
                    </div>

                    <div class="field span-2">
                        <label for="external_reference">Riferimento esterno</label>
                        <input type="text" id="external_reference" name="external_reference" value="<?= e($old['external_reference']) ?>" placeholder="Facoltativo: codice interno, channel manager, Booking.com...">
                    </div>

                    <div class="field span-2">
                        <label for="notes">Note</label>
                        <textarea id="notes" name="notes" placeholder="Informazioni interne, dettagli ospite, richieste particolari..."><?= e($old['notes']) ?></textarea>
                    </div>
                </div>
            </section>
        </div>

        <div class="form-actions">
            <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Annulla</a>
            <button class="btn btn-primary" type="submit">Salva prenotazione</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
