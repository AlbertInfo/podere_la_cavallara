<?php
declare(strict_types=1);

function interhome_pdf_require_command(string $command): ?string
{
    $check = trim((string) @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
    return $check !== '' ? $check : null;
}

function interhome_pdf_get_imagemagick_command(): ?string
{
    foreach (['magick', '/opt/imagemagick/bin/convert', 'convert'] as $cmd) {
        $path = str_starts_with($cmd, '/') ? (is_file($cmd) ? $cmd : null) : interhome_pdf_require_command($cmd);
        if ($path) {
            return $cmd;
        }
    }
    return null;
}

function interhome_pdf_parse(string $pdfPath, PDO $pdo): array
{
    if (!is_file($pdfPath)) {
        throw new RuntimeException('PDF non trovato.');
    }

    if (!interhome_pdf_require_command('pdftotext')) {
        throw new RuntimeException('pdftotext non disponibile sul server.');
    }

    if (!interhome_pdf_require_command('pdftoppm')) {
        throw new RuntimeException('pdftoppm non disponibile sul server.');
    }

    if (!interhome_pdf_get_imagemagick_command()) {
        throw new RuntimeException('ImageMagick non disponibile sul server.');
    }

    $layoutText = (string) @shell_exec('pdftotext -layout ' . escapeshellarg($pdfPath) . ' - 2>/dev/null');
    $bboxXml = (string) @shell_exec('pdftotext -bbox-layout ' . escapeshellarg($pdfPath) . ' - 2>/dev/null');

    if (trim($layoutText) === '' || trim($bboxXml) === '') {
        throw new RuntimeException('Impossibile leggere il PDF con i tool di sistema.');
    }

    $plainPages = preg_split("/\f/u", $layoutText) ?: [];
    $xml = @simplexml_load_string($bboxXml);

    if (!$xml) {
        throw new RuntimeException('Impossibile interpretare la struttura del PDF.');
    }

    $xml->registerXPathNamespace('x', 'http://www.w3.org/1999/xhtml');
    $pages = $xml->xpath('//x:page') ?: [];

    $rows = [];
    $notes = [];

    foreach ($pages as $pageIndex => $page) {
        $pageNumber = $pageIndex + 1;
        $pageText = $plainPages[$pageIndex] ?? '';
        $pageRows = interhome_pdf_extract_rows_from_page($page, $pageNumber);
        $statuses = interhome_pdf_detect_statuses_for_page($pdfPath, $pageNumber, $pageRows);

        foreach ($pageRows as $rowIndex => &$row) {
            $row['icon_status'] = $statuses[$rowIndex] ?? 'existing_ok';
            $row['status_label'] = match ($row['icon_status']) {
                'cancelled_skip' => 'Cancellata',
                'new_ok' => 'Nuova prenotazione',
                default => 'Prenotazione esistente',
            };
        }
        unset($row);

        $notes = array_merge($notes, interhome_pdf_extract_notes_from_page_text($pageText));
        $rows = array_merge($rows, $pageRows);
    }

    $rows = interhome_pdf_attach_notes($rows, $notes);
    $rows = interhome_pdf_filter_duplicates($pdo, $rows);

    $filteredRows = array_values(array_filter($rows, static function (array $row): bool {
        return $row['icon_status'] !== 'cancelled_skip' && !$row['is_duplicate'];
    }));

    return [
        'all_rows' => $rows,
        'rows' => $filteredRows,
        'stats' => [
            'total_found' => count($rows),
            'new_rows' => count($filteredRows),
            'duplicates' => count(array_filter($rows, static fn(array $row): bool => $row['is_duplicate'])),
            'cancelled' => count(array_filter($rows, static fn(array $row): bool => $row['icon_status'] === 'cancelled_skip')),
            'notes' => count($notes),
        ],
    ];
}

function interhome_pdf_extract_rows_from_page(SimpleXMLElement $page, int $pageNumber): array
{
    $lines = [];
    foreach (($page->xpath('.//x:line') ?: []) as $line) {
        $textParts = [];
        foreach (($line->xpath('./x:word') ?: []) as $word) {
            $textParts[] = trim((string) $word);
        }
        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($textParts, static fn(string $v): bool => $v !== ''))));
        if ($text === '') {
            continue;
        }
        $lines[] = [
            'text' => $text,
            'xMin' => (float) $line['xMin'],
            'xMax' => (float) $line['xMax'],
            'yMin' => (float) $line['yMin'],
            'yMax' => (float) $line['yMax'],
        ];
    }

    $dateLines = array_values(array_filter($lines, static function (array $line): bool {
        return $line['xMin'] < 130
            && $line['yMin'] > 440
            && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $line['text']) === 1;
    }));

    $rows = [];
    for ($i = 0; $i < count($dateLines); $i += 2) {
        if (!isset($dateLines[$i + 1])) {
            break;
        }

        $checkinLine = $dateLines[$i];
        $checkoutLine = $dateLines[$i + 1];
        $nextStart = $dateLines[$i + 2]['yMin'] ?? ((float) $page['height'] - 70.0);
        $rowStart = $checkinLine['yMin'] - 2.0;
        $rowEnd = $nextStart - 2.0;
        $centerY = (($checkinLine['yMin'] + $checkoutLine['yMax']) / 2.0) + 5.0;

        $houseLines = [];
        $clientLines = [];
        $detailLines = [];

        foreach ($lines as $line) {
            if ($line['yMin'] < $rowStart || $line['yMin'] >= $rowEnd) {
                continue;
            }

            if ($line['xMin'] >= 145 && $line['xMin'] < 275) {
                $houseLines[] = $line['text'];
            } elseif ($line['xMin'] >= 280 && $line['xMin'] < 465) {
                $clientLines[] = $line['text'];
            } elseif ($line['xMin'] >= 475) {
                $detailLines[] = $line['text'];
            }
        }

        $rows[] = interhome_pdf_build_row(
            $pageNumber,
            count($rows),
            $checkinLine['text'],
            $checkoutLine['text'],
            $houseLines,
            $clientLines,
            $detailLines,
            $centerY
        );
    }

    return $rows;
}

