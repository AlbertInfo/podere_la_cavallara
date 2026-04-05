<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('import-interhome-pdf.php'));
    exit;
}

verify_csrf();

$ref = trim((string)($_POST['external_reference'] ?? ''));
$rows = $_SESSION['interhome_import']['rows'] ?? [];

if ($ref !== '' && is_array($rows)) {
    $_SESSION['interhome_import']['rows'] = array_values(array_filter($rows, static function ($row) use ($ref) {
        return trim((string)($row['external_reference'] ?? '')) !== $ref;
    }));
}

set_flash('success', 'Riga rimossa dall’elenco del PDF.');
header('Location: ' . admin_url('import-interhome-pdf.php'));
exit;
?>