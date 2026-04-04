<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=UTF-8');

echo "START\n";

$pdf = __DIR__ . '/admin/test/interhome-test.pdf';
$output = __DIR__ . '/admin/test/interhome-page1.png';

echo "PDF path: $pdf\n";
echo "Output path: $output\n";

echo "PDF exists: " . (file_exists($pdf) ? 'SI' : 'NO') . "\n";
echo "Output dir writable: " . (is_writable(dirname($output)) ? 'SI' : 'NO') . "\n";
echo "Imagick loaded: " . (extension_loaded('imagick') ? 'SI' : 'NO') . "\n";

if (!extension_loaded('imagick')) {
    exit("Imagick NON attivo\n");
}

try {
    $imagick = new Imagick();
    echo "Imagick istanziato\n";

    $imagick->setResolution(150, 150);
    echo "Resolution settata\n";

    $imagick->readImage($pdf . '[0]');
    echo "PDF letto\n";

    echo "Width: " . $imagick->getImageWidth() . "\n";
    echo "Height: " . $imagick->getImageHeight() . "\n";
    echo "Format: " . $imagick->getImageFormat() . "\n";

    $imagick->setImageFormat('png');
    echo "Formato PNG impostato\n";

    $result = $imagick->writeImage($output);
    echo "writeImage result: " . var_export($result, true) . "\n";

    echo "PNG exists: " . (file_exists($output) ? 'SI' : 'NO') . "\n";
    if (file_exists($output)) {
        echo "PNG size: " . filesize($output) . "\n";
    }

    echo "DONE\n";
} catch (Throwable $e) {
    echo "EXCEPTION:\n";
    echo $e->getMessage() . "\n";
}