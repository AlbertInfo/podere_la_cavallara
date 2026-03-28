<?php
require_once __DIR__ . '/includes/auth.php';

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$validation = ($email && $token) ? validate_password_reset($pdo, $email, $token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$validation) {
        set_flash('error', 'Link di reset non valido o scaduto.');
        header('Location: ' . admin_url('forgot-password.php'));
        exit;
    }

    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirmation'] ?? '');

    if (strlen($password) < 10 || $password !== $passwordConfirm) {
        set_flash('error', 'La password deve essere di almeno 10 caratteri e i campi devono coincidere.');
        header('Location: ' . admin_url('reset-password.php') . '?email=' . urlencode($email) . '&token=' . urlencode($token));
        exit;
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE admin_users SET password_hash = :password_hash WHERE id = :id');
    $stmt->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'id' => $validation['admin']['id'],
    ]);

    $stmt = $pdo->prepare('UPDATE admin_password_resets SET used_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $validation['reset']['id']]);
    $pdo->commit();

    set_flash('success', 'Password aggiornata correttamente. Ora puoi accedere.');
    header('Location: ' . admin_url('login.php'));
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-shell">
    <div class="card auth-card">
        <h2>Imposta nuova password</h2>
        <?php if (!$validation): ?>
            <p class="muted">Il link non è valido oppure è scaduto.</p>
            <a class="btn btn-primary" href="<?= e(admin_url('forgot-password.php')) ?>">Richiedi un nuovo link</a>
        <?php else: ?>
            <form method="post" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="email" value="<?= e($email) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label>Nuova password
                    <input type="password" name="password" required minlength="10">
                </label>
                <label>Conferma password
                    <input type="password" name="password_confirmation" required minlength="10">
                </label>
                <button class="btn btn-primary" type="submit">Salva nuova password</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
