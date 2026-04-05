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
    if ($loaded) return;
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        throw new RuntimeException('Composer autoload non trovato. Esegui "composer require smalot/pdfparser" nella root del sito.');
    }
    require_once $autoload;
    $loaded = true;
}

function interhome_import_parse_pdf(string $pdfPath, PDO $pdo): array
{
    if (!file_exists($pdfPath)) {
        throw new RuntimeException('PDF non trovato.');
    }

    interhome_import_require_vendor();

    $parser = new Parser();
    $pdf = $parser->parseFile($pdfPath);
    $pages = $pdf->getPages();
    if (!$pages) {
        throw new RuntimeException('Impossibile leggere il contenuto del PDF Interhome.');
    }

    $allRows = [];
    $globalNotes = [];
    $pageCount = 0;

    foreach ($pages as $pageIndex => $page) {
        $pageCount++;
        $pageText = interhome_page_text_normalize($page->getText());
        if ($pageText === '' || !str_contains($pageText, 'Lista degli arrivi')) {
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

    $allRows = array_values(array_filter($allRows, static fn(array $row): bool => interhome_safe_trim($row['external_reference'] ?? '') !== ''));

    $beforeDuplicates = count($allRows);
    $allRows = interhome_filter_existing_rows($pdo, $allRows);
    $duplicatesSkipped = $beforeDuplicates - count($allRows);

    return [
        'rows' => $allRows,
        'summary' => [
            'found_total' => count($allRows),
            'duplicates_skipped' => $duplicatesSkipped,
            'cancelled_skipped' => 0,
            'pages' => $pageCount,
        ],
    ];
}

function interhome_filter_existing_rows(PDO $pdo, array $rows): array
{
    $refs = [];
    foreach ($rows as $row) {
        $ref = interhome_safe_trim($row['external_reference'] ?? '');
        if ($ref !== '') $refs[] = $ref;
    }
    $refs = array_values(array_unique($refs));
    if (!$refs) return $rows;

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
            if (isset($existing[$ref]) || isset($seenInFile[$ref])) continue;
            $seenInFile[$ref] = true;
        }
        $filtered[] = $row;
    }
    return $filtered;
}

function interhome_page_text_normalize(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    return trim($text);
}

function interhome_extract_notes_from_page_text(string $pageText): array
{
    $notes = [];
    foreach (preg_split("/\n/u", $pageText) as $line) {
        $line = interhome_safe_spaces($line);
        if ($line === '') continue;
        if (stripos($line, 'Note:') === 0 || stripos($line, 'Note :') === 0) {
            if (preg_match('/prenotazione\s*n\.?\s*([0-9]{9,15})/iu', $line, $m)) {
                $ref = interhome_safe_trim($m[1] ?? '');
                if ($ref !== '') $notes[$ref] = $line;
            }
        }
    }
    return $notes;
}