function interhome_pdf_build_row(
    int $pageNumber,
    int $rowIndex,
    string $checkin,
    string $checkout,
    array $houseLines,
    array $clientLines,
    array $detailLines,
    float $centerY
): array {
    $houseCode = '';
    $houseDescriptorLines = [];
    $houseExternalCode = '';

    foreach ($houseLines as $line) {
        if ($houseCode === '' && preg_match('/^IT[\d\.]+$/i', $line)) {
            $houseCode = $line;
            continue;
        }
        if ($houseExternalCode === '' && preg_match('/^\(BOL\d+\)$/i', $line)) {
            $houseExternalCode = trim($line, '()');
            continue;
        }
        $houseDescriptorLines[] = $line;
    }

    $houseDescriptor = trim(implode(' ', $houseDescriptorLines));
    $roomType = interhome_pdf_map_room_type($houseDescriptor . ' ' . $houseExternalCode);

    $customerName = trim($clientLines[0] ?? '');
    $peopleRaw = '';
    $customerPhone = '';
    $emailFragments = [];

    foreach (array_slice($clientLines, 1) as $line) {
        if ($peopleRaw === '' && interhome_pdf_is_people_line($line)) {
            $peopleRaw = $line;
            continue;
        }
        if ($customerPhone === '' && interhome_pdf_is_phone_line($line)) {
            $customerPhone = $line;
            continue;
        }
        if ($line !== '-') {
            $emailFragments[] = $line;
        }
    }

    $customerEmail = trim(preg_replace('/\s+/u', '', implode('', $emailFragments)));

    $externalReference = '';
    $language = '';

    foreach ($detailLines as $line) {
        if ($externalReference === '' && preg_match('/^\d{9,}$/', preg_replace('/\s+/u', '', $line))) {
            $externalReference = preg_replace('/\s+/u', '', $line);
            continue;
        }
        if ($language === '' && $line !== '') {
            $language = $line;
        }
    }

    ['adults' => $adults, 'children' => $children] = interhome_pdf_parse_guest_counts($peopleRaw);

    return [
        'row_key' => sha1($pageNumber . '|' . $rowIndex . '|' . $checkin . '|' . $checkout . '|' . $customerName . '|' . $externalReference),
        'page_number' => $pageNumber,
        'page_row_index' => $rowIndex,
        'center_y_pt' => $centerY,
        'checkin' => $checkin,
        'checkout' => $checkout,
        'stay_period' => $checkin . ' - ' . $checkout,
        'house_code' => $houseCode,
        'house_external_code' => $houseExternalCode,
        'house_descriptor' => $houseDescriptor,
        'room_type' => $roomType,
        'customer_name' => $customerName,
        'people_raw' => $peopleRaw,
        'adults' => $adults,
        'children_count' => $children,
        'customer_phone' => $customerPhone,
        'customer_email' => $customerEmail,
        'external_reference' => $externalReference,
        'language' => $language,
        'notes' => '',
        'source' => 'interhome_pdf',
        'raw_payload' => [],
        'is_duplicate' => false,
        'icon_status' => 'existing_ok',
        'status_label' => 'Prenotazione esistente',
    ];
}

