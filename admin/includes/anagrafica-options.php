<?php

declare(strict_types=1);

function anagrafica_eu_citizenships(): array
{
    return [
        'AT' => 'Austria', 'BE' => 'Belgio', 'BG' => 'Bulgaria', 'HR' => 'Croazia', 'CY' => 'Cipro', 'CZ' => 'Cechia', 'DK' => 'Danimarca', 'EE' => 'Estonia', 'FI' => 'Finlandia', 'FR' => 'Francia', 'DE' => 'Germania', 'GR' => 'Grecia', 'HU' => 'Ungheria', 'IE' => 'Irlanda', 'IT' => 'Italia', 'LV' => 'Lettonia', 'LT' => 'Lituania', 'LU' => 'Lussemburgo', 'MT' => 'Malta', 'NL' => 'Paesi Bassi', 'PL' => 'Polonia', 'PT' => 'Portogallo', 'RO' => 'Romania', 'SK' => 'Slovacchia', 'SI' => 'Slovenia', 'ES' => 'Spagna', 'SE' => 'Svezia',
    ];
}

function anagrafica_document_types(): array
{
    return [
        'carta_identita' => "Carta d'identità",
        'passaporto' => 'Passaporto',
        'altro' => 'Altro',
    ];
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

function anagrafica_citta_italiane_principali(): array
{
    return [
        'Roma', 'Milano', 'Napoli', 'Torino', 'Palermo', 'Genova', 'Bologna', 'Firenze', 'Bari', 'Catania', 'Venezia', 'Verona', 'Messina', 'Padova', 'Trieste', 'Taranto', 'Brescia', 'Prato', 'Parma', 'Modena', 'Reggio Calabria', 'Reggio Emilia', 'Perugia', 'Livorno', 'Ravenna', 'Cagliari', 'Foggia', 'Rimini', 'Salerno', 'Ferrara', 'Sassari', 'Latina', 'Giugliano in Campania', 'Monza', 'Siracusa', 'Pescara', 'Bergamo', 'Forlì', 'Trento', 'Vicenza', 'Terni', 'Bolzano', 'Novara', 'Piacenza', 'Ancona', 'Andria', 'Arezzo', 'Udine', 'Cesena', 'Lecce', 'Pesaro', 'Barletta', 'Alessandria', 'La Spezia', 'Pistoia', 'Pisa', 'Catanzaro', 'Lucca', 'Brindisi', 'Treviso', 'Como', 'Marsala', 'Grosseto', 'Asti', 'Siena', 'Macerata', 'Viterbo', 'Frosinone', 'Rieti', 'Velletri', 'Tivoli', 'Fiumicino', 'Frascati', "L'Aquila",
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
    return ['Auto', 'Aereo', 'Aereo+Pullman', 'Aereo+Navetta/Taxi/Auto', 'Aereo+Treno', 'Treno', 'Pullman', 'Caravan/Autocaravan', 'Barca/Nave/Traghetto', 'Moto', 'Bicicletta', 'A piedi', 'Altro mezzo', 'Non specificato'];
}


function anagrafica_tipo_alloggiato_options(): array
{
    return [
        '16' => 'Ospite singolo',
        '18' => 'Capo gruppo',
        '20' => 'Membro gruppo',
    ];
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

function ross1000_property_config(): array
{
    return [
        'codice_struttura' => '',
        'prodotto' => 'Podere La Cavallara Admin',
        'camere_disponibili' => 0,
        'letti_disponibili' => 0,
    ];
}

function ross1000_property_config_ready(array $config): bool
{
    return trim((string) ($config['codice_struttura'] ?? '')) !== ''
        && (int) ($config['camere_disponibili'] ?? 0) > 0
        && (int) ($config['letti_disponibili'] ?? 0) > 0;
}
