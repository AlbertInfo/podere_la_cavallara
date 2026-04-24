<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'endpoint' => 'https://eu-documentai.googleapis.com/v1/projects/104713903040/locations/eu/processors/196275ef2624638a:process',
    'credentials_path' => '/home/u881781553/domains/poderelacavallara.it/config/scandocuments-494116-c9617789be44.json',
    'bearer_token' => null,
    'python_binary' => '/home/u881781553/domains/poderelacavallara.it/public_html/venv/bin/python3',
    'timeout_seconds' => 90,
    'max_file_size_bytes' => 10 * 1024 * 1024,
    'accepted_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ],
    'store_raw_responses' => true,
];
