<?php

declare(strict_types=1);

function ross1000_property_config(): array
{
    return [
        // Dati da chiedere al cliente / configurare una volta sola.
        'codice_struttura' => 'T00148', // Es. A00927P
        'prodotto' => ADMIN_APP_NAME,
        'camere_disponibili' => 6,
        'letti_disponibili' => 32,

        // Se la struttura non ha chiusure particolari lascia true e gli array vuoti.
        'aperto_tutto_anno' => true,
        'giorni_chiusura' => [], // ['2026-12-25']
        'periodi_chiusura' => [
            // ['from' => '2026-11-03', 'to' => '2026-11-20'],
        ],

        // Da usare in una fase successiva se vorrai inviare via web service.
        'wsdl' => 'https://lazioturismo.ross1000.it/ws/checkinV2?wsdl',
        'username' => '',
        'password' => '',
        'simulate_send_without_ws' => false,
    ];
}

function ross1000_property_config_ready(array $config): bool
{
    return trim((string) ($config['codice_struttura'] ?? '')) !== ''
        && (int) ($config['camere_disponibili'] ?? 0) > 0
        && (int) ($config['letti_disponibili'] ?? 0) > 0;
}
