<?php
declare(strict_types=1);

use Smalot\PdfParser\Parser;

function interhome_safe_trim(mixed $value): string
{
    return trim((string)($value ?? ''));
}

function interhome_safe_spaces(mixed $value): string
{
    $string = (string)($value ?? '');
    $normalized = preg_replace('/\s+/u', ' ', $string);
    return trim((string)($normalized ?? $string));
}

function interhome_import_require_vendor(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $candidates = [
        dirname(__DIR__, 3) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
        dirname(__DIR__, 1) . '/vendor/autoload.php',
    ];

    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            $loaded = true;
            return;
        }
    }

    throw new RuntimeException('Composer autoload non trovato. Esegui "composer require smalot/pdfparser" nella root del sito.');
}

function interhome_import_parse_pdf(string $pdfPath, PDO $pdo): array
{
    if (!is_file($pdfPath)) {
        throw new RuntimeException('PDF non trovato.');
    }

    interhome_import_require_vendor();

    $parser = new Parser();
    $document = $parser->parseFile($pdfPath);
    $pages = $document->getPages();
    if (!$pages) {
        throw new RuntimeException('Impossibile leggere il contenuto del PDF Interhome.');
    }

    $allRows = [];
    $globalNotes = [];
    $pageCount = 0;

    foreach ($pages as $pageIndex => $page) {
        $pageCount++;
        $pageText = interhome_page_text_normalize((string)$page->getText());
        if ($pageText === '') {
            continue;
        }

        foreach (interhome_extract_notes_from_page_text($pageText) as $ref => $note) {
            $globalNotes[$ref] = $note;
        }

        $rows = interhome_parse_page_text($pageText, $pageIndex + 1);
        foreach ($rows as $row) {
            $ref = interhome_safe_trim($row['external_reference'] ?? '');
            if ($ref !== '' && isset($globalNotes[$ref])) {
                $row['notes'] = $globalNotes[$ref];
            }
            $allRows[] = $row;
        }
    }

    foreach ($allRows as &$row) {
        $ref = interhome_safe_trim($row['external_reference'] ?? '');
        if ($ref !== '' && isset($globalNotes[$ref])) {
            $row['notes'] = $globalNotes[$ref];
        }
    }
    unset($row);

    // Riferimento prenotazione obbligatorio
    $allRows = array_values(array_filter($allRows, static function (array $row): bool {
        return interhome_safe_trim($row['external_reference'] ?? '') !== '';
    }));

    $beforeFilter = count($allRows);
    $allRows = interhome_filter_existing_rows($pdo, $allRows);
    $duplicatesSkipped = $beforeFilter - count($allRows);

    return [
        'rows' => $allRows,
        'summary' => [
            'found_total' => count($allRows),
            'duplicates_skipped' => $duplicatesSkipped,
            'pages' => $pageCount,
        ],
    ];
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
        return $rows;
    }

    $placeholders = implode(',', array_fill(0, count($refs), '?'));
    $stmt = $pdo->prepare("SELECT external_reference FROM prenotazioni WHERE external_reference IN ($placeholders)");
    $stmt->execute($refs);

    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
        $existing[(string)$ref] = true;
    }

    $seenInFile = [];
    $filtered = [];

    foreach ($rows as $row) {
        $ref = interhome_safe_trim($row['external_reference'] ?? '');
        if ($ref !== '') {
            if (isset($existing[$ref]) || isset($seenInFile[$ref])) {
                continue;
            }
            $seenInFile[$ref] = true;
        }
        $filtered[] = $row;
    }

    return $filtered;
}

function interhome_page_text_normalize(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

    $lines = preg_split('/\n/u', $text) ?: [];
    $normalizedLines = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        // compatta righe tipo "N u o v a  p r e n o t a z i o n e"
        if (preg_match('/^(?:[[:alpha:]]\s+){4,}[[:alpha:]]$/u', $line)) {
            $line = preg_replace('/\s+/u', '', $line) ?? $line;
        }
        $normalizedLines[] = $line;
    }

    return trim(implode("\n", $normalizedLines));
}