function interhome_parse_page_text(string $pageText, int $pageNo): array
{
    $lines = array_values(array_filter(array_map('interhome_safe_spaces', preg_split("/\n/u", $pageText) ?: []), static fn($l) => $l !== ''));

    $filtered = [];
    foreach ($lines as $line) {
        if (preg_match('/^(Nuova prenotazione|Prenotazione esistente|Modifica a prenotazione esistente|Prenotazione cancellata)\b/ui', $line)) continue;
        if (preg_match('/^Pagina\b/ui', $line)) continue;
        if (preg_match('/^Lista degli arrivi\b/ui', $line)) continue;
        if (preg_match('/^\d+\s*\/\s*\d+\s*\/\s*[A-Z]$/u', $line)) continue;
        if (preg_match('/^IT\d{5}$/', $line)) continue;
        if (preg_match('/^(Codice|partner|Data di creazione|Contatto|Data Casa vacanze Clienti Dettagli)$/ui', $line)) continue;
        if (str_contains($line, 'Interhome | Service Office') || str_contains($line, '@interhome.group') || str_contains($line, 'myhome.it@interhome.group')) continue;
        if (preg_match('/^(HHD AG|Sägereistrasse|CH-\d+|Marialia Guarducci Podere La Cavallara|Corso Cavour|01027|ITALIA)$/ui', $line)) continue;
        $filtered[] = $line;
    }
    $lines = $filtered;

    $propertyBlocks = [];
    $propertyIndexes = [];
    $count = count($lines);
    for ($i=0; $i<$count; $i++) {
        if (preg_match('/^IT\d+\.\d+\.\d+\.\d+$/', $lines[$i])) {
            $code = $lines[$i];
            $parts = [];
            $j = $i + 1;
            while ($j < $count) {
                $candidate = $lines[$j];
                if (preg_match('/^\(BOL\d+\)$/i', $candidate)) { $parts[] = $candidate; break; }
                if (preg_match('/^IT\d+\.\d+\.\d+\.\d+$/', $candidate) || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $candidate)) break;
                $parts[] = $candidate;
                $j++;
            }
            if ($parts && preg_match('/\(BOL\d+\)$/i', end($parts))) {
                $raw = interhome_safe_spaces($code . ' ' . implode(' ', $parts));
                $propertyBlocks[] = ['property_code'=>$code, 'raw_property'=>$raw, 'room_type'=>interhome_map_room_type($raw)];
                $propertyIndexes[] = $i;
                $i = $j;
            }
        }
    }

    $rowCount = count($propertyBlocks);
    if ($rowCount === 0) return [];

    $dateLines = [];
    foreach ($lines as $line) if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $line)) $dateLines[] = $line;
    $datePairs = interhome_select_date_pairs($dateLines, $rowCount);

    $refLang = interhome_extract_ref_language_pairs($lines, $rowCount);
    $lastPropertyIndex = $propertyIndexes ? max($propertyIndexes) : 0;
    $firstRefLineIndex = interhome_find_first_reference_line_index($lines, $refLang);
    $detailSection = array_slice($lines, $lastPropertyIndex + 1, max(0, $firstRefLineIndex - ($lastPropertyIndex + 1)));

    $customerBlocks = interhome_extract_customer_blocks($detailSection, $rowCount);

    $finalCount = min(count($datePairs), count($propertyBlocks), count($customerBlocks), count($refLang));
    $rows = [];
    for ($i=0; $i<$finalCount; $i++) {
        $date = $datePairs[$i];
        $prop = $propertyBlocks[$i];
        $cust = $customerBlocks[$i];
        $ref = $refLang[$i];
        $stayPeriod = $date['check_in'] . ' - ' . $date['check_out'];

        $rows[] = [
            'stay_period'=>$stayPeriod,
            'check_in'=>$date['check_in'],
            'check_out'=>$date['check_out'],
            'room_type'=>$prop['room_type'],
            'customer_name'=>$cust['customer_name'],
            'customer_phone'=>$cust['customer_phone'],
            'customer_email'=>$cust['customer_email'],
            'adults'=>$cust['adults'],
            'children_count'=>$cust['children_count'],
            'external_reference'=>$ref['external_reference'],
            'source'=>'interhome_pdf',
            'status'=>'confermata',
            'notes'=>null,
            '_raw_people'=>$cust['people_raw'],
            '_raw_property'=>$prop['raw_property'],
            '_language'=>$ref['language'],
            '_raw_row_text'=>interhome_safe_spaces(implode(' | ', [$stayPeriod,$prop['raw_property'],$cust['customer_name'],$cust['people_raw'],$cust['customer_phone'],$cust['customer_email'],$ref['external_reference'],$ref['language']])),
            '_page'=>$pageNo,
        ];
    }
    return $rows;
}

function interhome_select_date_pairs(array $dates, int $expectedCount): array
{
    $dates = array_values($dates);
    $memo = [];

    $dfs = function (int $index, int $pairsNeeded) use (&$dfs, &$memo, $dates): ?array {
        $key = $index . ':' . $pairsNeeded;
        if (array_key_exists($key, $memo)) return $memo[$key];
        if ($pairsNeeded === 0) return $memo[$key] = [];
        if ($index >= count($dates)) return $memo[$key] = null;

        $best = null;
        $skip = $dfs($index + 1, $pairsNeeded);
        if ($skip !== null) $best = $skip;

        if (($index + 1) < count($dates)) {
            $d1 = DateTimeImmutable::createFromFormat('d/m/Y', $dates[$index]);
            $d2 = DateTimeImmutable::createFromFormat('d/m/Y', $dates[$index + 1]);
            if ($d1 && $d2 && $d2 > $d1) {
                $days = (int)$d1->diff($d2)->days;
                if ($days >= 1 && $days <= 60) {
                    $rest = $dfs($index + 2, $pairsNeeded - 1);
                    if ($rest !== null) {
                        $candidate = array_merge([['check_in'=>$dates[$index], 'check_out'=>$dates[$index+1]]], $rest);
                        if ($best === null || count($candidate) > count($best)) $best = $candidate;
                    }
                }
            }
        }
        return $memo[$key] = $best;
    };

    return $dfs(0, $expectedCount) ?? [];
}

