<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

header('Content-Type: text/plain; charset=UTF-8');

$pythonBin = '/usr/bin/python3'; // se which python3 restituisce un path diverso, metti quello
$script = __DIR__ . '/python/test_parser.py';

echo "pythonBin: $pythonBin\n";
echo "script: $script\n";
echo "script_exists: " . (file_exists($script) ? 'YES' : 'NO') . "\n\n";

$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open([$pythonBin, $script], $descriptorspec, $pipes, __DIR__);

if (!is_resource($process)) {
    echo "Impossibile avviare il processo Python.\n";
    exit;
}

fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);

$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);

$exitCode = proc_close($process);

echo "=== EXIT CODE ===\n";
echo $exitCode . "\n\n";

echo "=== STDOUT ===\n";
echo $stdout . "\n\n";

echo "=== STDERR ===\n";
echo $stderr . "\n";