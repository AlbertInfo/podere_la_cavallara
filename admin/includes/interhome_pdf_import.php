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
    return trim((string)($value ?? ''));
}

function interhome_safe_spaces(mixed $value): string
{
    $text = (string)($value ?? '');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $normalized = preg_replace('/[ \t]+/u', ' ', $text);
    return trim((string)($normalized ?? $text));
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

    foreach ($pages as $pageIndex => $page) {
        $text = (string)$page->getText();
        if (interhome_safe_trim($text) === '') {
            continue;
        }
        $pagesRead++;
        $result = interhome_parse_page_text($text, $pageIndex + 1);
        foreach ($result['notes'] as $ref => $note) {
            $notesByReference[$ref] = $note;
        }
        foreach ($result['rows'] as $row) {
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
    $lines = interhome_prepare_lines($pageText);
    $rows = [];
    $notes = [];
    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];

        if (preg_match('/^Note:/iu', $line)) {
            if (preg_match('/prenotazione n\.\s*([0-9]{9,15})/iu', $line, $m)) {
                $notes[(string)$m[1]] = $line;
            }
            continue;
        }

        if (!interhome_is_date($line)) {
            continue;
        }
        $checkIn = $line;
        $checkOut = $lines[$i + 1] ?? '';
        $propertyCode = $lines[$i + 2] ?? '';
        if (!interhome_is_date($checkOut) || !interhome_is_property_code($propertyCode)) {
            continue;
        }

        $cursor = $i + 3;
        $propertyParts = [];
        while ($cursor < $count) {
            $candidate = $lines[$cursor];
            if ($candidate === '' || $candidate === 'Cancellata') {
                $cursor++;
                continue;
            }
            if (interhome_looks_like_person_start($candidate) || interhome_is_date($candidate) || interhome_is_footer_line($candidate)) {
                break;
            }
            $propertyParts[] = $candidate;
            $cursor++;
            if (preg_match('/^\(BOL\d+\)$/i', $candidate)) {
                break;
            }
        }
        if (!$propertyParts) {
            continue;
        }

        $name = interhome_safe_trim($lines[$cursor] ?? '');
        if ($name === '' || interhome_is_date($name) || interhome_is_property_code($name) || interhome_is_footer_line($name)) {
            continue;
        }
        $cursor++;

        $people = '-';
        if ($cursor < $count && interhome_is_people_or_dash($lines[$cursor])) {
            $people = interhome_safe_trim($lines[$cursor]);
            $cursor++;
        }

        $phone = '';
        if ($cursor < $count && interhome_is_phone($lines[$cursor])) {
            $phone = interhome_safe_trim($lines[$cursor]);
            $cursor++;
        }

        $email = '';
        if ($cursor < $count && interhome_may_be_email_line($lines[$cursor])) {
            $email = interhome_safe_trim($lines[$cursor]);
            $cursor++;
            while ($cursor < $count && interhome_may_continue_email($email, $lines[$cursor])) {
                $email .= interhome_safe_trim($lines[$cursor]);
                $cursor++;
            }
        }

        // find reference in the next few lines to tolerate minor noise
        $reference = '';
        $refPos = $cursor;
        while ($refPos < min($count, $cursor + 6)) {
            if (interhome_is_reference($lines[$refPos] ?? '')) {
                $reference = interhome_safe_trim($lines[$refPos]);
                break;
            }
            if (interhome_is_date($lines[$refPos] ?? '') || interhome_is_property_code($lines[$refPos] ?? '')) {
                break;
            }
            $refPos++;
        }
        if ($reference === '') {
            continue;
        }
        $cursor = $refPos + 1;

        $language = '';
        if ($cursor < $count && interhome_is_language($lines[$cursor])) {
            $language = interhome_safe_trim($lines[$cursor]);
        }

        $rawProperty = interhome_safe_spaces($propertyCode . ' ' . implode(' ', $propertyParts));
        [$adults, $children] = interhome_parse_people($people);

        $rows[] = [
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
            'notes' => null,
            'status' => 'confermata',
            'source' => 'interhome_pdf',
            'external_reference' => $reference,
            '_language' => $language,
            '_raw_people' => $people,
            '_raw_property' => $rawProperty,
            '_page' => $pageNo,
        ];

        $i = max($i, $refPos);
    }

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

        if (preg_match('/^(Nuova prenotazione|Prenotazione esistente|Modifica a prenotazione esistente|Prenotazione cancellata)\s*\(/iu', $line)) {
            continue;
        }
        if (preg_match('/^Pagina\s+\d+\/\d+/iu', $line)) {
            continue;
        }
        if (preg_match('/^Lista degli arrivi\b/iu', $line)) {
            continue;
        }
        if (preg_match('/^Codice\b/iu', $line) || preg_match('/^partner$/iu', $line) || preg_match('/^Data di creazione Contatto$/iu', $line)) {
            continue;
        }
        if (preg_match('/^IT04151\b.*13\s*\/\s*2026\s*\/\s*W/iu', $line)) {
            continue;
        }
        if (preg_match('/^(Interhome \| Service Office|myhome\.it@interhome\.group|\+39 02 4839 1440)$/iu', $line)) {
            continue;
        }
        if (preg_match('/^(Marialia Guarducci Podere La Cavallara|Corso Cavour, 5|01027 MONTEFIASCONE|ITALIA|HHD AG|Sägereistrasse 20|CH-8152 Glattbrugg)$/iu', $line)) {
            continue;
        }
        if (mb_strtolower($line) === 'data casa vacanze clienti dettagli') {
            continue;
        }
        if (mb_strtolower($line) === 'cancellata') {
            continue; // handled manually by admin
        }
        if (mb_strtolower($line) === 'testo') {
            continue;
        }

        $lines[] = $line;
    }
    return $lines;
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
            $existing[(string)$ref] = true;
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
    return (bool)preg_match('/^\d{2}\/\d{2}\/\d{4}$/', interhome_safe_trim($value));
}

