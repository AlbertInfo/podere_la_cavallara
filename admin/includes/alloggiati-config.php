<?php

declare(strict_types=1);

return [
    // Compila questi valori quando attiveremo il web service reale.
    'endpoint' => 'https://alloggiatiweb.poliziadistato.it/service/service.asmx',
    'wsdl' => 'https://alloggiatiweb.poliziadistato.it/service/service.asmx?wsdl',
    'utente' => '',
    'password' => '',
    'wskey' => '',

    // Fase attuale: il workflow di invio resta locale, ma il tracciato e le buste SOAP sono già predisposti.
    'simulate_send_without_ws' => true,
];
