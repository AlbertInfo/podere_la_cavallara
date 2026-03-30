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
    <div class="card auth-card">
        <img class="sidebar-logo sidebar-logo-desktop" src="<?= e(admin_url('assets/img/logo_sticky.png')) ?>" alt="Podere La Cavallara">
        <h2>Accedi all’area admin</h2>
        <p class="muted">Inserisci email e password per gestire richieste e prenotazioni.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label>Email
                <input type="email" name="email" placeholder="nome@dominio.it" required>
            </label>
            <label>Password
                <input type="password" name="password" placeholder="Inserisci la password" required>
            </label>
            <button class="btn btn-primary" type="submit">Accedi</button>
            <a class="small muted" href="<?= e(admin_url('forgot-password.php')) ?>">Hai dimenticato la password?</a>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
