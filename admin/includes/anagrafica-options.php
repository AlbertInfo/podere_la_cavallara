<?php

declare(strict_types=1);

function anagrafica_alloggiati_data_dir(): string
{
    return dirname(__DIR__) . '/data/alloggiati';
}

function anagrafica_load_csv_records(string $filename): array
{
    static $cache = [];

    $path = anagrafica_alloggiati_data_dir() . '/' . ltrim($filename, '/');
    if (isset($cache[$path])) {
        return $cache[$path];
    }

    if (!is_file($path)) {
        return $cache[$path] = [];
    }

    $rows = [];
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return $cache[$path] = [];
    }

    $headers = [];
    while (($line = fgets($handle)) !== false) {
        $line = trim((string) preg_replace('/^ï»¿/', '', $line));
        if ($line == '') {
            continue;
        }

        $columns = str_getcsv($line);
        if (!$headers) {
            $headers = $columns;
            continue;
        }

        if (count($columns) < count($headers)) {
            $columns = array_pad($columns, count($headers), '');
        }

        $rows[] = array_combine($headers, array_slice($columns, 0, count($headers)));
    }

    fclose($handle);

    return $cache[$path] = $rows;
}

function anagrafica_normalize_lookup_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = str_replace(["
", "
", "	"], ' ', $value);

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = strtoupper($value);
    $value = str_replace(['’', "'", '`', '.', ',', ';', ':', '/', '\\', '-', '_', '(', ')', '[', ']', '{', '}', '"'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);

    return trim((string) $value);
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


function anagrafica_find_province_code(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $normalizedValue = anagrafica_normalize_lookup_value($value);
    foreach (anagrafica_province_italiane() as $code => $label) {
        if ($normalizedValue === anagrafica_normalize_lookup_value($code) || $normalizedValue === anagrafica_normalize_lookup_value($label)) {
            return $code;
        }
    }

    return null;
}

function anagrafica_comune_labels_by_province(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $grouped = [];
    foreach (anagrafica_comune_rows() as $row) {
        $province = (string) ($row['province'] ?? '');
        if ($province === '') {
            continue;
        }
        $grouped[$province][] = (string) ($row['label'] ?? '');
    }

    foreach ($grouped as $province => $labels) {
        $labels = array_values(array_unique(array_filter($labels)));
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        $grouped[$province] = $labels;
    }

    return $cache = $grouped;
}

function anagrafica_default_italy_state_code(): string
{
    return '100000100';
}

function anagrafica_default_state_label(): string
{
    return 'ITALIA';
}

function anagrafica_eu_citizenships(): array
{
    return [
        'AT' => 'AUSTRIA', 'BE' => 'BELGIO', 'BG' => 'BULGARIA', 'HR' => 'CROAZIA', 'CY' => 'CIPRO', 'CZ' => 'CECHIA', 'DK' => 'DANIMARCA',
        'EE' => 'ESTONIA', 'FI' => 'FINLANDIA', 'FR' => 'FRANCIA', 'DE' => 'GERMANIA', 'GR' => 'GRECIA', 'HU' => 'UNGHERIA', 'IE' => 'IRLANDA',
        'IT' => 'ITALIA', 'LV' => 'LETTONIA', 'LT' => 'LITUANIA', 'LU' => 'LUSSEMBURGO', 'MT' => 'MALTA', 'NL' => 'PAESI BASSI',
        'PL' => 'POLONIA', 'PT' => 'PORTOGALLO', 'RO' => 'ROMANIA', 'SK' => 'SLOVACCHIA', 'SI' => 'SLOVENIA', 'ES' => 'SPAGNA', 'SE' => 'SVEZIA',
    ];
}

function anagrafica_country_rows(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }

    $rows = [];
    foreach (anagrafica_load_csv_records('stati.csv') as $row) {
        $code = trim((string) ($row['Codice'] ?? ''));
        $description = trim((string) ($row['Descrizione'] ?? ''));
        $dataFineVal = trim((string) ($row['DataFineVal'] ?? ''));
        if ($code === '' || $description === '' || $dataFineVal !== '') {
            continue;
        }
        $rows[] = [
            'code' => $code,
            'description' => $description,
            'normalized_description' => anagrafica_normalize_lookup_value($description),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp($a['description'], $b['description']);
    });

    return $rows;
}

function anagrafica_state_options(): array
{
    $options = [];
    foreach (anagrafica_country_rows() as $row) {
        $options[$row['code']] = $row['description'];
    }
    return $options;
}

function anagrafica_find_state_by_value(?string $value): ?array
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $normalized = anagrafica_normalize_lookup_value($value);
    foreach (anagrafica_country_rows() as $row) {
        if ($value === $row['code'] || $normalized === $row['normalized_description']) {
            return $row;
        }
    }

    return null;
}

function anagrafica_document_rows(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }

    $rows = [];
    foreach (anagrafica_load_csv_records('documenti.csv') as $row) {
        $code = trim((string) ($row['Codice'] ?? ''));
        $description = trim((string) ($row['Descrizione'] ?? ''));
        if ($code === '' || $description === '') {
            continue;
        }
        $rows[] = [
            'code' => $code,
            'description' => $description,
            'normalized_description' => anagrafica_normalize_lookup_value($description),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp($a['description'], $b['description']);
    });

    return $rows;
}

