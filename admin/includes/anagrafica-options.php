<?php

declare(strict_types=1);

function anagrafica_data_path(string $relative): string
{
    return dirname(__DIR__) . '/data/' . ltrim($relative, '/');
}

function anagrafica_normalize_lookup(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($trans) && $trans !== '') {
        $value = $trans;
    }

    $value = strtoupper($value);
    $value = str_replace(["'", '’', '`', '´'], ' ', $value);
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?: '';
    return trim($value);
}

function anagrafica_csv_rows(string $relative): array
{
    static $cache = [];

    if (isset($cache[$relative])) {
        return $cache[$relative];
    }

    $path = anagrafica_data_path($relative);
    if (!is_file($path)) {
        return $cache[$relative] = [];
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return $cache[$relative] = [];
    }

    $rows = [];
    $headers = fgetcsv($handle);
    if (!is_array($headers)) {
        fclose($handle);
        return $cache[$relative] = [];
    }

    $headers = array_map(static fn($value) => trim((string) $value), $headers);

    while (($data = fgetcsv($handle)) !== false) {
        if ($data === [null] || $data === false) {
            continue;
        }

        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = isset($data[$index]) ? trim((string) $data[$index]) : '';
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $cache[$relative] = $rows;
}

function anagrafica_active_rows(array $rows): array
{
    return array_values(array_filter($rows, static function (array $row): bool {
        return trim((string) ($row['DataFineVal'] ?? '')) === '';
    }));
}

function anagrafica_document_type_rows(): array
{
    return anagrafica_csv_rows('alloggiati/documenti.csv');
}

function anagrafica_state_rows(): array
{
    return anagrafica_active_rows(anagrafica_csv_rows('alloggiati/stati.csv'));
}

function anagrafica_comune_rows(): array
{
    return anagrafica_active_rows(anagrafica_csv_rows('alloggiati/comuni.csv'));
}

function anagrafica_tipo_alloggiato_rows(): array
{
    return anagrafica_csv_rows('alloggiati/tipo_alloggiato.csv');
}

function anagrafica_document_types(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (anagrafica_document_type_rows() as $row) {
        $code = trim((string) ($row['Codice'] ?? ''));
        $label = trim((string) ($row['Descrizione'] ?? ''));
        if ($code !== '' && $label !== '') {
            $map[$code] = $label;
        }
    }

    return $map;
}

function anagrafica_state_options(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (anagrafica_state_rows() as $row) {
        $code = trim((string) ($row['Codice'] ?? ''));
        $label = trim((string) ($row['Descrizione'] ?? ''));
        if ($code !== '' && $label !== '') {
            $map[$code] = $label;
        }
    }

    asort($map, SORT_NATURAL | SORT_FLAG_CASE);
    return $map;
}

function anagrafica_eu_citizenships(): array
{
    $wanted = [
        'AUSTRIA', 'BELGIO', 'BULGARIA', 'CROAZIA', 'CIPRO', 'CECHIA', 'DANIMARCA', 'ESTONIA', 'FINLANDIA',
        'FRANCIA', 'GERMANIA', 'GRECIA', 'UNGHERIA', 'IRLANDA', 'ITALIA', 'LETTONIA', 'LITUANIA',
        'LUSSEMBURGO', 'MALTA', 'PAESI BASSI', 'POLONIA', 'PORTOGALLO', 'ROMANIA', 'SLOVACCHIA',
        'SLOVENIA', 'SPAGNA', 'SVEZIA',
    ];
    $wanted = array_fill_keys($wanted, true);

    $out = [];
    foreach (anagrafica_state_options() as $code => $label) {
        if (isset($wanted[anagrafica_normalize_lookup($label)])) {
            $out[$code] = $label;
        }
    }

    return $out;
}

function anagrafica_province_italiane(): array
{
    return [
        'AG' => 'Agrigento', 'AL' => 'Alessandria', 'AN' => 'Ancona', 'AO' => 'Aosta', 'AR' => 'Arezzo', 'AP' => 'Ascoli Piceno', 'AT' => 'Asti', 'AV' => 'Avellino',
        'BA' => 'Bari', 'BT' => 'Barletta-Andria-Trani', 'BL' => 'Belluno', 'BN' => 'Benevento', 'BG' => 'Bergamo', 'BI' => 'Biella', 'BO' => 'Bologna', 'BZ' => 'Bolzano', 'BS' => 'Brescia', 'BR' => 'Brindisi',
        'CA' => 'Cagliari', 'CL' => 'Caltanissetta', 'CB' => 'Campobasso', 'CI' => 'Carbonia-Iglesias', 'CE' => 'Caserta', 'CT' => 'Catania', 'CZ' => 'Catanzaro', 'CH' => 'Chieti', 'CO' => 'Como', 'CS' => 'Cosenza', 'CR' => 'Cremona', 'KR' => 'Crotone', 'CN' => 'Cuneo',
        'EN' => 'Enna', 'FM' => 'Fermo', 'FE' => 'Ferrara', 'FI' => 'Firenze', 'FG' => 'Foggia', 'FC' => 'Forlì-Cesena', 'FR' => 'Frosinone',
        'GE' => 'Genova', 'GO' => 'Gorizia', 'GR' => 'Grosseto',
        'IM' => 'Imperia', 'IS' => 'Isernia',
        'AQ' => "L'Aquila", 'SP' => 'La Spezia', 'LT' => 'Latina', 'LE' => 'Lecce', 'LC' => 'Lecco', 'LI' => 'Livorno', 'LO' => 'Lodi', 'LU' => 'Lucca',
        'MC' => 'Macerata', 'MN' => 'Mantova', 'MS' => 'Massa-Carrara', 'MT' => 'Matera', 'VS' => 'Medio Campidano', 'ME' => 'Messina', 'MI' => 'Milano', 'MO' => 'Modena', 'MB' => 'Monza e Brianza',
        'NA' => 'Napoli', 'NO' => 'Novara', 'NU' => 'Nuoro',
        'OR' => 'Oristano',
        'PD' => 'Padova', 'PA' => 'Palermo', 'PR' => 'Parma', 'PV' => 'Pavia', 'PG' => 'Perugia', 'PU' => 'Pesaro e Urbino', 'PE' => 'Pescara', 'PC' => 'Piacenza', 'PI' => 'Pisa', 'PT' => 'Pistoia', 'PN' => 'Pordenone', 'PZ' => 'Potenza', 'PO' => 'Prato',
        'RG' => 'Ragusa', 'RA' => 'Ravenna', 'RC' => 'Reggio Calabria', 'RE' => 'Reggio Emilia', 'RI' => 'Rieti', 'RN' => 'Rimini', 'RM' => 'Roma', 'RO' => 'Rovigo',
        'SA' => 'Salerno', 'SS' => 'Sassari', 'SV' => 'Savona', 'SI' => 'Siena', 'SR' => 'Siracusa', 'SO' => 'Sondrio', 'SU' => 'Sud Sardegna',
        'TA' => 'Taranto', 'TE' => 'Teramo', 'TR' => 'Terni', 'TO' => 'Torino', 'TP' => 'Trapani', 'TN' => 'Trento', 'TV' => 'Treviso', 'TS' => 'Trieste',
        'UD' => 'Udine',
        'VA' => 'Varese', 'VE' => 'Venezia', 'VB' => 'Verbano-Cusio-Ossola', 'VC' => 'Vercelli', 'VR' => 'Verona', 'VV' => 'Vibo Valentia', 'VI' => 'Vicenza', 'VT' => 'Viterbo',
    ];
}

function anagrafica_booking_channels(): array
{
    return ['Diretta tradizionale', 'Diretta web', 'Indiretta tradizionale', 'Indiretta web', 'Altro canale', 'Non specificato'];
}

function anagrafica_tourism_types(): array
{
    return ['Culturale', 'Balneare', 'Congressuale/Affari', 'Fieristico', 'Sportivo/Fitness', 'Scolastico', 'Religioso', 'Sociale', 'Parchi Tematici', 'Termale/Trattamenti salute', 'Enogastronomico', 'Cicloturismo', 'Escursionistico/Naturalistico', 'Altro motivo', 'Non specificato'];
}

function anagrafica_transport_types(): array
{
    return ['Auto', 'Aereo', 'Aereo+Pullman', 'Aereo+Navetta/Taxi/Auto', 'Aereo+Treno', 'Treno', 'Pullman', 'Caravan/Autocaravan', 'Barca/Nave/Traghetto', 'Moto', 'Bicicletta', 'A piedi', 'Altro mezzo', 'Non Specificato'];
}

function anagrafica_tipo_alloggiato_label(string $code): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (anagrafica_tipo_alloggiato_rows() as $row) {
            $rowCode = trim((string) ($row['Codice'] ?? ''));
            $label = trim((string) ($row['Descrizione'] ?? ''));
            if ($rowCode !== '') {
                $map[$rowCode] = $label;
            }
        }
    }

    return $map[$code] ?? $code;
}

