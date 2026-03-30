<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();
$pageTitle = 'Nuova prenotazione';
require_once __DIR__ . '/includes/header.php';
?>
<div class="booking-page">
    <div class="booking-hero">
        <div class="booking-hero-copy">
            <h1>Nuova prenotazione</h1>
            <p class="muted">Inserisci manualmente una prenotazione e salvala direttamente nel database.</p>
        </div>
        <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Torna alle prenotazioni</a>
    </div>

    <form class="booking-form" method="post" action="<?= e(admin_url('actions/create-prenotazione.php')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

        <section class="form-section">
            <h2 class="form-section-title">Soggiorno</h2>
            <div class="booking-form-grid">
                <label>
                    Periodo soggiorno *
                    <input class="js-date-range" type="text" name="stay_period" placeholder="Seleziona check-in e check-out" readonly required>
                </label>

                <label>
                    Soluzione *
                    <select name="room_type" required>
                        <option value="">Scegli soluzione</option>
                        <option>Casa Domenico 1</option>
                        <option>Casa Domenico 2</option>
                        <option>Casa Domenico 1-2</option>
                        <option>Casa Riccardo 3</option>
                        <option>Casa Riccardo 4</option>
                        <option>Casa Alessandro 5</option>
                        <option>Casa Alessandro 6</option>
                    </select>
                </label>

                <label>
                    Adulti *
                    <input type="number" name="adults" min="1" step="1" required>
                </label>

                <label>
                    Bambini
                    <input type="number" name="children_count" min="0" step="1" value="0">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Ospite</h2>
            <div class="booking-form-grid">
                <label>
                    Nome e cognome *
                    <input type="text" name="customer_name" required>
                </label>

                <label>
                    Email *
                    <input type="email" name="customer_email" required>
                </label>

                <label>
                    Telefono
                    <input type="text" name="customer_phone">
                </label>
            </div>
        </section>

        <section class="form-section">
            <h2 class="form-section-title">Gestione prenotazione</h2>
            <div class="booking-form-grid">
                <label>
                    Stato *
                    <select name="status" required>
                        <option value="confermata">Confermata</option>
                        <option value="in_attesa">In attesa</option>
                        <option value="annullata">Annullata</option>
                    </select>
                </label>

                <label>
                    Origine *
                    <select name="source" required>
                        <option value="manual_admin">Inserimento manuale</option>
                        <option value="website_admin">Area admin</option>
                        <option value="booking.com">Booking.com</option>
                        <option value="phone">Telefono</option>
                        <option value="email">Email</option>
                    </select>
                </label>

                <label>
                    Riferimento esterno
                    <input type="text" name="external_reference">
                </label>

                <label class="full">
                    Note
                    <textarea name="notes" placeholder="Aggiungi eventuali note interne sulla prenotazione"></textarea>
                </label>
            </div>
        </section>

        <div class="form-actions">
            <a class="btn btn-light" href="<?= e(admin_url('index.php#registered-bookings')) ?>">Annulla</a>
            <button class="btn btn-primary" type="submit">Salva prenotazione</button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
