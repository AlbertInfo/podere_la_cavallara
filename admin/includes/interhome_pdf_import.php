<?php
declare(strict_types=1);

use Smalot\PdfParser\Parser;

function interhome_import_vendor_autoload_paths(): array
{
    return [
        dirname(__DIR__, 3) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
    ];
}

function interhome_import_bootstrap_pdfparser(): void
{
    foreach (interhome_import_vendor_autoload_paths() as $path) {
        if (is_file($path)) {
            require_once $path;
            if (class_exists(Parser::class)) {
                return;
            }
        }
    }

    throw new RuntimeException('Composer autoload non trovato. Esegui "composer require smalot/pdfparser" nella root del sito.');
}

function interhome_safe_trim(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function interhome_safe_spaces(mixed $value): string
{
    $text = (string) ($value ?? '');
    $normalized = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
    return trim((string) ($normalized ?? $text));
}

function interhome_import_parse_pdf(string $pdfPath, PDO $pdo): array
{
    if (!is_file($pdfPath)) {
        throw new RuntimeException('PDF non trovato.');
    }

    interhome_import_bootstrap_pdfparser();

    $parser = new Parser();
    $pdf = $parser->parseFile($pdfPath);
    $pages = $pdf->getPages();
    if (!$pages) {
        throw new RuntimeException('Impossibile leggere il contenuto del PDF.');
    }

    $allRows = [];
    $notesByReference = [];
    $pagesRead = 0;

    foreach ($pages as $index => $page) {
        $rawText = (string) $page->getText();
        if (interhome_safe_trim($rawText) === '') {
            continue;
        }
        $pagesRead++;
        $pageResult = interhome_parse_page_text($rawText, $index + 1);
        foreach ($pageResult['notes'] as $ref => $note) {
            $notesByReference[(string) $ref] = $note;
        }
        foreach ($pageResult['rows'] as $row) {
            $allRows[] = $row;
        }
    }

    foreach ($allRows as &$row) {
        $ref = interhome_safe_trim($row['external_reference'] ?? '');
        if ($ref !== '' && isset($notesByReference[$ref])) {
            $row['notes'] = $notesByReference[$ref];
        }
    }
    unset($row);

    $parsedTotal = count($allRows);
    $allRows = interhome_filter_existing_rows($pdo, $allRows);
    $duplicatesSkipped = $parsedTotal - count($allRows);

    foreach ($allRows as &$row) {
        $row['import_row_id'] = bin2hex(random_bytes(8));
    }
    unset($row);

    return [
        'rows' => array_values($allRows),
        'summary' => [
            'pages_read' => $pagesRead,
            'parsed_total' => $parsedTotal,
            'duplicates_skipped' => $duplicatesSkipped,
            'new_total' => count($allRows),
        ],
    ];
}

function interhome_parse_page_text(string $pageText, int $pageNo): array
{
    $notes = interhome_extract_notes_from_page($pageText);
    $body = interhome_strip_header_footer_and_notes($pageText);
    $rows = [];

    $pattern = '/(?ms)' .
        '^\s*(?P<check_in>\d{2}\/\d{2}\/\d{4})\s*$\R' .
        '^\s*(?P<check_out>\d{2}\/\d{2}\/\d{4})\s*$\R' .
        '^\s*(?P<property_code>IT\d{4,}\.\d+\.\d+)\s*$\R' .
        '^(?P<property_desc>porzione di casa.*)$\R' .
        '^\s*(?P<property_bol>\(BOL\d+\))\s*$\R' .
        '^(?P<customer_name>.+?)\s*$\R' .
        '(?P<after_name>.*?)(?=^\s*\d{2}\/\d{2}\/\d{4}\s*$|\z)/u';

    if (!preg_match_all($pattern, $body, $matches, PREG_SET_ORDER)) {
        return ['rows' => [], 'notes' => $notes];
    }

    foreach ($matches as $m) {
        $checkIn = interhome_safe_trim($m['check_in'] ?? '');
        $checkOut = interhome_safe_trim($m['check_out'] ?? '');
        $propertyCode = interhome_safe_trim($m['property_code'] ?? '');
        $propertyDesc = interhome_safe_trim($m['property_desc'] ?? '');
        $propertyBol = interhome_safe_trim($m['property_bol'] ?? '');
        $customerName = interhome_safe_trim($m['customer_name'] ?? '');
        $tail = interhome_prepare_lines((string) ($m['after_name'] ?? ''));

        if ($checkIn === '' || $checkOut === '' || $propertyCode === '' || $propertyDesc === '' || $propertyBol === '' || $customerName === '') {
            continue;
        }

        [$people, $phone, $email, $reference, $language, $inlineNote] = interhome_parse_tail_lines($tail);
        if (!interhome_is_reference($reference)) {
            continue;
        }

        [$adults, $children] = interhome_parse_people($people);
        $rawProperty = interhome_safe_spaces($propertyCode . ' ' . $propertyDesc . ' ' . $propertyBol);
        $roomType = interhome_map_room_type($rawProperty);

        $rows[] = [
            'import_row_id' => '',
            'stay_period' => $checkIn . ' - ' . $checkOut,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'room_type' => $roomType,
            'customer_name' => $customerName,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'adults' => $adults,
            'children_count' => $children,
            'notes' => $inlineNote !== '' ? $inlineNote : null,
            'status' => 'confermata',
            'source' => 'interhome_pdf',
            'external_reference' => $reference,
            '_language' => $language,
            '_raw_people' => $people,
            '_raw_property' => $rawProperty,
            '_page' => $pageNo,
        ];
    }

    return ['rows' => $rows, 'notes' => $notes];
}

function interhome_extract_notes_from_page(string $pageText): array
{
    $notes = [];
    if (preg_match_all('/^Note:\s*(.+)$/imu', $pageText, $matches)) {
        foreach ($matches[0] as $idx => $line) {
            if (preg_match('/prenotazione n\.\s*([0-9]{9,15})/iu', $line, $m)) {
                $notes[(string) $m[1]] = interhome_safe_spaces($line);
            }
        }
    }
    return $notes;
}

function interhome_strip_header_footer_and_notes(string $pageText): string
{
    $lines = preg_split('/\R/u', str_replace(["\r\n", "\r"], "\n", $pageText)) ?: [];
    $clean = [];
    $inTable = false;

    foreach ($lines as $line) {
        $line = interhome_safe_spaces($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^Data Casa vacanze Clienti Dettagli$/iu', $line)) {
            $inTable = true;
            continue;
        }

        if (!$inTable) {
            continue;
        }

        if (preg_match('/^(Nuova prenotazione|Prenotazione esistente|Prenotazione cancellata|Modifica a prenotazione esistente)\b/iu', $line)) {
            continue;
        }
        if (preg_match('/^Pagina\s+\d+\/\d+/iu', $line)) {
            continue;
        }
        if (preg_match('/^IT04151\b.*13\s*\/\s*2026\s*\/\s*W/iu', $line)) {
            continue;
        }
        if (preg_match('/^Note:\s*/iu', $line)) {
            continue;
        }
        if (preg_match('/^(Marialia Guarducci|Podere La Cavallara|Corso Cavour|01027 MONTEFIASCONE|ITALIA|HHD AG|Sägereistrasse|CH-8152 Glattbrugg)$/iu', $line)) {
            continue;
        }
        if (preg_match('/^(Lista degli arrivi|Codice partner|Data di creazione Contatto)$/iu', $line)) {
            continue;
        }
        // ignore stray overlay text
        if (preg_match('/^Testo$/iu', $line)) {
            continue;
        }

        $clean[] = $line;
    }

    return implode("\n", $clean);
}

function interhome_prepare_lines(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $rawLines = explode("\n", $text);
    $lines = [];
    foreach ($rawLines as $line) {
        $line = interhome_safe_spaces($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines;
}

function interhome_parse_tail_lines(array $tail): array
{
    $people = '-';
    $phone = '';
    $email = '';
    $reference = '';
    $language = '';
    $noteParts = [];

    foreach ($tail as $line) {
        if ($reference === '' && interhome_is_reference($line)) {
            $reference = $line;
            continue;
        }
        if ($reference !== '' && $language === '' && interhome_is_language($line)) {
            $language = $line;
            continue;
        }
        if ($people === '-' && interhome_looks_like_people($line)) {
            $people = $line;
            continue;
        }
        if ($phone === '' && interhome_is_phone($line)) {
            $phone = $line;
            continue;
        }
        if ($email === '' && interhome_looks_like_email($line)) {
            $email = $line;
            continue;
        }
        if (!interhome_is_property_code($line) && !interhome_is_date($line) && !preg_match('/^\(BOL\d+\)$/i', $line)) {
            $noteParts[] = $line;
        }
    }

    return [$people, $phone, $email, $reference, $language, interhome_safe_spaces(implode(' · ', $noteParts))];
}

function interhome_filter_existing_rows(PDO $pdo, array $rows): array
{
    $refs = [];
    foreach ($rows as $row) {
        $ref = interhome_safe_trim($row['external_reference'] ?? '');
        if ($ref !== '') {
            $refs[] = $ref;
        }
    }
    $refs = array_values(array_unique($refs));
    if (!$refs) {
        return [];
    }

    $existing = [];
    foreach (array_chunk($refs, 500) as $chunk) {
        $sql = 'SELECT external_reference FROM prenotazioni WHERE external_reference IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
            $existing[(string) $ref] = true;
        }
    }

    $filtered = [];
    $seen = [];
    foreach ($rows as $row) {
        $ref = interhome_safe_trim($row['external_reference'] ?? '');
        if ($ref === '' || isset($existing[$ref]) || isset($seen[$ref])) {
            continue;
        }
        $seen[$ref] = true;
        $filtered[] = $row;
    }
    return $filtered;
}

function interhome_is_date(string $value): bool
{
    return (bool) preg_match('/^\d{2}\/\d{2}\/\d{4}$/', interhome_safe_trim($value));
}
function interhome_is_property_code(string $value): bool
{
    return (bool) preg_match('/^IT\d{4,}\.\d+\.\d+$/i', interhome_safe_trim($value));
}
function interhome_is_reference(string $value): bool
{
    return (bool) preg_match('/^\d{9,15}$/', interhome_safe_trim($value));
}
function interhome_is_language(string $value): bool
{
    return (bool) preg_match('/^(Italiano|Inglese|Tedesco|Ceco|Polacco|Olandese|Francese|Spagnolo)$/iu', interhome_safe_trim($value));
}
function interhome_is_phone(string $value): bool
{
    $value = interhome_safe_trim($value);
    return $value !== '' && (str_starts_with($value, '+') || preg_match('/^[0-9][0-9 \-\/]{5,}$/', $value));
}
function interhome_looks_like_email(string $value): bool
{
    $value = interhome_safe_trim($value);
    return $value !== '' && str_contains($value, '@');
}
function interhome_looks_like_people(string $value): bool
{
    $value = mb_strtolower(interhome_safe_trim($value));
    return $value === '-' || str_contains($value, 'adulti') || str_contains($value, 'adulti') || str_contains($value, 'bambin');
}

function interhome_parse_people(string $raw): array
{
    $raw = mb_strtolower(interhome_safe_trim($raw));
    if ($raw === '' || $raw === '-') {
        return [0, 0];
    }
    $adults = 0;
    $children = 0;
    if (preg_match('/(\d+)\s*adulti?/u', $raw, $m)) {
        $adults = (int) ($m[1] ?? 0);
    }
    if (preg_match('/(\d+)\s*bambin[io]/u', $raw, $m)) {
        $children = (int) ($m[1] ?? 0);
    }
    return [$adults, $children];
}

function interhome_map_room_type(string $rawProperty): string
{
    $normalized = mb_strtolower(interhome_safe_spaces($rawProperty));
    $normalized = str_replace(['º', '°'], '°', $normalized);

    if (preg_match('/porzione di casa\s*,?\s*n[°o]?\s*([1-6])\b/ui', $normalized, $m)) {
        return interhome_room_number_to_name((int) $m[1]);
    }
    if (preg_match('/\(bol560\)/i', $normalized)) return 'Casa Domenico 2';
    if (preg_match('/\(bol561\)/i', $normalized)) return 'Casa Domenico 1';
    if (preg_match('/\(bol562\)/i', $normalized)) return 'Casa Riccardo 4';
    if (preg_match('/\(bol563\)/i', $normalized)) return 'Casa Riccardo 3';
    if (preg_match('/\(bol565\)/i', $normalized)) return 'Casa Alessandro 5';
    if (preg_match('/\(bol564\)/i', $normalized)) return 'Casa Alessandro 6';
    return '';
}
function interhome_room_number_to_name(int $number): string
{
    return match ($number) {
        1 => 'Casa Domenico 1',
        2 => 'Casa Domenico 2',
        3 => 'Casa Riccardo 3',
        4 => 'Casa Riccardo 4',
        5 => 'Casa Alessandro 5',
        6 => 'Casa Alessandro 6',
        default => '',
    };
}
