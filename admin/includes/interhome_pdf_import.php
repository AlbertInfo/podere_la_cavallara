<?php
declare(strict_types=1);

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

function interhome_import_parse_pdf(string $pdfPath, PDO $pdo): array
{
    if (!file_exists($pdfPath)) {
        throw new RuntimeException('PDF non trovato.');
    }

    $pages = interhome_pdf_extract_pages($pdfPath);
    if (!$pages) {
        throw new RuntimeException('Impossibile leggere il contenuto del PDF Interhome.');
    }

    $allRows = [];
    $globalNotes = [];

    foreach ($pages as $pageNo => $pageData) {
        $rowsData = interhome_parse_page_tokens($pageData['tokens'], (int)$pageNo);

        foreach ($pageData['notes'] as $ref => $note) {
            $globalNotes[(string)$ref] = $note;
        }
        foreach ($rowsData['notes'] as $ref => $note) {
            $globalNotes[(string)$ref] = $note;
        }

        foreach ($rowsData['rows'] as $row) {
            $externalRef = interhome_safe_trim($row['external_reference'] ?? '');
            if ($externalRef !== '' && isset($globalNotes[$externalRef])) {
                $row['notes'] = $globalNotes[$externalRef];
            }
            $allRows[] = $row;
        }
    }

    foreach ($allRows as &$row) {
        $externalRef = interhome_safe_trim($row['external_reference'] ?? '');
        if ($externalRef !== '' && isset($globalNotes[$externalRef])) {
            $row['notes'] = $globalNotes[$externalRef];
        }
    }
    unset($row);

    $beforeCancelled = count($allRows);
    $allRows = array_values(array_filter($allRows, static function (array $row): bool {
        return !interhome_row_is_cancelled($row);
    }));
    $cancelledSkipped = $beforeCancelled - count($allRows);

    $beforeMissingRef = count($allRows);
    $allRows = array_values(array_filter($allRows, static function (array $row): bool {
        return interhome_safe_trim($row['external_reference'] ?? '') !== '';
    }));
    $missingRefsSkipped = $beforeMissingRef - count($allRows);

    $beforeDuplicates = count($allRows);
    $allRows = interhome_filter_existing_rows($pdo, $allRows);
    $duplicatesSkipped = $beforeDuplicates - count($allRows);

    return [
        'rows' => $allRows,
        'summary' => [
            'found_total' => count($allRows),
            'duplicates_skipped' => $duplicatesSkipped,
            'cancelled_skipped' => $cancelledSkipped,
            'missing_reference_skipped' => $missingRefsSkipped,
            'pages' => count($pages),
        ],
    ];
}

function interhome_row_is_cancelled(array $row): bool
{
    $haystack = strtolower(implode(' ', array_map(
        static fn($v) => is_scalar($v) ? (string)$v : '',
        [
            $row['_raw_row_text'] ?? '',
            $row['_status_text'] ?? '',
            $row['notes'] ?? '',
        ]
    )));

    return str_contains($haystack, 'cancellata');
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
    $existingRefs = [];

    if ($refs) {
        $placeholders = implode(',', array_fill(0, count($refs), '?'));
        $stmt = $pdo->prepare("SELECT external_reference FROM prenotazioni WHERE external_reference IN ($placeholders)");
        $stmt->execute($refs);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
            $existingRefs[(string)$ref] = true;
        }
    }

    $seenInDocument = [];
    $filtered = [];
    foreach ($rows as $row) {
        $key = interhome_safe_trim($row['external_reference'] ?? '');
        if ($key !== '') {
            if (isset($existingRefs[$key]) || isset($seenInDocument[$key])) {
                continue;
            }
            $seenInDocument[$key] = true;
        }
        $filtered[] = $row;
    }
    return $filtered;
}

function interhome_pdf_extract_pages(string $pdfPath): array
{
    $content = file_get_contents($pdfPath);
    if ($content === false) {
        throw new RuntimeException('Impossibile leggere il PDF.');
    }

    preg_match_all('/stream\r?\n(.*?)endstream/s', $content, $matches);
    $pages = [];

    foreach ($matches[1] ?? [] as $rawStream) {
        $decoded = interhome_try_decode_pdf_stream($rawStream);
        if ($decoded === null) {
            continue;
        }

        if (strpos($decoded, 'BT') === false || (strpos($decoded, 'TJ') === false && strpos($decoded, 'Tj') === false)) {
            continue;
        }

        $tokens = interhome_extract_pdf_tokens($decoded);
        if (!$tokens) {
            continue;
        }

        $joined = implode(' ', $tokens);
        if (!str_contains($joined, 'Dettagli')) {
            continue;
        }

        if (!preg_match('/\b([1-9]\d*)\/([1-9]\d*)\b/', $joined, $m)) {
            continue;
        }

        $pageNo = (int)($m[1] ?? 0);
        if ($pageNo <= 0) {
            continue;
        }

        $pages[$pageNo] = [
            'tokens' => $tokens,
            'notes' => interhome_extract_notes_from_tokens($tokens),
        ];
    }

    ksort($pages);
    return $pages;
}