function interhome_extract_notes_from_page_text(string $pageText): array
{
    $notes = [];
    foreach (preg_split('/\n/u', $pageText) ?: [] as $line) {
        $line = interhome_safe_spaces($line);
        if ($line === '') {
            continue;
        }
        if (stripos($line, 'Note:') === 0 || stripos($line, 'Note :') === 0) {
            if (preg_match('/prenotazione\s*n\.?\s*([0-9]{9,15})/iu', $line, $m)) {
                $ref = interhome_safe_trim($m[1] ?? '');
                if ($ref !== '') {
                    $notes[$ref] = $line;
                }
            }
        }
    }
    return $notes;
}

function interhome_parse_page_text(string $pageText, int $pageNo): array
{
    $lines = array_values(array_filter(array_map('interhome_safe_spaces', preg_split('/\n/u', $pageText) ?: []), static fn(string $l): bool => $l !== ''));

    $lines = interhome_strip_page_noise($lines);

    $propertyData = interhome_extract_property_blocks($lines);
    $propertyBlocks = $propertyData['blocks'];
    $propertyCount = count($propertyBlocks);
    if ($propertyCount === 0) {
        return [];
    }

    $datePairs = interhome_select_date_pairs_from_lines($lines, $propertyCount);
    $refLang = interhome_extract_ref_language_pairs($lines, $propertyCount);
    $customerBlocks = interhome_extract_customer_blocks_from_lines($lines, $propertyCount);

    $finalCount = min(count($propertyBlocks), count($datePairs), count($refLang), count($customerBlocks));
    if ($finalCount === 0) {
        return [];
    }

    $rows = [];
    for ($i = 0; $i < $finalCount; $i++) {
        $date = $datePairs[$i];
        $property = $propertyBlocks[$i];
        $customer = $customerBlocks[$i];
        $reference = $refLang[$i];

        $stayPeriod = $date['check_in'] . ' - ' . $date['check_out'];

        $rows[] = [
            'stay_period' => $stayPeriod,
            'check_in' => $date['check_in'],
            'check_out' => $date['check_out'],
            'room_type' => $property['room_type'],
            'customer_name' => $customer['customer_name'],
            'customer_phone' => $customer['customer_phone'],
            'customer_email' => $customer['customer_email'],
            'adults' => $customer['adults'],
            'children_count' => $customer['children_count'],
            'external_reference' => $reference['external_reference'],
            'source' => 'interhome_pdf',
            'status' => 'confermata',
            'notes' => null,
            '_raw_people' => $customer['people_raw'],
            '_raw_property' => $property['raw_property'],
            '_language' => $reference['language'],
            '_page' => $pageNo,
        ];
    }

    return $rows;
}

function interhome_strip_page_noise(array $lines): array
{
    $filtered = [];
    foreach ($lines as $line) {
        $compact = preg_replace('/\s+/u', ' ', $line) ?? $line;
        $compactNoSpace = preg_replace('/\s+/u', '', $line) ?? $line;

        if ($compact === '') continue;
        if (preg_match('/^(Nuovaprenotazione|Prenotazioneesistente|Modificaaprenotazioneesistente|Prenotazionecancellata)/iu', $compactNoSpace)) continue;
        if (preg_match('/^Pagina$/iu', $compact)) continue;
        if (preg_match('/^\d+\/\d+$/u', $compact)) continue;
        if (preg_match('/^\d+\s*\/\s*\d+\s*\/\s*[A-Z]$/u', $compact)) continue;
        if ($compact === 'Lista degli arrivi 13 / 2026 / W' || str_starts_with($compact, 'Lista degli arrivi')) continue;
        if (preg_match('/^IT\d{5}$/', $compact)) continue;
        if ($compact === 'Codice partner IT04151' || $compact === 'Codice partner' || $compact === 'Data di creazione Contatto') continue;
        if ($compact === 'Data Casa vacanze Clienti Dettagli' || $compact === 'Data Casa vacanze Clienti Dettagli cancellata') continue;
        if (str_contains($compact, 'Interhome | Service Office') || str_contains($compact, '@interhome.group') || str_contains($compact, 'myhome.it@interhome.group')) continue;
        if (preg_match('/^(HHD AG|Sägereistrasse|CH-\d+|Marialia Guarducci Podere La Cavallara|Corso Cavour,? 5|01027 MONTEFIASCONE|ITALIA)$/ui', $compact)) continue;
        if (preg_match('/^Animale di piccola taglia$/ui', $compact)) continue;
        if (strcasecmp($compact, 'cancellata') === 0) continue;

        $filtered[] = $compact;
    }
    return $filtered;
}