function anagrafica_document_types(): array
{
    $options = [];
    foreach (anagrafica_document_rows() as $row) {
        $options[$row['code']] = $row['description'];
    }
    return $options;
}

function anagrafica_find_document_by_value(?string $value): ?array
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $normalized = anagrafica_normalize_lookup_value($value);
    foreach (anagrafica_document_rows() as $row) {
        if ($value === $row['code'] || $normalized === $row['normalized_description']) {
            return $row;
        }
    }

    $aliases = [
        'CARTA IDENTITA' => 'IDENT',
        'CARTA D IDENTITA' => 'IDENT',
        'CARTA IDENTITA ELETTRONICA' => 'IDELE',
        'PASSAPORTO' => 'PASOR',
        'PASSAPORTO ORDINARIO' => 'PASOR',
        'carta_identita' => 'IDENT',
        'passaporto' => 'PASOR',
    ];

    if (isset($aliases[$value])) {
        $value = $aliases[$value];
    } elseif (isset($aliases[$normalized])) {
        $value = $aliases[$normalized];
    }

    foreach (anagrafica_document_rows() as $row) {
        if ($value === $row['code']) {
            return $row;
        }
    }

    return null;
}

function anagrafica_tipo_alloggiato_rows(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }

    $rows = [];
    foreach (anagrafica_load_csv_records('tipo_alloggiato.csv') as $row) {
        $code = trim((string) ($row['Codice'] ?? ''));
        $description = trim((string) ($row['Descrizione'] ?? ''));
        if ($code === '' || $description === '') {
            continue;
        }
        $rows[] = [
            'code' => $code,
            'description' => $description,
        ];
    }

    return $rows;
}

function anagrafica_tipo_alloggiato_options(): array
{
    $options = [];
    foreach (anagrafica_tipo_alloggiato_rows() as $row) {
        $options[$row['code']] = $row['description'];
    }
    return $options;
}

function anagrafica_record_type_options(): array
{
    return [
        'single' => 'Ospite singolo',
        'family' => 'Famiglia',
        'group' => 'Gruppo',
    ];
}

function anagrafica_document_issue_place_options(): array
{
    static $options = null;
    if ($options !== null) {
        return $options;
    }

    $options = array_values(array_unique(array_merge(
        array_values(anagrafica_state_options()),
        anagrafica_place_option_labels()
    )));
    sort($options, SORT_NATURAL | SORT_FLAG_CASE);

    return $options;
}

function anagrafica_resolve_document_issue_place_value(?string $value): ?array
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $comune = anagrafica_find_comune_by_value($value, '');
    if ($comune) {
        return [
            'code' => (string) ($comune['code'] ?? ''),
            'label' => (string) ($comune['label'] ?? $value),
            'type' => 'comune',
        ];
    }

    $state = anagrafica_find_state_by_value($value);
    if ($state) {
        return [
            'code' => (string) ($state['code'] ?? ''),
            'label' => (string) ($state['description'] ?? $value),
            'type' => 'state',
        ];
    }

    return null;
}

function anagrafica_tipo_alloggiato_code_for_record_type(string $recordType, bool $isLeader): string
{
    if ($recordType === 'family') {
        return $isLeader ? '17' : '19';
    }
    if ($recordType === 'group') {
        return $isLeader ? '18' : '20';
    }
    return '16';
}

