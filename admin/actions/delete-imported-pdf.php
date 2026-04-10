<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();
verify_csrf();

$pdfId = (int) ($_POST['pdf_id'] ?? 0);
if ($pdfId <= 0) {
    set_flash('error', 'PDF non valido.');
    header('Location: ' . admin_url('file-manager.php'));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM admin_imported_pdfs WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $pdfId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    set_flash('error', 'PDF non trovato nell\'archivio.');
    header('Location: ' . admin_url('file-manager.php'));
    exit;
}

$relativePath = ltrim((string) ($file['relative_path'] ?? ''), '/');
$absolutePath = dirname(__DIR__) . '/' . $relativePath;
$fileDeleted = true;

if ($relativePath !== '' && is_file($absolutePath)) {
    $fileDeleted = @unlink($absolutePath);
}

$deleteStmt = $pdo->prepare('DELETE FROM admin_imported_pdfs WHERE id = :id LIMIT 1');
$deleteStmt->execute(['id' => $pdfId]);

if ($fileDeleted) {
    set_flash('success', 'PDF eliminato dall\'archivio.');
} else {
    set_flash('success', 'Record eliminato dall\'archivio. Il file fisico non era più presente sul server.');
}

header('Location: ' . admin_url('file-manager.php'));
exit;
