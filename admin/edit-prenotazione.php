<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Prenotazione non valida.');
    header('Location: ' . admin_url('index.php') . '#registered-bookings');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM prenotazioni WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    set_flash('error', 'Prenotazione non trovata.');
    header('Location: ' . admin_url('index.php') . '#registered-bookings');
    exit;
}

$roomOptions = [
    'Casa Domenico 1',
    'Casa Domenico 2',
    'Casa Domenico 1-2',
    'Casa Riccardo 3',
    'Casa Riccardo 4',
    'Casa Alessandro 5',
    'Casa Alessandro 6',
];

require_once __DIR__ . '/includes/header.php';
?>
<section class="card" style="max-width:900px;margin:0 auto;">
    <div class="section-title">
        <div>
            <h2>Modifica prenotazione</h2>
            <p class="muted">Puoi aggiornare manualmente tutti i dati della prenotazione e salvare nel database.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('index.php')) ?>#registered-bookings">Torna alla dashboard</a>
    </div>

    <form method="post" action="<?= e(admin_url('actions/update-prenotazione.php')) ?>" class="form-grid" style="margin-top:20px;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="prenotazione_id" value="<?= (int)$booking['id'] ?>">

        <label>Check in / Check out
            <input type="text" name="stay_period" value="<?= e($booking['stay_period']) ?>" required>
        </label>

        <label>Soluzione
            <select name="room_type" required>
                <?php foreach ($roomOptions as $option): ?>
                    <option value="<?= e($option) ?>" <?= $booking['room_type'] === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
                <?php if (!in_array($booking['room_type'], $roomOptions, true)): ?>
                    <option value="<?= e($booking['room_type']) ?>" selected><?= e($booking['room_type']) ?></option>
                <?php endif; ?>
            </select>
        </label>

        <label>Adulti
            <input type="number" min="0" name="adults" value="<?= (int)$booking['adults'] ?>" required>
        </label>

        <label>Bambini
            <input type="number" min="0" name="children_count" value="<?= (int)$booking['children_count'] ?>" required>
        </label>

        <label>Nome e cognome
            <input type="text" name="customer_name" value="<?= e($booking['customer_name']) ?>" required>
        </label>

        <label>Email
            <input type="email" name="customer_email" value="<?= e($booking['customer_email']) ?>" required>
        </label>

        <label>Telefono
            <input type="text" name="customer_phone" value="<?= e($booking['customer_phone'] ?? '') ?>">
        </label>

        <label>Stato
            <select name="status" required>
                <?php foreach (['confermata', 'in_attesa', 'annullata'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $booking['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Origine
            <input type="text" name="source" value="<?= e($booking['source']) ?>" required>
        </label>

        <label>Riferimento esterno
            <input type="text" name="external_reference" value="<?= e($booking['external_reference'] ?? '') ?>">
        </label>

        <label style="grid-column:1 / -1;">Note
            <textarea name="notes" rows="5"><?= e($booking['notes'] ?? '') ?></textarea>
        </label>

        <div style="grid-column:1 / -1; display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn btn-primary" type="submit">Salva modifiche</button>
            <a class="btn btn-light" href="<?= e(admin_url('index.php')) ?>#registered-bookings">Annulla</a>
        </div>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