function anagrafica_map_tipo_alloggiato_code(string $recordType, int $guestIndex): string
{
    if ($recordType === 'group') {
        return $guestIndex === 0 ? '18' : '20';
    }

    return '16';
}

function anagrafica_map_document_type_code(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $types = anagrafica_document_types();
    if (isset($types[$value])) {
        return $value;
    }

    $normalized = anagrafica_normalize_lookup($value);
    $legacyMap = [
        'CARTA IDENTITA ELETTRONICA' => 'IDELE',
        'CARTA D IDENTITA ELETTRONICA' => 'IDELE',
        'CARTA IDENTITA' => 'IDENT',
        'CARTA D IDENTITA' => 'IDENT',
        'PASSAPORTO' => 'PASOR',
        'PASSAPORTO ORDINARIO' => 'PASOR',
        'ALTRO' => 'ALTRO',
    ];
    if (isset($legacyMap[$normalized])) {
        $code = $legacyMap[$normalized];
        return $code === 'ALTRO' ? null : $code;
    }

    foreach ($types as $code => $label) {
        if (anagrafica_normalize_lookup($label) === $normalized) {
            return $code;
        }
    }

    if (str_contains($normalized, 'PASSAPORT')) {
        return 'PASOR';
    }
    if (str_contains($normalized, 'ELETTRON')) {
        return 'IDELE';
    }
    if (str_contains($normalized, 'IDENTITA')) {
        return 'IDENT';
    }

    return null;
}

