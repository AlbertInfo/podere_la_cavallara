<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();
verify_csrf();

$pdfId = (int) ($_POST['pdf_id'] ?? 0);
$displayName = trim((string) ($_POST['display_name'] ?? ''));

if ($pdfId <= 0) {
    set_flash('error', 'PDF non valido.');
    header('Location: ' . admin_url('file-manager.php'));
    exit;
}

if ($displayName === '') {
    $stmt = $pdo->prepare('SELECT original_name FROM admin_imported_pdfs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $pdfId]);
    $originalName = (string) ($stmt->fetchColumn() ?: 'PDF Interhome');
    $displayName = pathinfo($originalName, PATHINFO_FILENAME);
}

$displayName = mb_substr($displayName, 0, 180);

$stmt = $pdo->prepare('UPDATE admin_imported_pdfs SET display_name = :display_name WHERE id = :id LIMIT 1');
$stmt->execute([
    'display_name' => $displayName,
    'id' => $pdfId,
]);

set_flash('success', 'Nome PDF aggiornato correttamente.');
header('Location: ' . admin_url('file-manager.php'));
exit;