function anagrafica_titoli_studio(): array
{
    return [
        'Licenza elementare',
        'Diploma',
        'Laurea',
        'Altro titolo',
        'Non specificato',
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

function anagrafica_comune_rows(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }

    $rows = [];
    foreach (anagrafica_load_csv_records('comuni.csv') as $row) {
        $code = trim((string) ($row['Codice'] ?? ''));
        $description = trim((string) ($row['Descrizione'] ?? ''));
        $province = trim((string) ($row['Provincia'] ?? ''));
        if ($code === '' || $description === '') {
            continue;
        }
        $label = $description . ($province !== '' ? ' (' . $province . ')' : '');
        $rows[] = [
            'code' => $code,
            'description' => $description,
            'province' => $province,
            'label' => $label,
            'data_fine' => trim((string) ($row['DataFineVal'] ?? '')),
            'normalized_description' => anagrafica_normalize_lookup_value($description),
            'normalized_label' => anagrafica_normalize_lookup_value($label),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp($a['label'], $b['label']);
    });

    return $rows;
}

function anagrafica_citta_italiane_principali(): array
{
    return anagrafica_comune_option_labels();
}

function anagrafica_comune_option_labels(): array
{
    static $labels = null;
    if ($labels !== null) {
        return $labels;
    }

    $labels = [];
    foreach (anagrafica_comune_rows() as $row) {
        $labels[] = $row['label'];
    }

    return array_values(array_unique($labels));
}

function anagrafica_find_comune_by_value(?string $value, ?string $province = null): ?array
{
    $value = trim((string) $value);
    $province = trim((string) $province);
    if ($value === '') {
        return null;
    }

    foreach (anagrafica_comune_rows() as $row) {
        if ($value === $row['code']) {
            return $row;
        }
    }

    $normalizedValue = anagrafica_normalize_lookup_value($value);
    $normalizedProvince = anagrafica_normalize_lookup_value($province);
    $matches = [];

    foreach (anagrafica_comune_rows() as $row) {
        if ($normalizedValue === $row['normalized_label']) {
            $matches[] = $row;
            continue;
        }

        if ($normalizedValue === $row['normalized_description']) {
            if ($normalizedProvince === '' || $normalizedProvince === anagrafica_normalize_lookup_value($row['province'])) {
                $matches[] = $row;
            }
        }
    }

    if (!$matches) {
        return null;
    }

    if (count($matches) === 1) {
        return $matches[0];
    }

    foreach ($matches as $match) {
        if ($match['data_fine'] === '') {
            return $match;
        }
    }

    return null;
}

function anagrafica_nuts_rows(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }

    $rows = [];
    foreach (anagrafica_load_csv_records('nuts.csv') as $row) {
        $code = trim((string) ($row['codice'] ?? ''));
        $description = trim((string) ($row['descrizione'] ?? ''));
        $level = trim((string) ($row['nuts_level'] ?? ''));
        $countrySigla = trim((string) ($row['sigla_nazione'] ?? ''));
        if ($code === '' || $description === '') {
            continue;
        }
        if ($level === '0') {
            continue;
        }
        $label = $description . ' [' . $code . ']';
        $rows[] = [
            'code' => $code,
            'description' => $description,
            'level' => $level,
            'country_sigla' => $countrySigla,
            'label' => $label,
            'normalized_description' => anagrafica_normalize_lookup_value($description),
            'normalized_label' => anagrafica_normalize_lookup_value($label),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp($a['label'], $b['label']);
    });

    return $rows;
}

function anagrafica_nuts_option_labels(): array
{
    static $labels = null;
    if ($labels !== null) {
        return $labels;
    }

    $labels = [];
    foreach (anagrafica_nuts_rows() as $row) {
        $labels[] = $row['label'];
    }

    return array_values(array_unique($labels));
}

function anagrafica_find_nuts_by_value(?string $value): ?array
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $normalizedValue = anagrafica_normalize_lookup_value($value);
    foreach (anagrafica_nuts_rows() as $row) {
        if ($value === $row['code'] || $normalizedValue === $row['normalized_label'] || $normalizedValue === $row['normalized_description']) {
            return $row;
        }
    }

    return null;
}

function anagrafica_place_option_labels(): array
{
    static $labels = null;
    if ($labels !== null) {
        return $labels;
    }

    $labels = array_values(array_unique(array_merge(
        anagrafica_comune_option_labels(),
        anagrafica_nuts_option_labels()
    )));
    sort($labels);

    return $labels;
}

function anagrafica_resolve_place_value(string $stateCode, ?string $value, ?string $province = null): ?array
{
    $value = trim((string) $value);
    $province = trim((string) $province);
    if ($value === '') {
        return null;
    }

    if ($stateCode === anagrafica_default_italy_state_code()) {
        $comune = anagrafica_find_comune_by_value($value, $province);
        if (!$comune) {
            return null;
        }

        return [
            'code' => $comune['code'],
            'label' => $comune['label'],
            'province' => $comune['province'],
            'type' => 'comune',
        ];
    }

    if (anagrafica_is_european_state_code($stateCode)) {
        $nuts = anagrafica_find_nuts_by_value($value);
        if ($nuts) {
            return [
                'code' => $nuts['code'],
                'label' => $nuts['label'],
                'province' => '',
                'type' => 'nuts',
            ];
        }
    }

    return [
        'code' => $value,
        'label' => $value,
        'province' => '',
        'type' => 'text',
    ];
}

function anagrafica_is_european_state_code(string $stateCode): bool
{
    static $europeanCodes = null;
    if ($europeanCodes !== null) {
        return in_array($stateCode, $europeanCodes, true);
    }

    $europeanCodes = [];
    foreach (anagrafica_eu_citizenships() as $label) {
        $state = anagrafica_find_state_by_value($label);
        if ($state) {
            $europeanCodes[] = $state['code'];
        }
    }

    return in_array($stateCode, $europeanCodes, true);
}