function interhome_try_decode_pdf_stream(string $stream): ?string
{
    $candidates = [];
    $u = @gzuncompress($stream);
    if ($u !== false) $candidates[] = $u;
    $d = @gzdecode($stream);
    if ($d !== false) $candidates[] = $d;
    $i = @gzinflate($stream);
    if ($i !== false) $candidates[] = $i;
    if (strlen($stream) > 2) {
        $i2 = @gzinflate(substr($stream, 2));
        if ($i2 !== false) $candidates[] = $i2;
    }
    foreach ($candidates as $decoded) {
        if (is_string($decoded) && str_contains($decoded, 'BT')) {
            return $decoded;
        }
    }
    return null;
}

function interhome_extract_notes_from_tokens(array $tokens): array
{
    $notes = [];
    foreach ($tokens as $token) {
        $token = interhome_safe_trim($token);
        if (stripos($token, 'Note:') === 0 || stripos($token, 'Note:se') === 0) {
            if (preg_match('/prenotazione n\.?\s*([0-9]{9,15})/i', $token, $m)) {
                $ref = interhome_safe_trim($m[1] ?? '');
                if ($ref !== '') $notes[$ref] = $token;
            }
        }
    }
    return $notes;
}

function interhome_extract_pdf_tokens(string $decodedStream): array
{
    $tokens = [];
    if (!preg_match_all('/BT(.*?)ET/s', $decodedStream, $blocks)) {
        return [];
    }

    foreach ($blocks[1] ?? [] as $block) {
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $arrayMatches)) {
            foreach ($arrayMatches[1] ?? [] as $arrayContent) {
                if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $arrayContent, $parts)) {
                    $text = '';
                    foreach ($parts[0] ?? [] as $part) {
                        $text .= interhome_unescape_pdf_string(substr((string)$part, 1, -1));
                    }
                    $text = interhome_safe_spaces($text);
                    if ($text !== '') $tokens[] = $text;
                }
            }
        }

        if (preg_match_all('/(\((?:\\\\.|[^\\\\)])*\))\s*Tj/s', $block, $stringMatches)) {
            foreach ($stringMatches[1] ?? [] as $part) {
                $rawText = interhome_unescape_pdf_string(substr((string)$part, 1, -1));
                $text = interhome_safe_spaces($rawText);
                if ($text !== '') $tokens[] = $text;
            }
        }
    }

    return $tokens;
}

function interhome_unescape_pdf_string(string $text): string
{
    $result = '';
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $char = $text[$i];
        if ($char !== '\\') {
            $result .= $char;
            continue;
        }
        if ($i + 1 >= $len) break;
        $i++;
        $next = $text[$i];
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\b", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
        if (isset($map[$next])) {
            $result .= $map[$next];
            continue;
        }
        if (ctype_digit($next)) {
            $octal = $next;
            for ($j = 0; $j < 2 && $i + 1 < $len && ctype_digit($text[$i + 1]); $j++) {
                $i++;
                $octal .= $text[$i];
            }
            $result .= chr(octdec($octal));
            continue;
        }
        $result .= $next;
    }
    return $result;
}