function interhome_extract_ref_language_pairs(array $lines, int $expectedCount): array
{
    $pairs = [];
    $count = count($lines);
    for ($i=0; $i<$count-1; $i++) {
        $ref = interhome_safe_trim($lines[$i]);
        $lang = interhome_safe_trim($lines[$i+1]);
        if (preg_match('/^[0-9]{9,15}$/', $ref) && interhome_looks_like_language($lang)) {
            $pairs[] = ['external_reference'=>$ref, 'language'=>$lang, '_line_index'=>$i];
        }
    }
    if (count($pairs) > $expectedCount) $pairs = array_slice($pairs, 0, $expectedCount);
    return array_values($pairs);
}

function interhome_find_first_reference_line_index(array $lines, array $refLang): int
{
    return $refLang ? (int)($refLang[0]['_line_index'] ?? count($lines)) : count($lines);
}

function interhome_extract_customer_blocks(array $lines, int $expectedCount): array
{
    $blocks = [];
    $i=0; $count=count($lines);
    while ($i < $count && count($blocks) < $expectedCount) {
        $name = interhome_safe_trim($lines[$i] ?? '');
        if (!interhome_looks_like_customer_name($name)) { $i++; continue; }

        $people = interhome_safe_trim($lines[$i+1] ?? '');
        if ($people === '' || !interhome_looks_like_people_line($people)) { $i++; continue; }

        $cursor = $i + 2;
        $phone = '';
        $email = '';

        if ($cursor < $count && interhome_looks_like_phone(interhome_safe_trim($lines[$cursor]))) {
            $phone = interhome_safe_trim($lines[$cursor]);
            $cursor++;
        }

        if ($cursor < $count) {
            $candidate = interhome_safe_trim($lines[$cursor]);
            if (strpos($candidate, '@') !== false || interhome_looks_like_email_fragment($candidate)) {
                $email = $candidate;
                $cursor++;
                while ($cursor < $count && !interhome_email_looks_complete($email)) {
                    $fragment = interhome_safe_trim($lines[$cursor]);
                    if ($fragment === '' || preg_match('/^[0-9]{9,15}$/', $fragment) || interhome_looks_like_language($fragment) || interhome_looks_like_customer_name($fragment)) break;
                    $email .= $fragment;
                    $cursor++;
                }
            }
        }

        [$adults, $children] = interhome_parse_people($people);
        $blocks[] = [
            'customer_name'=>$name,
            'people_raw'=>$people,
            'customer_phone'=>$phone,
            'customer_email'=>$email,
            'adults'=>$adults,
            'children_count'=>$children,
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
    if (preg_match('/^IT\d+\.\d+\.\d+\.\d+$/', $value)) return false;
    if (preg_match('/^\(BOL\d+\)$/i', $value)) return false;
    if (interhome_looks_like_phone($value)) return false;
    if (strpos($value, '@') !== false) return false;
    if (preg_match('/^[0-9]{9,15}$/', $value)) return false;
    if (interhome_looks_like_language($value)) return false;
    if (str_contains(mb_strtolower($value), 'porzione di casa')) return false;
    if (str_contains(mb_strtolower($value), 'animale di piccola taglia')) return false;
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
    return $value !== '' && (str_starts_with($value, '+') || preg_match('/^[0-9][0-9 \/\-]{5,}$/', $value));
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
    $value = interhome_safe_trim($value);
    if ($value === '') return false;
    return in_array(mb_strtolower($value), ['italiano','inglese','tedesco','ceco','polacco','olandese','francese','spagnolo'], true);
}

function interhome_parse_people(string $raw): array
{
    $raw = mb_strtolower(interhome_safe_trim($raw));
    if ($raw === '' || $raw === '-') return [0,0];
    $adults = 0; $children = 0;
    if (preg_match('/(\d+)\s*adulti?/', $raw, $m)) $adults = (int)($m[1] ?? 0);
    if (preg_match('/(\d+)\s*bambin[io]/', $raw, $m)) $children = (int)($m[1] ?? 0);
    return [$adults, $children];
}

function interhome_map_room_type(string $rawProperty): string
{
    $normalized = mb_strtolower(interhome_safe_trim($rawProperty));
    $normalized = str_replace(['º', '°'], '°', $normalized);
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
?>