function anagrafica_map_state_code(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $states = anagrafica_state_options();
    if (isset($states[$value])) {
        return $value;
    }

    $normalized = anagrafica_normalize_lookup($value);
    foreach ($states as $code => $label) {
        if (anagrafica_normalize_lookup($label) === $normalized) {
            return $code;
        }
    }

    return null;
}

function anagrafica_state_label_from_code(?string $code): ?string
{
    if ($code === null || trim($code) === '') {
        return null;
    }

    $states = anagrafica_state_options();
    return $states[$code] ?? null;
}

function anagrafica_find_comune(?string $value, ?string $province = null): ?array
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $normalizedValue = anagrafica_normalize_lookup($value);
    $normalizedProvince = $province ? strtoupper(trim($province)) : null;
    $matches = [];

    foreach (anagrafica_comune_rows() as $row) {
        $label = trim((string) ($row['Descrizione'] ?? ''));
        if (anagrafica_normalize_lookup($label) !== $normalizedValue) {
            continue;
        }
        if ($normalizedProvince !== null && $normalizedProvince !== '' && strtoupper((string) ($row['Provincia'] ?? '')) !== $normalizedProvince) {
            continue;
        }
        $matches[] = $row;
    }

    if (count($matches) === 1) {
        return $matches[0];
    }

    return null;
}

function anagrafica_comune_label_from_code(?string $code): ?string
{
    if ($code === null || trim($code) === '') {
        return null;
    }

    foreach (anagrafica_comune_rows() as $row) {
        if ((string) ($row['Codice'] ?? '') === (string) $code) {
            return trim((string) ($row['Descrizione'] ?? ''));
        }
    }

    return null;
}
