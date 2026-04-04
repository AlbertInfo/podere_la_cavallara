<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

header('Content-Type: text/plain; charset=UTF-8');

$commands = [
    'pdftotext' => 'which pdftotext 2>&1',
    'pdftoppm'  => 'which pdftoppm 2>&1',
    'magick'    => 'which magick 2>&1',
    'convert'   => 'which convert 2>&1',
];

foreach ($commands as $label => $cmd) {
    echo "=== {$label} ===\n";
    $output = shell_exec($cmd);
    echo ($output ?: "NON TROVATO") . "\n\n";
}