<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_admin();

header('Content-Type: text/plain; charset=UTF-8');

$pdf = __DIR__ . '/test/interhome-test.pdf';
$outputDir = __DIR__ . '/test';
$output = $outputDir . '/interhome-page1.png';

echo "PDF: {$pdf}\n";
echo "Output dir: {$outputDir}\n";
echo "Output file: {$output}\n\n";

echo "PDF exists: " . (file_exists($pdf) ? 'SI' : 'NO') . "\n";
echo "Dir exists: " . (is_dir($outputDir) ? 'SI' : 'NO') . "\n";
echo "Dir writable: " . (is_writable($outputDir) ? 'SI' : 'NO') . "\n";
echo "Imagick loaded: " . (extension_loaded('imagick') ? 'SI' : 'NO') . "\n\n";

if (!extension_loaded('imagick')) {
    exit("Imagick NON attivo\n");
}

try {
    $imagick = new Imagick();
    $imagick->setResolution(150, 150);
    $okRead = $imagick->readImage($pdf . '[0]');
    echo "readImage eseguito\n";
    echo "Frames: " . $imagick->getNumberImages() . "\n";
    echo "Width: " . $imagick->getImageWidth() . "\n";
    echo "Height: " . $imagick->getImageHeight() . "\n";
    echo "Format before: " . $imagick->getImageFormat() . "\n";

    $imagick->setImageFormat('png');
    echo "Format after: " . $imagick->getImageFormat() . "\n";

    $okWrite = $imagick->writeImage($output);
    echo "writeImage return: " . var_export($okWrite, true) . "\n";
    echo "Output exists after write: " . (file_exists($output) ? 'SI' : 'NO') . "\n";

    if (file_exists($output)) {
        echo "Output size: " . filesize($output) . " bytes\n";
    }

    echo "\nImagick version:\n";
    print_r(Imagick::getVersion());

} catch (Throwable $e) {
    echo "\nERRORE:\n";
    echo $e->getMessage() . "\n";
}