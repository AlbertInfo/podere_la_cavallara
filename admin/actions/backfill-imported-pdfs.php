<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_admin();
verify_csrf();

try {
    $pdo->query('SELECT 1 FROM admin_imported_pdfs LIMIT 1');
} catch (Throwable $e) {
    set_flash('error', 'Archivio PDF non attivo. Esegui prima la migration SQL.');
    header('Location: ' . admin_url('file-manager.php'));
    exit;
}

$currentAdmin = current_admin();
$adminId = (int) ($currentAdmin['id'] ?? 0) ?: null;
$adminRoot = dirname(__DIR__);
$scanDirectories = [
    $adminRoot . '/storage/imports',
    $adminRoot . '/uploads/interhome',
    $adminRoot . '/uploads',
];

$existingStmt = $pdo->query('SELECT relative_path FROM admin_imported_pdfs');
$existingPaths = array_fill_keys($existingStmt->fetchAll(PDO::FETCH_COLUMN), true);

$insertStmt = $pdo->prepare(
    'INSERT INTO admin_imported_pdfs (
        source,
        original_name,
        display_name,
        stored_name,
        relative_path,
        mime_type,
        file_size,
        file_hash_sha1,
        parser_status,
        uploaded_by_admin_id
    ) VALUES (
        :source,
        :original_name,
        :display_name,
        :stored_name,
        :relative_path,
        :mime_type,
        :file_size,
        :file_hash_sha1,
        :parser_status,
        :uploaded_by_admin_id
    )'
);

$added = 0;
$skipped = 0;
$seenAbsolute = [];

foreach ($scanDirectories as $directory) {
    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        if (strtolower($fileInfo->getExtension()) !== 'pdf') {
            continue;
        }

        $absolutePath = $fileInfo->getPathname();
        if (isset($seenAbsolute[$absolutePath])) {
            continue;
        }
        $seenAbsolute[$absolutePath] = true;

        $relativePath = ltrim(str_replace($adminRoot, '', $absolutePath), DIRECTORY_SEPARATOR);
        $relativePath = str_replace('\\', '/', $relativePath);

        if (isset($existingPaths[$relativePath])) {
            $skipped++;
            continue;
        }

        $basename = $fileInfo->getBasename();
        $displayName = pathinfo($basename, PATHINFO_FILENAME);

        $insertStmt->execute([
            'source' => 'interhome_pdf',
            'original_name' => $basename,
            'display_name' => $displayName,
            'stored_name' => $basename,
            'relative_path' => $relativePath,
            'mime_type' => function_exists('mime_content_type') ? (mime_content_type($absolutePath) ?: 'application/pdf') : 'application/pdf',
            'file_size' => $fileInfo->getSize(),
            'file_hash_sha1' => sha1_file($absolutePath) ?: null,
            'parser_status' => 'uploaded',
            'uploaded_by_admin_id' => $adminId,
        ]);

        $existingPaths[$relativePath] = true;
        $added++;
    }
}

set_flash('success', sprintf('Backfill archivio PDF completato. Nuovi file registrati: %d. Già presenti: %d.', $added, $skipped));
header('Location: ' . admin_url('file-manager.php'));
exit;