function interhome_extract_property_blocks(array $lines): array
{
    $blocks = [];
    $ends = [];
    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        if (preg_match('/^IT\d+\.\d+\.\d+$/', $lines[$i])) {
            $code = $lines[$i];
            $parts = [];
            $j = $i + 1;
            while ($j < $count) {
                $candidate = $lines[$j];
                if (preg_match('/^\(BOL\d+\)$/i', $candidate)) {
                    $parts[] = $candidate;
                    break;
                }
                if (preg_match('/^IT\d+\.\d+\.\d+$/', $candidate) || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $candidate) || interhome_looks_like_customer_name($candidate)) {
                    break;
                }
                $parts[] = $candidate;
                $j++;
            }
            if ($parts && preg_match('/\(BOL\d+\)$/i', (string)end($parts))) {
                $raw = interhome_safe_spaces($code . ' ' . implode(' ', $parts));
                $blocks[] = [
                    'property_code' => $code,
                    'raw_property' => $raw,
                    'room_type' => interhome_map_room_type($raw),
                ];
                $ends[] = $j;
                $i = $j;
            }
        }
    }

    return ['blocks' => $blocks, 'ends' => $ends];
}

function interhome_select_date_pairs_from_lines(array $lines, int $expectedCount): array
{
    $dates = [];
    foreach ($lines as $line) {
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $line)) {
            $dates[] = $line;
        }
    }

    $pairs = [];
    for ($i = 0; $i + 1 < count($dates); $i += 2) {
        $d1 = DateTimeImmutable::createFromFormat('d/m/Y', $dates[$i]);
        $d2 = DateTimeImmutable::createFromFormat('d/m/Y', $dates[$i + 1]);
        if (!$d1 || !$d2 || $d2 <= $d1) {
            continue;
        }
        $pairs[] = ['check_in' => $dates[$i], 'check_out' => $dates[$i + 1]];
        if (count($pairs) >= $expectedCount) {
            break;
        }
    }

    return $pairs;
}

function interhome_extract_ref_language_pairs(array $lines, int $expectedCount): array
{
    $pairs = [];
    $count = count($lines);
    for ($i = 0; $i < $count - 1; $i++) {
        $ref = interhome_safe_trim($lines[$i]);
        $lang = interhome_safe_trim($lines[$i + 1]);
        if (preg_match('/^[0-9]{9,15}$/', $ref) && interhome_looks_like_language($lang)) {
            $pairs[] = ['external_reference' => $ref, 'language' => $lang, '_line_index' => $i];
            if (count($pairs) >= $expectedCount) {
                break;
            }
        }
    }
    return $pairs;
}

