<?php

declare(strict_types=1);

function admin_ical_fetch(string $url, int $timeout = 12): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'PodereLaCavallaraAdmin/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            error_log('ICAL fetch failed: ' . $url . ' status=' . $status . ' error=' . $error);
            return '';
        }

        return (string) $response;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => "User-Agent: PodereLaCavallaraAdmin/1.0\r\n",
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    return $response !== false ? (string) $response : '';
}

function admin_ical_unfold_lines(string $ics): array
{
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    $lines = explode("\n", $ics);
    $result = [];

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        if (!empty($result) && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
            $result[count($result) - 1] .= ltrim($line);
            continue;
        }

        $result[] = trim($line);
    }

    return $result;
}

function admin_ical_parse_datetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{8}$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd', $value);
        return $dt ? $dt->format('Y-m-d 00:00:00') : null;
    }

    if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        return $dt ? $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s') : null;
    }

    if (preg_match('/^\d{8}T\d{6}$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $value);
        return $dt ? $dt->format('Y-m-d H:i:s') : null;
    }

    return null;
}

function admin_ical_parse_date_only(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{8}$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd', $value);
        return $dt ? $dt->format('Y-m-d') : null;
    }

    if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        return $dt ? $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d') : null;
    }

    if (preg_match('/^\d{8}T\d{6}$/', $value)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $value);
        return $dt ? $dt->format('Y-m-d') : null;
    }

    return null;
}

function admin_ical_normalize_text(?string $value): string
{
    $value = (string) $value;
    $value = str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
    return trim($value);
}

function admin_ical_extract_guest_name(array $event): string
{
    $summary = trim((string) ($event['summary'] ?? ''));
    if ($summary !== '') {
        return $summary;
    }

    $description = trim((string) ($event['description'] ?? ''));
    if ($description !== '') {
        $lines = preg_split('/\R+/', $description);
        if (!empty($lines[0])) {
            return trim((string) $lines[0]);
        }
    }

    return 'Ospite iCal';
}

function admin_ical_parse(string $ics, array $feedMeta = []): array
{
    $lines = admin_ical_unfold_lines($ics);
    $events = [];
    $current = null;
    $insideEvent = false;

    foreach ($lines as $line) {
        if ($line === 'BEGIN:VEVENT') {
            $insideEvent = true;
            $current = [];
            continue;
        }

        if ($line === 'END:VEVENT') {
            if (is_array($current)) {
                $startRaw = $current['DTSTART'] ?? null;
                $endRaw = $current['DTEND'] ?? null;

                $checkIn = admin_ical_parse_date_only($startRaw);
                $checkOut = admin_ical_parse_date_only($endRaw);
                $startAt = admin_ical_parse_datetime($startRaw);
                $endAt = admin_ical_parse_datetime($endRaw);

                $events[] = [
                    'uid' => trim((string) ($current['UID'] ?? '')),
                    'summary' => admin_ical_normalize_text($current['SUMMARY'] ?? ''),
                    'description' => admin_ical_normalize_text($current['DESCRIPTION'] ?? ''),
                    'location' => admin_ical_normalize_text($current['LOCATION'] ?? ''),
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'guest_name' => admin_ical_extract_guest_name([
                        'summary' => $current['SUMMARY'] ?? '',
                        'description' => $current['DESCRIPTION'] ?? '',
                    ]),
                    'feed_label' => (string) ($feedMeta['label'] ?? 'Feed iCal'),
                    'feed_source' => (string) ($feedMeta['source'] ?? 'ical'),
                    'raw' => $current,
                ];
            }

            $insideEvent = false;
            $current = null;
            continue;
        }

        if (!$insideEvent || !is_array($current)) {
            continue;
        }

        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        [$rawKey, $value] = $parts;
        $key = strtoupper(trim(explode(';', $rawKey)[0]));
        $current[$key] = $value;
    }

    return $events;
}

function admin_ical_load_events(array $feeds): array
{
    $all = [];

    foreach ($feeds as $feed) {
        $url = trim((string) ($feed['url'] ?? ''));
        if ($url === '') {
            continue;
        }

        $ics = admin_ical_fetch($url);
        if ($ics === '') {
            continue;
        }

        $events = admin_ical_parse($ics, $feed);
        foreach ($events as $event) {
            $all[] = $event;
        }
    }

    usort($all, static function (array $a, array $b): int {
        $aDate = (string) ($a['check_in'] ?? '');
        $bDate = (string) ($b['check_in'] ?? '');
        return strcmp($aDate, $bDate);
    });

    return $all;
}