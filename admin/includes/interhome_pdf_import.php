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
    $normalized = preg_replace('/\s*\n\s*/u', "\n", (string) ($normalized ?? $text));
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
            $notesByReference[$ref] = $note;
        }
        foreach ($pageResult['rows'] as $row) {
            $allRows[] = $row;
        }
    }

    foreach ($allRows as &$row) {
        $ref = interhome_safe_trim($row['external_reference'] ?? '');
        if ($ref !== '' && isset($notesByReference[$ref])) {
            $existingNote = interhome_safe_trim($row['notes'] ?? '');
            $row['notes'] = $existingNote !== '' ? ($existingNote . ' · ' . $notesByReference[$ref]) : $notesByReference[$ref];
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
    $lines = interhome_prepare_lines($pageText);
    $notes = [];
    $rows = [];

    $rowStarts = interhome_find_row_starts($lines);
    if (!$rowStarts) {
        return ['rows' => [], 'notes' => []];
    }

    $footerStart = count($lines);
    foreach ($lines as $idx => $line) {
        if (interhome_is_footer_line($line)) {
            $footerStart = $idx;
            break;
        }
    }

    $carryNotes = [];
    foreach ($rowStarts as $startPos => $startIndex) {
        $endIndex = $rowStarts[$startPos + 1] ?? $footerStart;
        $chunk = array_slice($lines, $startIndex, $endIndex - $startIndex);
        $parsed = interhome_parse_row_chunk($chunk, $pageNo);
        if (!$parsed) {
            continue;
        }

        if (!empty($carryNotes)) {
            $parsed['notes'] = implode(' · ', $carryNotes);
            $carryNotes = [];
        }

        $rows[] = $parsed;
    }

    // scan notes globally
    foreach ($lines as $line) {
        if (str_starts_with(mb_strtolower($line), 'note:')) {
            if (preg_match('/prenotazione n\.?\s*([0-9]{9,15})/iu', $line, $m)) {
                $notes[(string) $m[1]] = $line;
            }
        }
    }

    // attach standalone notes between rows to previous row
    foreach ($rows as $idx => &$row) {
        if (!empty($notes[$row['external_reference']])) {
            $existing = interhome_safe_trim($row['notes'] ?? '');
            $row['notes'] = $existing !== '' ? ($existing . ' · ' . $notes[$row['external_reference']]) : $notes[$row['external_reference']];
        }
    }
    unset($row);

    return ['rows' => $rows, 'notes' => $notes];
}

function interhome_prepare_lines(string $pageText): array
{
    $pageText = str_replace(["\r\n", "\r"], "\n", $pageText);
    $rawLines = explode("\n", $pageText);
    $lines = [];

    foreach ($rawLines as $line) {
        $line = interhome_safe_spaces($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^(Nuova prenotazione|Prenotazione esistente|Prenotazione cancellata|Modifica a prenotazione esistente) \(/iu', $line)) {
            continue;
        }
        if (preg_match('/^Pagina\s+\d+\/\d+/iu', $line)) {
            continue;
        }
        if (preg_match('/^IT04151\b.*13\s*\/\s*2026\s*\/\s*W/iu', $line)) {
            continue;
        }
        if (preg_match('/^Lista degli arrivi /iu', $line)) {
            continue;
        }
        if (in_array($line, ['Codice', 'partner', 'Data di creazione Contatto', 'Data Casa vacanze Clienti Dettagli', 'Data Casa vacanze Clienti Dettagli'], true)) {
            continue;
        }
        if (preg_match('/^(Codice partner|Data di creazione|Contatto)$/iu', $line)) {
            continue;
        }
        if ($line === 'Testo') {
            continue;
        }

        $lines[] = $line;
    }

    return $lines;
}

function interhome_find_row_starts(array $lines): array
{
    $starts = [];
    $count = count($lines);
    for ($i = 0; $i < $count - 3; $i++) {
        if (!interhome_is_date($lines[$i])) {
            continue;
        }
        if (!interhome_is_date($lines[$i + 1] ?? '')) {
            continue;
        }
        // within next 5 lines must exist property code
        for ($j = $i + 2; $j <= min($i + 5, $count - 1); $j++) {
            if (interhome_is_property_code($lines[$j] ?? '')) {
                $starts[] = $i;
                break;
            }
        }
    }
    return array_values(array_unique($starts));
}

function interhome_parse_row_chunk(array $chunk, int $pageNo): ?array
{
    if (count($chunk) < 6) {
        return null;
    }

    $checkIn = interhome_safe_trim($chunk[0] ?? '');
    $checkOut = interhome_safe_trim($chunk[1] ?? '');
    if (!interhome_is_date($checkIn) || !interhome_is_date($checkOut)) {
        return null;
    }

    $count = count($chunk);
    $propertyCodeIndex = null;
    for ($i = 2; $i < min($count, 8); $i++) {
        if (interhome_is_property_code($chunk[$i] ?? '')) {
            $propertyCodeIndex = $i;
            break;
        }
    }
    if ($propertyCodeIndex === null) {
        return null;
    }

    $bolIndex = null;
    for ($i = $propertyCodeIndex + 1; $i < min($count, $propertyCodeIndex + 6); $i++) {
        if (preg_match('/^\(BOL\d+\)$/i', interhome_safe_trim($chunk[$i] ?? ''))) {
            $bolIndex = $i;
            break;
        }
    }
    if ($bolIndex === null) {
        return null;
    }

    $referenceIndex = null;
    for ($i = $count - 1; $i >= $bolIndex + 1; $i--) {
        if (interhome_is_reference($chunk[$i] ?? '')) {
            $referenceIndex = $i;
            break;
        }
    }
    if ($referenceIndex === null) {
        return null;
    }

    $language = '';
    $languageIndex = $referenceIndex + 1;
    if (isset($chunk[$languageIndex]) && interhome_is_language($chunk[$languageIndex])) {
        $language = interhome_safe_trim($chunk[$languageIndex]);
    }

    $propertyCode = interhome_safe_trim($chunk[$propertyCodeIndex] ?? '');
    $propertyParts = [];
    for ($i = $propertyCodeIndex + 1; $i <= $bolIndex; $i++) {
        $propertyParts[] = interhome_safe_trim($chunk[$i] ?? '');
    }
    $rawProperty = interhome_safe_spaces($propertyCode . ' ' . implode(' ', $propertyParts));

    $customerLines = [];
    for ($i = $bolIndex + 1; $i < $referenceIndex; $i++) {
        $line = interhome_safe_trim($chunk[$i] ?? '');
        if ($line !== '') {
            $customerLines[] = $line;
        }
    }
    if (!$customerLines) {
        return null;
    }

    $name = array_shift($customerLines);
    $people = '-';
    if ($customerLines && interhome_is_people_line($customerLines[0])) {
        $people = array_shift($customerLines);
    }

    $phone = '';
    $emailParts = [];
    $notesParts = [];

    foreach ($customerLines as $line) {
        if ($phone === '' && interhome_is_phone($line)) {
            $phone = $line;
            continue;
        }
        if (interhome_may_be_email_fragment($line)) {
            $emailParts[] = $line;
            continue;
        }
        if (!interhome_is_language($line) && !interhome_is_reference($line)) {
            $notesParts[] = $line;
        }
    }

    $email = interhome_normalize_email_parts($emailParts);
    [$adults, $children] = interhome_parse_people($people);
    $reference = interhome_safe_trim($chunk[$referenceIndex] ?? '');
    if ($reference === '') {
        return null;
    }

    $notes = $notesParts ? implode(' · ', $notesParts) : null;

    return [
        'import_row_id' => '',
        'stay_period' => $checkIn . ' - ' . $checkOut,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'room_type' => interhome_map_room_type($rawProperty),
        'customer_name' => $name,
        'customer_email' => $email,
        'customer_phone' => $phone,
        'adults' => $adults,
        'children_count' => $children,
        'notes' => $notes,
        'status' => 'confermata',
        'source' => 'interhome_pdf',
        'external_reference' => $reference,
        '_language' => $language,
        '_raw_people' => $people,
        '_raw_property' => $rawProperty,
        '_page' => $pageNo,
    ];
}

function interhome_normalize_email_parts(array $parts): string
{
    if (!$parts) {
        return '';
    }
    $email = implode('', array_map('interhome_safe_trim', $parts));
    // if multiple emails accidentally concatenated, keep first valid token around @
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $email, $m)) {
        return $m[0];
    }
    return $email;
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
    $chunks = array_chunk($refs, 500);
    foreach ($chunks as $chunk) {
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
        if ($ref === '') {
            continue;
        }
        if (isset($existing[$ref]) || isset($seen[$ref])) {
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

function interhome_is_people_line(string $value): bool
{
    $value = interhome_safe_trim($value);
    if ($value === '-') return true;
    return (bool) preg_match('/^\d+\s*adulti?(?:\/\d+\s*bambin[io])?$/iu', $value);
}

function interhome_may_be_email_fragment(string $value): bool
{
    $value = interhome_safe_trim($value);
    if ($value === '') {
        return false;
    }
    return str_contains($value, '@') || (bool) preg_match('/^[A-Za-z0-9._%+\-]+$/', $value);
}

function interhome_is_footer_line(string $value): bool
{
    $value = interhome_safe_trim($value);
    if ($value === '') return false;
    return (bool) preg_match('/^(Marialia Guarducci|Podere La Cavallara|Corso Cavour|01027 MONTEFIASCONE|ITALIA|HHD AG|Sägereistrasse|CH-8152 Glattbrugg)$/iu', $value);
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