function interhome_pdf_is_people_line(string $line): bool
{
    return $line === '-'
        || preg_match('/adulti|bambin/i', $line) === 1;
}

function interhome_pdf_is_phone_line(string $line): bool
{
    return preg_match('/^\+?[0-9][0-9\s\-]+$/', $line) === 1;
}

function interhome_pdf_parse_guest_counts(string $peopleRaw): array
{
    $peopleRaw = trim(mb_strtolower($peopleRaw));
    if ($peopleRaw === '' || $peopleRaw === '-') {
        return ['adults' => 0, 'children' => 0];
    }

    $adults = 0;
    $children = 0;

    if (preg_match('/(\d+)\s*adulti/u', $peopleRaw, $matches)) {
        $adults = (int) $matches[1];
    }

    if (preg_match('/(\d+)\s*bambin(?:i|o)/u', $peopleRaw, $matches)) {
        $children = (int) $matches[1];
    } elseif (preg_match('/(\d+)\s*child/iu', $peopleRaw, $matches)) {
        $children = (int) $matches[1];
    }

    return ['adults' => $adults, 'children' => $children];
}

function interhome_pdf_map_room_type(string $rawHouse): string
{
    $rawHouse = mb_strtolower($rawHouse);
    if (preg_match('/n[°º]?\s*,?\s*([1-6])/u', $rawHouse, $matches)) {
        return match ((int) $matches[1]) {
            1 => 'Casa Domenico 1',
            2 => 'Casa Domenico 2',
            3 => 'Casa Riccardo 3',
            4 => 'Casa Riccardo 4',
            5 => 'Casa Alessandro 5',
            6 => 'Casa Alessandro 6',
            default => '',
        };
    }

    return '';
}

function interhome_pdf_extract_notes_from_page_text(string $pageText): array
{
    $notes = [];
    foreach (preg_split('/\R/u', $pageText) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || stripos($trimmed, 'Note:') !== 0) {
            continue;
        }

        $reference = '';
        if (preg_match('/prenotazione\s*n\.\s*(\d{6,})/iu', $trimmed, $matches)) {
            $reference = $matches[1];
        }

        $notes[] = [
            'text' => $trimmed,
            'reference' => $reference,
        ];
    }

    return $notes;
}

function interhome_pdf_attach_notes(array $rows, array $notes): array
{
    foreach ($notes as $note) {
        if ($note['reference'] === '') {
            continue;
        }

        foreach ($rows as &$row) {
            if ($row['external_reference'] === $note['reference']) {
                $row['notes'] = trim($row['notes'] . "\n" . $note['text']);
                break;
            }
        }
        unset($row);
    }

    return $rows;
}

