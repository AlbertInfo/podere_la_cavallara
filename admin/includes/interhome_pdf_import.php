<?php
declare(strict_types=1);

function interhome_safe_trim(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function interhome_safe_spaces(mixed $value): string
{
    $string = (string) ($value ?? '');
    $normalized = preg_replace('/\s+/u', ' ', $string);
    return trim((string) ($normalized ?? $string));
}

function interhome_import_parse_pdf(string $pdfPath, PDO $pdo): array
{
    if (!is_file($pdfPath)) {
        throw new RuntimeException('PDF non trovato.');
    }

    $pages = interhome_pdf_extract_pages($pdfPath);
    if (!$pages) {
        throw new RuntimeException('Impossibile leggere il contenuto del PDF Interhome.');
    }

    $rows = [];
    $globalNotes = [];
    $cancelledSkipped = 0;

    foreach ($pages as $pageNo => $pageData) {
        foreach ($pageData['notes'] as $ref => $note) {
            $globalNotes[(string) $ref] = $note;
        }

        $parsed = interhome_parse_page_tokens($pageData['tokens'], (int) $pageNo);

        foreach ($parsed['notes'] as $ref => $note) {
            $globalNotes[(string) $ref] = $note;
        }

        foreach ($parsed['rows'] as $row) {
            $ref = interhome_safe_trim($row['external_reference'] ?? '');
            if ($ref !== '' && isset($globalNotes[$ref])) {
                $row['notes'] = $globalNotes[$ref];
            }

            if (!empty($row['_cancelled'])) {
                $cancelledSkipped++;
                continue;
            }

            $rows[] = $row;
        }
    }

    $beforeFilter = count($rows);
    $rows = interhome_filter_existing_rows($pdo, $rows);
    $duplicatesSkipped = $beforeFilter - count($rows);

    return [
        'rows' => array_values($rows),
        'summary' => [
            'pages' => count($pages),
            'found_total' => count($rows),
            'duplicates_skipped' => $duplicatesSkipped,
            'cancelled_skipped' => $cancelledSkipped,
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
    $existing = [];

    if ($refs) {
        $placeholders = implode(',', array_fill(0, count($refs), '?'));
        $stmt = $pdo->prepare("SELECT external_reference FROM prenotazioni WHERE external_reference IN ($placeholders)");
        $stmt->execute($refs);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
            $existing[(string) $ref] = true;
        }
    }

    $seenInDocument = [];
    $filtered = [];

    foreach ($rows as $row) {
        $key = interhome_safe_trim($row['external_reference'] ?? '');
        if ($key !== '') {
            if (isset($existing[$key]) || isset($seenInDocument[$key])) {
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
        $decoded = @gzuncompress($rawStream);
        if ($decoded === false) {
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
        if (strpos($joined, 'Dettagli') === false) {
            continue;
        }

        if (!preg_match('/\b([1-9]\d*)\/([1-9]\d*)\b/', $joined, $m)) {
            continue;
        }

        $pageNo = (int) ($m[1] ?? 0);
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

function interhome_extract_notes_from_tokens(array $tokens): array
{
    $notes = [];
    foreach ($tokens as $token) {
        $token = interhome_safe_spaces($token);
        if (stripos($token, 'Note:') === 0 && preg_match('/prenotazione n\.?\s*([0-9]{9,15})/i', $token, $m)) {
            $ref = interhome_safe_trim($m[1] ?? '');
            if ($ref !== '') {
                $notes[$ref] = $token;
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
                        $text .= interhome_unescape_pdf_string(substr((string) $part, 1, -1));
                    }
                    $text = interhome_safe_spaces($text);
                    if ($text !== '') {
                        $tokens[] = $text;
                    }
                }
            }
        }

        if (preg_match_all('/(\((?:\\\\.|[^\\\\)])*\))\s*Tj/s', $block, $stringMatches)) {
            foreach ($stringMatches[1] ?? [] as $part) {
                $rawText = interhome_unescape_pdf_string(substr((string) $part, 1, -1));
                $text = interhome_safe_spaces($rawText);
                if ($text !== '') {
                    $tokens[] = $text;
                }
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

        if ($i + 1 >= $len) {
            break;
        }

        $i++;
        $next = $text[$i];

        $map = [
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\b",
            'f' => "\f",
            '(' => '(',
            ')' => ')',
            '\\' => '\\',
        ];

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
    $cancellationMarkers = 0;
    foreach ($tokens as $token) {
        if (mb_strtolower(interhome_safe_trim($token)) === 'cancellata') {
            $cancellationMarkers++;
        }
    }

    for ($i = $startIndex; $i < $count; $i++) {
        $token = interhome_safe_trim($tokens[$i] ?? '');

        if (stripos($token, 'Note:') === 0) {
            if (preg_match('/prenotazione n\.?\s*([0-9]{9,15})/i', $token, $m)) {
                $ref = interhome_safe_trim($m[1] ?? '');
                if ($ref !== '') {
                    $notes[$ref] = interhome_safe_spaces($token);
                }
            }
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

        if ($cursor < $count && (strpos((string) ($tokens[$cursor] ?? ''), '@') !== false || interhome_looks_like_email_fragment((string) ($tokens[$cursor] ?? '')))) {
            $email = interhome_safe_trim($tokens[$cursor] ?? '');
            $cursor++;
            while ($cursor < $count && !interhome_email_looks_complete($email)) {
                $fragment = interhome_safe_trim($tokens[$cursor] ?? '');
                if (
                    $fragment === ''
                    || preg_match('/^[0-9]{9,15}$/', $fragment)
                    || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fragment)
                    || interhome_looks_like_phone($fragment)
                    || mb_strtolower($fragment) === 'cancellata'
                ) {
                    break;
                }
                $email .= $fragment;
                $cursor++;
            }
        }

        if ($cursor < $count && preg_match('/^[0-9]{9,15}$/', (string) ($tokens[$cursor] ?? ''))) {
            $externalReference = interhome_safe_trim($tokens[$cursor] ?? '');
            $cursor++;
        }

        if ($cursor < $count) {
            $possibleLanguage = interhome_safe_trim($tokens[$cursor] ?? '');
            if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $possibleLanguage) && mb_strtolower($possibleLanguage) !== 'cancellata') {
                $language = $possibleLanguage;
                $cursor++;
            }
        }

        $rowChunkTokens = [];
        $lookahead = $cursor;
        while ($lookahead < $count) {
            $candidate = interhome_safe_trim($tokens[$lookahead] ?? '');
            if ($candidate === '') {
                $lookahead++;
                continue;
            }
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $candidate)) {
                break;
            }
            $rowChunkTokens[] = mb_strtolower($candidate);
            $lookahead++;
        }

        $isCancelled = in_array('cancellata', $rowChunkTokens, true);

        $rawProperty = interhome_safe_trim($propertyCode . ' ' . implode(' ', $propertyParts));
        $roomType = interhome_map_room_type($rawProperty);
        [$adults, $children] = interhome_parse_people($peopleRaw);

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
            '_page' => $pageNo,
            '_cancelled' => $isCancelled,
        ];

        $i = max($i, $cursor - 1);
    }

    if ($cancellationMarkers > 0) {
        $alreadyMarked = 0;
        foreach ($rows as $row) {
            if (!empty($row['_cancelled'])) {
                $alreadyMarked++;
            }
        }

        if ($alreadyMarked < $cancellationMarkers) {
            $missing = $cancellationMarkers - $alreadyMarked;
            // fallback: mark rows containing the same-date block nearest the end of the page.
            for ($r = count($rows) - 1; $r >= 0 && $missing > 0; $r--) {
                if (!empty($rows[$r]['_cancelled'])) {
                    continue;
                }
                $rows[$r]['_cancelled'] = true;
                $missing--;
            }
        }
    }

    return [
        'rows' => $rows,
        'notes' => $notes,
    ];
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
    return (bool) preg_match('/\.[A-Za-z]{2,}$/', interhome_safe_trim($email));
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
        $adults = (int) ($m[1] ?? 0);
    }
    if (preg_match('/(\d+)\s*bambin[io]/', $raw, $m)) {
        $children = (int) ($m[1] ?? 0);
    }

    return [$adults, $children];
}

function interhome_map_room_type(string $rawProperty): string
{
    $rawProperty = interhome_safe_trim($rawProperty);

    if (preg_match('/n[°ºo]?\s*1\b/ui', $rawProperty)) {
        return 'Casa Domenico 1';
    }
    if (preg_match('/n[°ºo]?\s*2\b/ui', $rawProperty)) {
        return 'Casa Domenico 2';
    }
    if (preg_match('/n[°ºo]?\s*3\b/ui', $rawProperty)) {
        return 'Casa Riccardo 3';
    }
    if (preg_match('/n[°ºo]?\s*4\b/ui', $rawProperty)) {
        return 'Casa Riccardo 4';
    }
    if (preg_match('/n[°ºo]?\s*5\b/ui', $rawProperty)) {
        return 'Casa Alessandro 5';
    }
    if (preg_match('/n[°ºo]?\s*6\b/ui', $rawProperty)) {
        return 'Casa Alessandro 6';
    }

    return '';
}
