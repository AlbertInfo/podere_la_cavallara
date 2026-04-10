<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM prenotazioni WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$prenotazione = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prenotazione) {
    set_flash('error', 'Prenotazione non trovata.');
    header('Location: ' . admin_url('index.php#registered-bookings'));
    exit;
}

$pageTitle = 'Modifica prenotazione';
require_once __DIR__ . '/includes/header.php';
?>
<div class="booking-page">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Modifica prenotazione</h1>
            <p class="muted">Aggiorna i dati della prenotazione e salva le modifiche nel database.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alle prenotazioni</a>
    </div>

    <form class="booking-form" method="post" action="<?= e(admin_url('actions/update-prenotazione.php')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="prenotazione_id" value="<?= (int)$prenotazione['id'] ?>">

        <section class="form-section">
            <h2 class="form-section-title">Soggiorno</h2>
            <div class="booking-form-grid">
                <label>
                    Periodo soggiorno *
                    <input class="js-date-range" type="text" name="stay_period" value="<?= e($prenotazione['stay_period']) ?>" readonly required>
                </label>

                <label>
                    Soluzione *
                    <select name="room_type" required>
                        <?php $rooms = ['Casa Domenico 1','Casa Domenico 2','Casa Domenico 1-2','Casa Riccardo 3','Casa Riccardo 4','Casa Alessandro 5','Casa Alessandro 6']; ?>
                        <option value="">Scegli soluzione</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= e($room) ?>" <?= $prenotazione['room_type'] === $room ? 'selected' : '' ?>><?= e($room) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Adulti *
                    <input type="number" name="adults" min="1" step="1" value="<?= (int)$prenotazione['adults'] ?>" required>
                </label>

                <label>
                    Bambini
                    <input type="number" name="children_count" min="0" step="1" value="<?= (int)$prenotazione['children_count'] ?>">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Ospite</h2>
            <div class="booking-form-grid">
                <label>
                    Nome e cognome *
                    <input type="text" name="customer_name" value="<?= e($prenotazione['customer_name']) ?>" required>
                </label>

                <label>
                    Email
                    <input type="email" name="customer_email" value="<?= e($prenotazione['customer_email'] ?? '') ?>" placeholder="Non disponibile">
                </label>

                <label>
                    Telefono
                    <input type="text" name="customer_phone" value="<?= e($prenotazione['customer_phone'] ?? '') ?>">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Gestione prenotazione</h2>
            <div class="booking-form-grid">
                <label>
                    Stato *
                    <select name="status" required>
                        <?php $statuses = ['confermata' => 'Confermata', 'in_attesa' => 'In attesa', 'annullata' => 'Annullata']; ?>
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $prenotazione['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Origine *
                    <input type="text" name="source" value="<?= e($prenotazione['source']) ?>" required>
                </label>

                <label>
                    Riferimento esterno
                    <input type="text" name="external_reference" value="<?= e($prenotazione['external_reference'] ?? '') ?>">
                </label>

                <label class="full">
                    Note
                    <textarea name="notes"><?= e($prenotazione['notes'] ?? '') ?></textarea>
                </label>
            </div>
        </section>

        <div class="form-actions">
            <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Annulla</a>
            <button class="btn btn-primary" type="submit">Salva modifiche</button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