function interhome_is_property_code(string $value): bool
{
    return (bool)preg_match('/^IT\d+\.\d+\.\d+$/i', interhome_safe_trim($value));
}

function interhome_is_reference(string $value): bool
{
    return (bool)preg_match('/^\d{9,15}$/', interhome_safe_trim($value));
}

function interhome_is_language(string $value): bool
{
    return (bool)preg_match('/^(Italiano|Inglese|Tedesco|Ceco|Polacco|Olandese|Francese|Spagnolo)$/iu', interhome_safe_trim($value));
}

function interhome_is_phone(string $value): bool
{
    $value = interhome_safe_trim($value);
    return $value !== '' && (str_starts_with($value, '+') || preg_match('/^[0-9][0-9 \-\/]{5,}$/', $value));
}

function interhome_is_people_or_dash(string $value): bool
{
    $v = interhome_safe_trim($value);
    return $v === '-' || (bool)preg_match('/^\d+\s*adult/i', $v) || (bool)preg_match('/^\d+\s*adulti?\/\d+\s*bambin/i', $v);
}

function interhome_may_be_email_line(string $value): bool
{
    $v = interhome_safe_trim($value);
    return $v !== '' && str_contains($v, '@');
}

function interhome_may_continue_email(string $current, string $next): bool
{
    $next = interhome_safe_trim($next);
    if ($next === '' || interhome_is_reference($next) || interhome_is_language($next) || interhome_is_date($next) || interhome_is_phone($next) || interhome_is_property_code($next) || preg_match('/^\(BOL\d+\)$/i', $next)) {
        return false;
    }
    if (!str_contains($current, '@')) {
        return false;
    }
    return (bool)preg_match('/^[A-Za-z0-9._%+\-]+$/', $next);
}

function interhome_looks_like_person_start(string $value): bool
{
    $value = interhome_safe_trim($value);
    if ($value === '' || interhome_is_date($value) || interhome_is_property_code($value) || interhome_is_reference($value) || interhome_is_phone($value) || interhome_is_language($value)) {
        return false;
    }
    if (str_starts_with(mb_strtolower($value), 'porzione di casa') || preg_match('/^\(BOL\d+\)$/i', $value)) {
        return false;
    }
    return true;
}

function interhome_is_footer_line(string $value): bool
{
    $value = interhome_safe_trim($value);
    if ($value === '') return false;
    return str_contains(mb_strtolower($value), 'pagina ') || str_contains(mb_strtolower($value), 'it04151') || str_contains(mb_strtolower($value), '13 / 2026 / w');
}

function interhome_parse_people(string $raw): array
{
    $raw = mb_strtolower(interhome_safe_trim($raw));
    if ($raw === '' || $raw === '-') return [0,0];
    $adults = 0; $children = 0;
    if (preg_match('/(\d+)\s*adulti?/u', $raw, $m)) $adults = (int)$m[1];
    if (preg_match('/(\d+)\s*bambin[io]/u', $raw, $m)) $children = (int)$m[1];
    return [$adults, $children];
}

function interhome_map_room_type(string $rawProperty): string
{
    $normalized = mb_strtolower(interhome_safe_spaces($rawProperty));
    if (preg_match('/porzione di casa\s*,?\s*n[°ºo]?\s*1\b/u', $normalized) || str_contains($normalized, '(bol561)')) return 'Casa Domenico 1';
    if (preg_match('/porzione di casa\s*,?\s*n[°ºo]?\s*2\b/u', $normalized) || str_contains($normalized, '(bol560)')) return 'Casa Domenico 2';
    if (preg_match('/porzione di casa\s*,?\s*n[°ºo]?\s*3\b/u', $normalized) || str_contains($normalized, '(bol563)')) return 'Casa Riccardo 3';
    if (preg_match('/porzione di casa\s*,?\s*n[°ºo]?\s*4\b/u', $normalized) || str_contains($normalized, '(bol562)')) return 'Casa Riccardo 4';
    if (preg_match('/porzione di casa\s*,?\s*n[°ºo]?\s*5\b/u', $normalized) || str_contains($normalized, '(bol565)')) return 'Casa Alessandro 5';
    if (preg_match('/porzione di casa\s*,?\s*n[°ºo]?\s*6\b/u', $normalized) || str_contains($normalized, '(bol564)')) return 'Casa Alessandro 6';
    return '';
}
