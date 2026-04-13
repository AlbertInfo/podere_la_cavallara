<?php
require_once __DIR__ . '/includes/auth.php';

if (current_admin()) {
    header('Location: ' . admin_url('index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $admin = find_admin_by_email($pdo, $email);
    if ($admin && password_verify($password, $admin['password_hash'])) {
        login_admin($admin);
        set_flash('success', 'Accesso effettuato con successo.');
        header('Location: ' . admin_url('index.php'));
        exit;
    }

    set_flash('error', 'Credenziali non valide.');
    header('Location: ' . admin_url('login.php'));
    exit;
}

$pageTitle = 'Accesso area amministrazione';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-shell">
    <div class="auth-brand">
        <img src="<?= e(admin_url('assets/img/logo.svg')) ?>" alt="Podere La Cavallara">
        <span>Area amministrazione</span>
    </div>
    <div class="card auth-card">
        <img class="auth-card-logo" src="<?= e(admin_url('assets/img/logo.svg')) ?>" alt="Podere La Cavallara">
        <h2>Accedi all’area admin</h2>
        <p class="muted">Inserisci email e password per gestire richieste e prenotazioni.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label>Email
                <input type="email" name="email" placeholder="nome@dominio.it" required>
            </label>
            <label>Password
                <span class="password-field">
                    <input type="password" name="password" placeholder="Inserisci la password" required>
                    <button class="password-toggle" type="button" data-password-toggle aria-label="Mostra password">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 5c5.5 0 9.5 4.5 10.8 6.2a1.3 1.3 0 0 1 0 1.6C21.5 14.5 17.5 19 12 19S2.5 14.5 1.2 12.8a1.3 1.3 0 0 1 0-1.6C2.5 9.5 6.5 5 12 5Zm0 2C8 7 4.8 10 3.3 12 4.8 14 8 17 12 17s7.2-3 8.7-5C19.2 10 16 7 12 7Zm0 2.5A2.5 2.5 0 1 1 9.5 12 2.5 2.5 0 0 1 12 9.5Z" fill="currentColor"/>
                        </svg>
                    </button>
                </span>
            </label>
            <button class="btn btn-primary" type="submit">Accedi</button>
            <a class="small muted" href="<?= e(admin_url('forgot-password.php')) ?>">Hai dimenticato la password?</a>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
