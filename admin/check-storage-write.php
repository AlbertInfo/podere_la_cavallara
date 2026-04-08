<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

header('Content-Type: text/plain; charset=UTF-8');

$dir = __DIR__ . '/storage/parser-logs';
$file = $dir . '/write-test.txt';
$content = "test " . date('Y-m-d H:i:s');

echo "DIR: $dir\n";
echo "is_dir: " . (is_dir($dir) ? 'YES' : 'NO') . "\n";
echo "is_writable(dir): " . (is_writable($dir) ? 'YES' : 'NO') . "\n";

$result = @file_put_contents($file, $content);

echo "file_put_contents: " . ($result !== false ? 'OK' : 'FAIL') . "\n";
echo "file_exists: " . (file_exists($file) ? 'YES' : 'NO') . "\n";

if (file_exists($file)) {
    echo "written_content: " . file_get_contents($file) . "\n";
}