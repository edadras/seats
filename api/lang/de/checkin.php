<?php

/**
 * Der Türscanner.
 *
 * Diese Datei ist die Quelle; die App trägt eine erzeugte Kopie davon (ADR-0005): Der Scanner muss
 * ohne Netz arbeiten, und ein Katalog, den er abrufen muss, ist genau dann nicht da, wenn er
 * gebraucht wird.
 */
return [
    'title' => 'Seatmap-Einlass',
    /* Shown by the page itself, before the app has loaded a single frame. */
    'boot' => 'Scanner wird geladen …',

    'language' => 'Sprache',
    'cancel' => 'Abbrechen',
    'defaultDeviceName' => 'Türscanner',
    'thisDevice' => 'Dieses Gerät',

    'listSeparator' => ' · ',

    'pair' => [
        'title' => 'Diesen Scanner koppeln',
        'subtitle' => 'Holen Sie einen Kopplungscode aus dem Veranstalter-Panel. Er gilt einmal und läuft ab.',
        'address' => 'Seatmap-Adresse',
        'code' => 'Kopplungscode',
        'deviceName' => 'Diesem Gerät einen Namen geben',
        'deviceNameHint' => 'Im Panel sichtbar, damit das Team weiß, welche Tür welche ist.',
        'submit' => 'Koppeln',
        'incomplete' => 'Tragen Sie die Adresse und den Kopplungscode ein.',
        'unreachable' => 'Diese Adresse war nicht erreichbar. Prüfen Sie das WLAN im Haus und die Adresse oben.',
    ],

    'events' => [
        'title' => 'Veranstaltung wählen',
        'refresh' => 'Aktualisieren',
        'queuedOne' => '1 Scan noch zu senden',
        'queuedMany' => ':count Scans noch zu senden',
        'unpair' => 'Gerät entkoppeln',
        'emptyTitle' => 'Dieses Gerät darf noch nichts scannen.',
        'emptyBody' => 'Geben Sie ihm im Veranstalter-Panel eine Veranstaltung und aktualisieren Sie dann.',
    ],

    'unpair' => [
        'title' => 'Dieses Gerät entkoppeln?',
        'withQueue' => 'Es warten noch :count Scans auf den Versand. Sie bleiben erhalten, aber dieses Gerät muss erneut gekoppelt werden, bevor es sie senden kann.',
        'clean' => 'Sie brauchen einen neuen Kopplungscode, um diesen Scanner wieder zu benutzen.',
        'confirm' => 'Entkoppeln',
    ],

    'scan' => [
        'typeTitle' => 'Code eintippen',
        'ticketCode' => 'Ticketcode',
        'check' => 'Prüfen',
        'typeCode' => 'Code eintippen',
        'torch' => 'Licht',
        'switchCamera' => 'Kamera wechseln',
        'countedIn' => ':count drin',
        'stillToCome' => ':count fehlen noch',
        'noCameraTitle' => 'Keine Kamera hier',
        'noCameraBody' => 'Erlauben Sie den Kamerazugriff im Browser, oder tippen Sie Codes von Hand ein.',
        'next' => 'Weiter',
        'firstScanned' => 'Zuerst gescannt um :time',
        'firstScannedBy' => 'Zuerst gescannt um :time · :by',
        'row' => 'Reihe :row',
        'seat' => 'Platz :seat',
    ],

    'result' => [
        'valid' => ['headline' => 'Herein', 'detail' => 'Eingelassen.'],
        'alreadyUsed' => ['headline' => 'Schon benutzt', 'detail' => 'Mit diesem Ticket ist bereits jemand hereingekommen.'],
        'cancelled' => ['headline' => 'Storniert', 'detail' => 'Diese Buchung wurde storniert.'],
        'refunded' => ['headline' => 'Erstattet', 'detail' => 'Diese Buchung wurde erstattet.'],
        'wrongEvent' => ['headline' => 'Falscher Termin', 'detail' => 'Dieses Ticket gilt für eine andere Vorstellung.'],
        'invalid' => ['headline' => 'Kein Ticket', 'detail' => 'Dieser Code ist keiner von uns.'],
        'queued' => ['headline' => 'Offline gespeichert', 'detail' => 'Keine Verbindung. Wird gesendet, sobald Sie wieder online sind.'],
    ],

    'status' => [
        'online' => 'Online',
        'offline' => 'Offline — Scans werden gespeichert',
        'waiting' => ':count wartend',
    ],

    'failure' => [
        'offline' => 'Keine Verbindung.',
        'generic' => 'Das hat nicht geklappt.',
    ],

    'months' => [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mär',
        4 => 'Apr',
        5 => 'Mai',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Aug',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Dez',
    ],
    'dateTime' => ':day. :month :year · :time',
];
