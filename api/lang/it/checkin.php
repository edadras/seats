<?php

/**
 * Lo scanner alla porta.
 *
 * Questo file è la fonte; l’app porta con sé una copia generata (ADR-0005): lo scanner deve
 * funzionare senza rete, e un catalogo da scaricare è un catalogo che manca proprio nel momento in
 * cui serve.
 */
return [
    'title' => 'Ingresso Seatmap',
    /* Shown by the page itself, before the app has loaded a single frame. */
    'boot' => 'Caricamento dello scanner…',

    'language' => 'Lingua',
    'cancel' => 'Annulla',
    'defaultDeviceName' => 'Scanner alla porta',
    'thisDevice' => 'Questo dispositivo',

    'listSeparator' => ' · ',

    'pair' => [
        'title' => 'Abbina questo scanner',
        'subtitle' => 'Prendi un codice di abbinamento dal pannello dell’organizzatore. Vale una volta e scade.',
        'address' => 'Indirizzo Seatmap',
        'code' => 'Codice di abbinamento',
        'deviceName' => 'Dai un nome a questo dispositivo',
        'deviceNameHint' => 'Si vede nel pannello, così lo staff sa quale porta è quale.',
        'submit' => 'Abbina',
        'incomplete' => 'Compila l’indirizzo e il codice di abbinamento.',
        'unreachable' => 'Non è stato possibile raggiungere quell’indirizzo. Controlla il Wi-Fi della sala e l’indirizzo qui sopra.',
    ],

    'events' => [
        'title' => 'Scegli un evento',
        'refresh' => 'Aggiorna',
        'queuedOne' => '1 scansione ancora da inviare',
        'queuedMany' => ':count scansioni ancora da inviare',
        'unpair' => 'Disabbina questo dispositivo',
        'emptyTitle' => 'Questo dispositivo non può ancora scansionare nulla.',
        'emptyBody' => 'Assegnagli un evento nel pannello dell’organizzatore, poi aggiorna.',
    ],

    'unpair' => [
        'title' => 'Disabbinare questo dispositivo?',
        'withQueue' => 'Ci sono ancora :count scansioni in attesa di invio. Restano salvate, ma questo dispositivo dovrà essere riabbinato prima di poterle inviare.',
        'clean' => 'Ti servirà un nuovo codice di abbinamento per riusare questo scanner.',
        'confirm' => 'Disabbina',
    ],

    'scan' => [
        'typeTitle' => 'Digita il codice',
        'ticketCode' => 'Codice del biglietto',
        'check' => 'Controlla',
        'typeCode' => 'Digita un codice',
        'torch' => 'Torcia',
        'switchCamera' => 'Cambia fotocamera',
        'countedIn' => ':count entrati',
        'stillToCome' => ':count ancora attesi',
        'noCameraTitle' => 'Qui non c’è fotocamera',
        'noCameraBody' => 'Consenti la fotocamera nel browser, oppure digita i codici a mano.',
        'next' => 'Avanti',
        'firstScanned' => 'Prima scansione alle :time',
        'firstScannedBy' => 'Prima scansione alle :time · :by',
        'row' => 'fila :row',
        'seat' => 'posto :seat',
    ],

    'result' => [
        'valid' => ['headline' => 'Prego, entri', 'detail' => 'Ammesso.'],
        'alreadyUsed' => ['headline' => 'Già usato', 'detail' => 'Con questo biglietto è già entrato qualcuno.'],
        'cancelled' => ['headline' => 'Annullato', 'detail' => 'Questa prenotazione è stata annullata.'],
        'refunded' => ['headline' => 'Rimborsato', 'detail' => 'Questa prenotazione è stata rimborsata.'],
        'wrongEvent' => ['headline' => 'Evento sbagliato', 'detail' => 'Questo biglietto è per un’altra recita.'],
        'invalid' => ['headline' => 'Non è un biglietto', 'detail' => 'Questo codice non è dei nostri.'],
        'notOnList' => ['headline' => 'Non in elenco', 'detail' => 'Non è nella copia che questo scanner ha con sé.'],
        'queued' => ['headline' => 'Salvato offline', 'detail' => 'Nessuna connessione. Verrà inviato appena tornerai online.'],
    ],

    'door' => [
        'title' => 'Lista d’ingresso',
        'taking' => 'Copia in corso…',
        'held' => ':count biglietti · presa :time',
        'none' => 'Nessuna lista d’ingresso su questo dispositivo',
        'noneHint' => 'Prendine una finché hai segnale. Senza, questo scanner non può controllare nulla quando cade la connessione.',
        'take' => 'Prendi una copia',
        'retake' => 'Prendi una copia nuova',
        'failed' => 'Copia non riuscita.',
        'checkedAgainst' => 'Controllato sulla copia presa :time.',
        'sinceTaken' => 'Chi ha comprato dopo non c’è — fallo entrare, sarà controllato al ritorno del segnale.',
        'conflictsOne' => '1 scansione è stata decisa diversamente una volta inviata',
        'conflictsMany' => ':count scansioni sono state decise diversamente una volta inviate',
        'conflictsTitle' => 'Cosa ha detto il sistema',
        'conflictsHint' => 'L’ingresso li ha fatti entrare. Il sistema non era d’accordo.',
        'conflictLine' => ':who — l’ingresso ha detto :said, il sistema ha detto :was',
        'dismiss' => 'Capito',
    ],

    'status' => [
        'online' => 'In linea',
        'offline' => 'Offline — le scansioni vengono salvate',
        'waiting' => ':count in attesa',
    ],

    'failure' => [
        'offline' => 'Nessuna connessione.',
        'generic' => 'Non ha funzionato.',
    ],

    'months' => [
        1 => 'gen',
        2 => 'feb',
        3 => 'mar',
        4 => 'apr',
        5 => 'mag',
        6 => 'giu',
        7 => 'lug',
        8 => 'ago',
        9 => 'set',
        10 => 'ott',
        11 => 'nov',
        12 => 'dic',
    ],
    'dateTime' => ':day :month :year · :time',
];
