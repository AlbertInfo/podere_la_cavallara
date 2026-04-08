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
    $normalized = preg_replace('/\s+/u', ' ', $text);
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
        $text = interhome_safe_spaces($page->getText());
        if ($text === '') {
            continue;
        }
        $pagesRead++;
        $pageResult = interhome_parse_page_text((string) $page->getText(), $index + 1);
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
    $notes = [];
    $rows = [];
    $lastRowIndex = null;

    $startIndex = 0;
    foreach ($lines as $i => $line) {
        if (str_contains(mb_strtolower($line), 'data casa vacanze clienti dettagli')) {
            $startIndex = $i + 1;
            break;
        }
    }

    $i = $startIndex;
    $count = count($lines);

    while ($i < $count) {
        $line = $lines[$i];

        if (interhome_is_footer_line($line)) {
            break;
        }

        if (str_starts_with(mb_strtolower($line), 'note:')) {
            $note = $line;
            if (preg_match('/prenotazione n\.\s*([0-9]{9,15})/iu', $note, $m)) {
                $notes[(string) $m[1]] = $note;
            }
            $i++;
            continue;
        }

        if (!interhome_is_date($line)) {
            if ($lastRowIndex !== null && interhome_can_attach_inline_note($line)) {
                $existing = interhome_safe_trim($rows[$lastRowIndex]['notes'] ?? '');
                $rows[$lastRowIndex]['notes'] = $existing !== '' ? ($existing . ' · ' . $line) : $line;
            }
            $i++;
            continue;
        }

        $checkIn = $line;
        $checkOut = $lines[$i + 1] ?? '';
        if (!interhome_is_date($checkOut)) {
            $i++;
            continue;
        }
        $i += 2;

        $propertyCode = interhome_safe_trim($lines[$i] ?? '');
        if (!interhome_is_property_code($propertyCode)) {
            continue;
        }
        $i++;

        $propertyParts = [];
        while ($i < $count) {
            $candidate = $lines[$i] ?? '';
            if ($candidate === '') {
                $i++;
                continue;
            }
            if (interhome_looks_like_customer_name($candidate) || interhome_is_date($candidate) || interhome_is_footer_line($candidate)) {
                break;
            }
            $propertyParts[] = $candidate;
            $i++;
            if (preg_match('/^\(BOL\d+\)$/i', $candidate)) {
                break;
            }
        }

        $name = interhome_safe_trim($lines[$i] ?? '');
        if ($name === '' || interhome_is_date($name) || interhome_is_property_code($name) || interhome_is_footer_line($name)) {
            continue;
        }
        $i++;

        $people = interhome_safe_trim($lines[$i] ?? '-');
        if ($people !== '' && (interhome_is_reference($people) || interhome_is_language($people) || interhome_is_date($people))) {
            $people = '-';
        } else {
            $i++;
        }

        $phone = '';
        if ($i < $count && interhome_is_phone($lines[$i])) {
            $phone = $lines[$i];
            $i++;
        }

        $email = '';
        if ($i < $count && interhome_may_be_email_fragment($lines[$i])) {
            $email = $lines[$i];
            $i++;
            while ($i < $count && interhome_may_continue_email($email, $lines[$i])) {
                $email .= interhome_safe_trim($lines[$i]);
                $i++;
            }
        }

        $reference = interhome_safe_trim($lines[$i] ?? '');
        if (!interhome_is_reference($reference)) {
            while ($i < $count && !interhome_is_reference($lines[$i] ?? '')) {
                if (interhome_is_date($lines[$i] ?? '') || interhome_is_footer_line($lines[$i] ?? '')) {
                    break;
                }
                $i++;
            }
            $reference = interhome_safe_trim($lines[$i] ?? '');
        }

        if (!interhome_is_reference($reference)) {
            continue;
        }
        $i++;

        $language = interhome_safe_trim($lines[$i] ?? '');
        if (interhome_is_language($language)) {
            $i++;
        } else {
            $language = '';
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
        $lastRowIndex = count($rows) - 1;
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
        // ignore obvious header fragments except the table header itself
        if (preg_match('/^Nuova prenotazione \(/iu', $line) || preg_match('/^Prenotazione esistente \(/iu', $line) || preg_match('/^Modifica a prenotazione esistente \(/iu', $line)) {
            continue;
        }
        if (preg_match('/^Prenotazione cancellata \(/iu', $line) || preg_match('/^Pagina\s+\d+\/\d+/iu', $line)) {
            continue;
        }
        if (preg_match('/^IT04151\b.*13\s*\/\s*2026\s*\/\s*W/iu', $line)) {
            continue;
        }
        if (in_array($line, ['Lista degli arrivi 13 / 2026 / W', 'Codice partner', 'Data di creazione Contatto'], true)) {
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

function interhome_may_be_email_fragment(string $value): bool
{
    $value = interhome_safe_trim($value);
    if ($value === '') {
        return false;
    }
    return str_contains($value, '@') || (bool) preg_match('/^[A-Za-z0-9._%+\-]+$/', $value);
}

function interhome_may_continue_email(string $current, string $next): bool
{
    $next = interhome_safe_trim($next);
    if ($next === '') {
        return false;
    }
    if (interhome_is_reference($next) || interhome_is_language($next) || interhome_is_date($next) || interhome_is_phone($next) || interhome_is_property_code($next)) {
        return false;
    }
    if (preg_match('/^\(BOL\d+\)$/i', $next)) {
        return false;
    }
    if (!str_contains($current, '@')) {
        return false;
    }
    return (bool) preg_match('/^[A-Za-z0-9._%+\-]+$/', $next);
}

function interhome_looks_like_customer_name(string $value): bool
{
    $value = interhome_safe_trim($value);
    if ($value === '' || interhome_is_date($value) || interhome_is_property_code($value) || interhome_is_reference($value) || interhome_is_phone($value)) {
        return false;
    }
    if (str_starts_with(mb_strtolower($value), 'porzione di casa')) {
        return false;
    }
    if (preg_match('/^\(BOL\d+\)$/i', $value)) {
        return false;
    }
    if (interhome_is_language($value)) {
        return false;
    }
    return true;
}

function interhome_can_attach_inline_note(string $value): bool
{
    $value = interhome_safe_trim($value);
    if ($value === '') return false;
    if (interhome_is_date($value) || interhome_is_property_code($value) || interhome_is_reference($value) || interhome_is_language($value) || interhome_is_phone($value)) return false;
    if (preg_match('/^\(BOL\d+\)$/i', $value)) return false;
    if (str_starts_with(mb_strtolower($value), 'porzione di casa')) return false;
    if (interhome_is_footer_line($value)) return false;
    return true;
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