function interhome_extract_customer_blocks_from_lines(array $lines, int $expectedCount): array
{
    $detailLines = [];
    $count = count($lines);

    // rimuovi date, blocchi proprietà, riferimenti, lingue e note; resta solo la colonna clienti/dettagli
    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $line)) continue;
        if (preg_match('/^IT\d+\.\d+\.\d+$/', $line)) continue;
        if (preg_match('/^\(BOL\d+\)$/i', $line)) continue;
        if (str_contains(mb_strtolower($line), 'porzione di casa')) continue;
        if (preg_match('/^[0-9]{9,15}$/', $line)) continue;
        if (interhome_looks_like_language($line)) continue;
        if (stripos($line, 'Note:') === 0) continue;
        $detailLines[] = $line;
    }

    $blocks = [];
    $i = 0;
    $detailCount = count($detailLines);

    while ($i < $detailCount && count($blocks) < $expectedCount) {
        $name = interhome_safe_trim($detailLines[$i] ?? '');
        if (!interhome_looks_like_customer_name($name)) {
            $i++;
            continue;
        }

        $people = interhome_safe_trim($detailLines[$i + 1] ?? '');
        if (!interhome_looks_like_people_line($people)) {
            $i++;
            continue;
        }

        $cursor = $i + 2;
        $phone = '';
        $email = '';

        if ($cursor < $detailCount && interhome_looks_like_phone(interhome_safe_trim($detailLines[$cursor] ?? ''))) {
            $phone = interhome_safe_trim($detailLines[$cursor] ?? '');
            $cursor++;
        }

        if ($cursor < $detailCount) {
            $candidate = interhome_safe_trim($detailLines[$cursor] ?? '');
            if (strpos($candidate, '@') !== false || interhome_looks_like_email_fragment($candidate)) {
                $email = $candidate;
                $cursor++;
                while ($cursor < $detailCount && !interhome_email_looks_complete($email)) {
                    $fragment = interhome_safe_trim($detailLines[$cursor] ?? '');
                    if ($fragment === '' || interhome_looks_like_customer_name($fragment) || interhome_looks_like_people_line($fragment) || interhome_looks_like_phone($fragment)) {
                        break;
                    }
                    $email .= $fragment;
                    $cursor++;
                }
            }
        }

        [$adults, $children] = interhome_parse_people($people);
        $blocks[] = [
            'customer_name' => $name,
            'people_raw' => $people,
            'customer_phone' => $phone,
            'customer_email' => $email,
            'adults' => $adults,
            'children_count' => $children,
        ];

        $i = $cursor;
    }

    return $blocks;
}

function interhome_looks_like_customer_name(string $value): bool
{
    $value = interhome_safe_trim($value);
    if ($value === '') return false;
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) return false;
    if (preg_match('/^IT\d+\.\d+\.\d+$/', $value)) return false;
    if (preg_match('/^\(BOL\d+\)$/i', $value)) return false;
    if (interhome_looks_like_phone($value)) return false;
    if (strpos($value, '@') !== false) return false;
    if (preg_match('/^[0-9]{9,15}$/', $value)) return false;
    if (interhome_looks_like_language($value)) return false;
    if (str_contains(mb_strtolower($value), 'porzione di casa')) return false;
    if (str_contains(mb_strtolower($value), 'interhome')) return false;
    return true;
}

function interhome_looks_like_people_line(string $value): bool
{
    $value = mb_strtolower(interhome_safe_trim($value));
    return $value === '-' || str_contains($value, 'adult') || str_contains($value, 'bambin');
}

function interhome_looks_like_phone(string $value): bool
{
    $value = interhome_safe_trim($value);
    return $value !== '' && (str_starts_with($value, '+') || preg_match('/^[0-9][0-9 \-\/]{5,}$/', $value));
}

function interhome_looks_like_email_fragment(string $value): bool
{
    $value = interhome_safe_trim($value);
    return $value !== '' && preg_match('/^[A-Za-z0-9._%+\-]+$/', $value);
}

function interhome_email_looks_complete(string $email): bool
{
    return (bool)preg_match('/\.[A-Za-z]{2,}$/', interhome_safe_trim($email));
}

function interhome_looks_like_language(string $value): bool
{
    $value = mb_strtolower(interhome_safe_trim($value));
    return in_array($value, ['italiano', 'inglese', 'tedesco', 'ceco', 'polacco', 'olandese', 'francese', 'spagnolo'], true);
}

function interhome_parse_people(string $raw): array
{
    $raw = mb_strtolower(interhome_safe_trim($raw));
    if ($raw === '' || $raw === '-') {
        return [0, 0];
    }
    $adults = 0;
    $children = 0;
    if (preg_match('/(\d+)\s*adulti?/', $raw, $m)) {
        $adults = (int)($m[1] ?? 0);
    }
    if (preg_match('/(\d+)\s*bambin[io]/', $raw, $m)) {
        $children = (int)($m[1] ?? 0);
    }
    return [$adults, $children];
}

function interhome_map_room_type(string $rawProperty): string
{
    $normalized = mb_strtolower(interhome_safe_trim($rawProperty));
    $normalized = str_replace(['º', '°'], '°', $normalized);
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

    if (preg_match('/porzione di casa\s*,?\s*n[°o]?\s*([1-6])\b/ui', $normalized, $m)) {
        return interhome_room_number_to_name((int)($m[1] ?? 0));
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