function interhome_parse_page_tokens(array $tokens, int $pageNo): array
{
    $rows = [];
    $notes = [];

    $startIndex = 0;
    foreach ($tokens as $i => $token) {
        if (interhome_safe_trim($token) === 'Dettagli') {
            $startIndex = $i + 1;
            break;
        }
    }

    $count = count($tokens);
    $currentCancelledMarkers = 0;

    for ($i = $startIndex; $i < $count; $i++) {
        $token = interhome_safe_trim($tokens[$i] ?? '');
        if ($token === '') continue;

        if (stripos($token, 'Note:') === 0 || stripos($token, 'Note:se') === 0) {
            if (preg_match('/prenotazione n\.?\s*([0-9]{9,15})/i', $token, $m)) {
                $ref = interhome_safe_trim($m[1] ?? '');
                if ($ref !== '') $notes[$ref] = $token;
            }
            continue;
        }

        if (strcasecmp($token, 'cancellata') === 0) {
            $currentCancelledMarkers++;
            continue;
        }

        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $token)) {
            continue;
        }

        $checkIn = $token;
        $checkOut = interhome_safe_trim($tokens[$i + 1] ?? '');
        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $checkOut)) {
            continue;
        }

        $cursor = $i + 2;
        $propertyCode = interhome_safe_trim($tokens[$cursor] ?? '');
        $cursor++;

        $propertyParts = [];
        while ($cursor < $count) {
            $candidate = interhome_safe_trim($tokens[$cursor] ?? '');
            if ($candidate === '') {
                $cursor++;
                continue;
            }
            if (preg_match('/^\(BOL\d+\)$/', $candidate)) {
                $propertyParts[] = $candidate;
                $cursor++;
                break;
            }
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $candidate)) {
                break;
            }
            $propertyParts[] = $candidate;
            $cursor++;
        }

        $name = interhome_safe_trim($tokens[$cursor] ?? '');
        $cursor++;

        $peopleRaw = interhome_safe_trim($tokens[$cursor] ?? '-');
        $cursor++;

        $phone = '';
        $email = '';
        $externalReference = '';
        $language = '';

        if ($cursor < $count && interhome_looks_like_phone(interhome_safe_trim($tokens[$cursor] ?? ''))) {
            $phone = interhome_safe_trim($tokens[$cursor] ?? '');
            $cursor++;
        }

        if ($cursor < $count && (strpos((string)($tokens[$cursor] ?? ''), '@') !== false || interhome_looks_like_email_fragment((string)($tokens[$cursor] ?? '')))) {
            $email = interhome_safe_trim($tokens[$cursor] ?? '');
            $cursor++;
            while ($cursor < $count && !interhome_email_looks_complete($email)) {
                $fragment = interhome_safe_trim($tokens[$cursor] ?? '');
                if ($fragment === '' || preg_match('/^[0-9]{9,15}$/', $fragment) || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fragment) || interhome_looks_like_phone($fragment) || strcasecmp($fragment, 'cancellata') === 0) {
                    break;
                }
                $email .= $fragment;
                $cursor++;
            }
        }

        if ($cursor < $count && preg_match('/^[0-9]{9,15}$/', (string)($tokens[$cursor] ?? ''))) {
            $externalReference = interhome_safe_trim($tokens[$cursor] ?? '');
            $cursor++;
        }

        if ($cursor < $count) {
            $language = interhome_safe_trim($tokens[$cursor] ?? '');
        }

        $rawProperty = interhome_safe_trim($propertyCode . ' ' . implode(' ', $propertyParts));
        $roomType = interhome_map_room_type($rawProperty);
        [$adults, $children] = interhome_parse_people($peopleRaw);

        $statusText = '';
        if (strcasecmp(interhome_safe_trim($tokens[$cursor] ?? ''), 'cancellata') === 0) {
            $statusText = 'cancellata';
            $cursor++;
        } elseif ($currentCancelledMarkers > 0) {
            $statusText = 'cancellata';
            $currentCancelledMarkers--;
        }

        $rows[] = [
            'stay_period' => $checkIn . ' - ' . $checkOut,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'room_type' => $roomType,
            'customer_name' => $name,
            'customer_phone' => $phone,
            'customer_email' => $email,
            'adults' => $adults,
            'children_count' => $children,
            'external_reference' => $externalReference,
            'source' => 'interhome_pdf',
            'status' => 'confermata',
            'notes' => null,
            '_raw_people' => $peopleRaw,
            '_raw_property' => $rawProperty,
            '_language' => $language,
            '_status_text' => $statusText,
            '_raw_row_text' => interhome_safe_spaces(implode(' | ', [$checkIn,$checkOut,$rawProperty,$name,$peopleRaw,$phone,$email,$externalReference,$language,$statusText])),
            '_page' => $pageNo,
        ];

        $i = max($i, $cursor - 1);
    }

    return ['rows' => $rows, 'notes' => $notes];
}

function interhome_looks_like_phone(string $value): bool
{
    $value = interhome_safe_trim($value);
    return $value !== '' && (str_starts_with($value, '+') || preg_match('/^[0-9][0-9 \/\-]{5,}$/', $value));
}

function interhome_looks_like_email_fragment(string $value): bool
{
    $value = interhome_safe_trim($value);
    return $value !== '' && (bool)preg_match('/^[A-Za-z0-9._%+\-]+$/', $value);
}

function interhome_email_looks_complete(string $email): bool
{
    return (bool)preg_match('/\.[A-Za-z]{2,}$/', interhome_safe_trim($email));
}

function interhome_parse_people(string $raw): array
{
    $raw = mb_strtolower(interhome_safe_trim($raw));
    if ($raw === '' || $raw === '-') {
        return [0, 0];
    }

    $adults = 0;
    $children = 0;
    if (preg_match('/(\d+)\s*adulti?/', $raw, $m)) $adults = (int)($m[1] ?? 0);
    if (preg_match('/(\d+)\s*bambin[io]/', $raw, $m)) $children = (int)($m[1] ?? 0);
    return [$adults, $children];
}

function interhome_map_room_type(string $rawProperty): string
{
    $rawProperty = interhome_safe_trim($rawProperty);
    $normalized = mb_strtolower($rawProperty);
    $normalized = str_replace(['º', '°'], '°', $normalized);
    $normalized = preg_replace('/\s+/u', ' ', $normalized);
    $normalized = trim((string)($normalized ?? ''));

    if (preg_match('/porzione di casa\s*,?\s*n[°o]?\s*([1-6])\b/ui', $normalized, $m)) {
        return interhome_room_number_to_name((int)($m[1] ?? 0));
    }
    if (preg_match('/casa\s*,?\s*n[°o]?\s*([1-6])\b/ui', $normalized, $m)) {
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
