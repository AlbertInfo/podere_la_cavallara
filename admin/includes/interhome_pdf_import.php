<?php
declare(strict_types=1);

/**
 * Interhome PDF import helper.
 *
 * Strategy:
 * - text extraction from raw PDF streams (pure PHP, no pdftotext needed)
 * - page rasterization with Imagick
 * - status detection from first icon column
 * - safe fail: if per-page status count != parsed rows count, import is blocked
 */

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
    if (!file_exists($pdfPath)) {
        throw new RuntimeException('PDF non trovato.');
    }

    $pages = interhome_pdf_extract_pages($pdfPath);
    if (!$pages) {
        throw new RuntimeException('Impossibile leggere il contenuto testuale del PDF Interhome.');
    }

    $allRows = [];
    $globalNotes = [];
    $cancelledCount = 0;
    $duplicatesSkipped = 0;
    $tmpDir = sys_get_temp_dir() . '/interhome_import_' . bin2hex(random_bytes(6));

    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('Impossibile creare la cartella temporanea di parsing.');
    }

    try {
        foreach ($pages as $pageNo => $pageData) {
            $rowsData = interhome_parse_page_tokens($pageData['tokens'], (int) $pageNo);

            foreach ($pageData['notes'] as $ref => $note) {
                $globalNotes[(string) $ref] = $note;
            }

            foreach ($rowsData['notes'] as $ref => $note) {
                $globalNotes[(string) $ref] = $note;
            }

            $expectedCount = count($rowsData['rows']);
            if ($expectedCount === 0) {
                continue;
            }

            $pngPath = interhome_render_pdf_page_to_png($pdfPath, ((int) $pageNo) - 1, $tmpDir);
            $statuses = interhome_detect_statuses_from_png($pngPath, $expectedCount);

            if (count($statuses) !== $expectedCount) {
                throw new RuntimeException(
                    sprintf(
                        'Controllo di sicurezza fallito alla pagina %d: rilevate %d icone stato per %d prenotazioni. Import bloccato per evitare errori.',
                        $pageNo,
                        count($statuses),
                        $expectedCount
                    )
                );
            }

            foreach ($rowsData['rows'] as $index => $row) {
                $statusIcon = $statuses[$index]['status'] ?? 'unknown';
                if ($statusIcon === 'cancelled') {
                    $cancelledCount++;
                }

                $row['status_icon'] = $statusIcon;
                $row['_page'] = (int) $pageNo;
                $row['_page_index'] = $index;

                $externalRef = interhome_safe_trim($row['external_reference'] ?? '');
                $row['notes'] = $globalNotes[$externalRef] ?? ($row['notes'] ?? null);

                $allRows[] = $row;
            }
        }
    } finally {
        interhome_remove_dir($tmpDir);
    }

    foreach ($allRows as &$row) {
        $externalRef = interhome_safe_trim($row['external_reference'] ?? '');
        if ($externalRef !== '' && isset($globalNotes[$externalRef])) {
            $row['notes'] = $globalNotes[$externalRef];
        }
    }
    unset($row);

    $allRows = array_values(array_filter($allRows, static function (array $row): bool {
        return ($row['status_icon'] ?? '') !== 'cancelled';
    }));

    $beforeFilter = count($allRows);
    $allRows = interhome_filter_existing_rows($pdo, $allRows);
    $duplicatesSkipped = $beforeFilter - count($allRows);

    $summary = [
        'found_total' => count($allRows),
        'duplicates_skipped' => $duplicatesSkipped,
        'cancelled_skipped' => $cancelledCount,
        'pages' => count($pages),
    ];

    return [
        'rows' => $allRows,
        'summary' => $summary,
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
    $existingRefs = [];

    if ($refs) {
        $placeholders = implode(',', array_fill(0, count($refs), '?'));
        $stmt = $pdo->prepare("SELECT external_reference FROM prenotazioni WHERE external_reference IN ($placeholders)");
        $stmt->execute($refs);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ref) {
            $existingRefs[(string) $ref] = true;
        }
    }

    $seenInDocument = [];
    $filtered = [];

    foreach ($rows as $row) {
        $key = interhome_safe_trim($row['external_reference'] ?? '');

        if ($key !== '') {
            if (isset($existingRefs[$key])) {
                continue;
            }
            if (isset($seenInDocument[$key])) {
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
        $stream = $rawStream;

        $decoded = @gzuncompress($stream);
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
        $token = interhome_safe_trim($token);

        if (stripos($token, 'Note:') === 0) {
            if (preg_match('/prenotazione n\.?\s*([0-9]{9,15})/i', $token, $m)) {
                $ref = interhome_safe_trim($m[1] ?? '');
                if ($ref !== '') {
                    $notes[$ref] = $token;
                }
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

    for ($i = $startIndex; $i < $count; $i++) {
        $token = interhome_safe_trim($tokens[$i] ?? '');

        if (stripos($token, 'Note:') === 0) {
            if (preg_match('/prenotazione n\.?\s*([0-9]{9,15})/i', $token, $m)) {
                $ref = interhome_safe_trim($m[1] ?? '');
                if ($ref !== '') {
                    $notes[$ref] = $token;
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

            // stop if a new date starts unexpectedly
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

        if (
            $cursor < $count
            && (
                strpos((string) ($tokens[$cursor] ?? ''), '@') !== false
                || interhome_looks_like_email_fragment((string) ($tokens[$cursor] ?? ''))
            )
        ) {
            $email = interhome_safe_trim($tokens[$cursor] ?? '');
            $cursor++;

            while ($cursor < $count && !interhome_email_looks_complete($email)) {
                $fragment = interhome_safe_trim($tokens[$cursor] ?? '');

                if (
                    $fragment === ''
                    || preg_match('/^[0-9]{9,15}$/', $fragment)
                    || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $fragment)
                    || interhome_looks_like_phone($fragment)
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
            $language = interhome_safe_trim($tokens[$cursor] ?? '');
        }

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
        ];

        $i = max($i, $cursor - 1);
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

function interhome_render_pdf_page_to_png(string $pdfPath, int $pageIndex, string $tmpDir): string
{
    $imagick = new Imagick();
    $imagick->setResolution(150, 150);
    $imagick->readImage($pdfPath . '[' . $pageIndex . ']');
    $imagick->setImageBackgroundColor('white');
    $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
    $imagick->setImageFormat('png');

    $path = $tmpDir . '/page-' . ($pageIndex + 1) . '.png';
    $imagick->writeImage($path);
    $imagick->clear();
    $imagick->destroy();

    return $path;
}

function interhome_detect_statuses_from_png(string $pngPath, int $expectedCount): array
{
    $img = new Imagick($pngPath);
    $width = $img->getImageWidth();
    $height = $img->getImageHeight();

    $headerY = interhome_find_table_header_y($img, $width, $height);
    $scanStartY = (int) max(0, $headerY + 40);
    $scanEndY = (int) min($height - 140, $height - 1);

    $xStart = (int) max(20, $width * 0.03);
    $xEnd = (int) min(110, $width * 0.09);
    $regionWidth = max(1, $xEnd - $xStart);
    $regionHeight = max(1, $scanEndY - $scanStartY);

    $pixels = $img->exportImagePixels($xStart, $scanStartY, $regionWidth, $regionHeight, 'RGB', Imagick::PIXEL_CHAR);
    $img->clear();
    $img->destroy();

    $scores = [];
    $classScores = [];
    $idx = 0;

    for ($y = 0; $y < $regionHeight; $y++) {
        $rowScore = 0;
        $green = 0;
        $red = 0;
        $gray = 0;

        for ($x = 0; $x < $regionWidth; $x++) {
            $r = $pixels[$idx++] ?? 0;
            $g = $pixels[$idx++] ?? 0;
            $b = $pixels[$idx++] ?? 0;

            if (($r > 200 && $g > 210 && $b > 220) || ($r > 240 && $g > 240 && $b > 240)) {
                continue;
            }

            if ($g > 120 && $r < 120 && $b < 180) {
                $green++;
                $rowScore++;
                continue;
            }

            if ($r > 170 && $g < 130 && $b < 130) {
                $red++;
                $rowScore++;
                continue;
            }

            if ($r > 45 && $r < 145 && abs($r - $g) < 24 && abs($g - $b) < 24) {
                $gray++;
                $rowScore++;
                continue;
            }
        }

        $scores[$y] = $rowScore;
        $classScores[$y] = [
            'green' => $green,
            'red' => $red,
            'gray' => $gray,
        ];
    }

    $bands = [];
    $inBand = false;
    $start = 0;
    $minScore = 8;

    for ($y = 0; $y < $regionHeight; $y++) {
        $active = ($scores[$y] ?? 0) >= $minScore;

        if ($active && !$inBand) {
            $start = $y;
            $inBand = true;
        } elseif (!$active && $inBand) {
            $bands[] = [$start, $y - 1];
            $inBand = false;
        }
    }

    if ($inBand) {
        $bands[] = [$start, $regionHeight - 1];
    }

    $merged = [];
    foreach ($bands as $band) {
        if (!$merged) {
            $merged[] = $band;
            continue;
        }

        $lastIndex = count($merged) - 1;
        if ($band[0] - $merged[$lastIndex][1] <= 3) {
            $merged[$lastIndex][1] = $band[1];
        } else {
            $merged[] = $band;
        }
    }

    $merged = array_values(array_filter($merged, static fn(array $band): bool => ($band[1] - $band[0]) >= 8));

    if (count($merged) > $expectedCount) {
        $merged = array_slice($merged, 0, $expectedCount);
    }

    $statuses = [];
    foreach ($merged as $band) {
        $green = 0;
        $red = 0;
        $gray = 0;

        for ($y = $band[0]; $y <= $band[1]; $y++) {
            $green += $classScores[$y]['green'] ?? 0;
            $red += $classScores[$y]['red'] ?? 0;
            $gray += $classScores[$y]['gray'] ?? 0;
        }

        $status = 'existing';
        if ($red > max($green, $gray) && $red > 25) {
            $status = 'cancelled';
        } elseif ($green >= $gray && $green > 25) {
            $status = 'new';
        } else {
            $status = 'existing';
        }

        $statuses[] = [
            'status' => $status,
            'score' => ['green' => $green, 'red' => $red, 'gray' => $gray],
            'y_start' => $band[0],
            'y_end' => $band[1],
        ];
    }

    return $statuses;
}

function interhome_find_table_header_y(Imagick $img, int $width, int $height): int
{
    $x = (int) max(40, $width * 0.10);
    $w = (int) min($width - 80, $width * 0.80);
    $pixels = $img->exportImagePixels($x, 0, $w, $height, 'RGB', Imagick::PIXEL_CHAR);

    $lightBlueScores = [];
    $idx = 0;

    for ($y = 0; $y < $height; $y++) {
        $score = 0;

        for ($px = 0; $px < $w; $px++) {
            $r = $pixels[$idx++] ?? 0;
            $g = $pixels[$idx++] ?? 0;
            $b = $pixels[$idx++] ?? 0;

            if ($r > 180 && $g > 205 && $b > 220 && ($b - $r) > 10) {
                $score++;
            }
        }

        $lightBlueScores[$y] = $score;
    }

    $threshold = (int) max(40, $w * 0.35);

    for ($y = 0; $y < $height; $y++) {
        if (($lightBlueScores[$y] ?? 0) >= $threshold) {
            return $y;
        }
    }

    return (int) ($height * 0.40);
}

function interhome_remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!$items) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            interhome_remove_dir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }

    @rmdir($dir);
}