function interhome_pdf_filter_duplicates(PDO $pdo, array $rows): array
{
    $references = array_values(array_filter(array_unique(array_map(
        static fn(array $row): string => trim((string) $row['external_reference']),
        $rows
    ))));

    if ($references === []) {
        return $rows;
    }

    $placeholders = implode(',', array_fill(0, count($references), '?'));
    $stmt = $pdo->prepare('SELECT external_reference FROM prenotazioni WHERE external_reference IN (' . $placeholders . ')');
    $stmt->execute($references);
    $existing = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($rows as &$row) {
        $row['is_duplicate'] = in_array((string) $row['external_reference'], $existing, true);
        $row['raw_payload'] = [
            'page_number' => $row['page_number'],
            'page_row_index' => $row['page_row_index'],
            'house_code' => $row['house_code'],
            'house_external_code' => $row['house_external_code'],
            'house_descriptor' => $row['house_descriptor'],
            'language' => $row['language'],
            'icon_status' => $row['icon_status'],
            'status_label' => $row['status_label'],
            'people_raw' => $row['people_raw'],
        ];
    }
    unset($row);

    return $rows;
}

function interhome_pdf_detect_statuses_for_page(string $pdfPath, int $pageNumber, array $rows): array
{
    $command = interhome_pdf_get_imagemagick_command();
    if (!$command) {
        return array_fill(0, count($rows), 'existing_ok');
    }

    $tmpBase = sys_get_temp_dir() . '/interhome_import_' . md5($pdfPath . '|' . $pageNumber . '|' . microtime(true));
    $imagePath = $tmpBase . '.png';

    @shell_exec(
        'pdftoppm -singlefile -png -f ' . (int) $pageNumber .
        ' -l ' . (int) $pageNumber . ' ' .
        escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpBase) . ' 2>/dev/null'
    );

    if (!is_file($imagePath)) {
        return array_fill(0, count($rows), 'existing_ok');
    }

    $imageSize = @getimagesize($imagePath);
    if (!$imageSize) {
        @unlink($imagePath);
        return array_fill(0, count($rows), 'existing_ok');
    }

    [$imgWidth, $imgHeight] = $imageSize;
    $pageHeight = 842.0;
    $scaleX = $imgWidth / 595.35;
    $scaleY = $imgHeight / $pageHeight;

    $statuses = [];
    foreach ($rows as $row) {
        $centerY = (int) round($row['center_y_pt'] * $scaleY);
        $cropX = (int) round(18 * $scaleX);
        $cropY = max(0, $centerY - (int) round(18 * $scaleY));
        $cropW = (int) round(50 * $scaleX);
        $cropH = (int) round(36 * $scaleY);

        $histogram = interhome_pdf_crop_histogram($command, $imagePath, $cropW, $cropH, $cropX, $cropY);
        $statuses[] = interhome_pdf_classify_histogram($histogram);
    }

    @unlink($imagePath);
    return $statuses;
}

function interhome_pdf_crop_histogram(string $command, string $imagePath, int $w, int $h, int $x, int $y): string
{
    if ($command === 'magick') {
        $cmd = 'magick ' . escapeshellarg($imagePath) . ' -crop ' . "{$w}x{$h}+{$x}+{$y}" . ' -format %c histogram:info:- 2>/dev/null';
        return (string) @shell_exec($cmd);
    }

    $binary = str_starts_with($command, '/') ? $command : escapeshellcmd($command);
    $cmd = $binary . ' ' . escapeshellarg($imagePath) . ' -crop ' . "{$w}x{$h}+{$x}+{$y}" . ' -format %c histogram:info:- 2>/dev/null';
    return (string) @shell_exec($cmd);
}

function interhome_pdf_classify_histogram(string $histogram): string
{
    $best = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0];

    foreach (preg_split('/\R/u', $histogram) ?: [] as $line) {
        if (!preg_match('/^\s*(\d+): \((\d+),(\d+),(\d+)/', $line, $matches)) {
            continue;
        }

        $count = (int) $matches[1];
        $r = (int) $matches[2];
        $g = (int) $matches[3];
        $b = (int) $matches[4];

        if ($r > 215 && $g > 215 && $b > 215) {
            continue;
        }

        if ($count > $best['count']) {
            $best = ['count' => $count, 'r' => $r, 'g' => $g, 'b' => $b];
        }
    }

    if ($best['count'] === 0) {
        return 'existing_ok';
    }

    if ($best['r'] > ($best['g'] + 40) && $best['r'] > ($best['b'] + 40)) {
        return 'cancelled_skip';
    }

    if ($best['g'] > ($best['r'] + 20) && $best['g'] > ($best['b'] + 10)) {
        return 'new_ok';
    }

    return 'existing_ok';
}
