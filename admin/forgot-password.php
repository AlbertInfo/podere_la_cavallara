<?php
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $admin = find_admin_by_email($pdo, $email);

    if ($admin) {
        send_password_reset_email($pdo, $admin);
    }

    set_flash('success', 'Se l’indirizzo esiste, abbiamo inviato il link di recupero password.');
    header('Location: ' . admin_url('forgot-password.php'));
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-shell">
    <div class="card auth-card">
        <h2>Recupero password</h2>
        <p class="muted">Riceverai via email un link temporaneo per impostare una nuova password.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label>Email admin
                <input type="email" name="email" required>
            </label>
            <button class="btn btn-primary" type="submit">Invia link di recupero</button>
            <a class="small muted" href="<?= e(admin_url('login.php')) ?>">Torna al login</a>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
