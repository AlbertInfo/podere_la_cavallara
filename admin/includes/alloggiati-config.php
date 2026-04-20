<?php

declare(strict_types=1);

return [
    // Compila questi valori quando attiveremo il web service reale.
    'endpoint' => 'https://alloggiatiweb.poliziadistato.it/service/service.asmx',
    'wsdl' => 'https://alloggiatiweb.poliziadistato.it/service/service.asmx?wsdl',
    'utente' => 'VT001723',
    'password' => 'KvtlLvgu',
    'wskey' => 'AGEJ9mNb+rh1qzNT9EYe84JRyvjV/jbxCq41rWENWEyftO9JYqWLDeKzKUaSRYrJHg==',

    // Imposta a false per usare il web service reale dopo aver compilato le credenziali.
    'simulate_send_without_ws' => false,
];
