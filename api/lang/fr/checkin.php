<?php

/**
 * Le scanner de porte.
 *
 * Ce fichier est la source ; l’application en embarque une copie générée (ADR-0005) : le scanner
 * doit fonctionner sans réseau, et un catalogue qu’il faut aller chercher est un catalogue absent
 * au moment précis où il sert.
 */
return [
    'title' => 'Contrôle Seatmap',
    /* Shown by the page itself, before the app has loaded a single frame. */
    'boot' => 'Chargement du scanner…',

    'language' => 'Langue',
    'cancel' => 'Annuler',
    'defaultDeviceName' => 'Scanner de porte',
    'thisDevice' => 'Cet appareil',

    'listSeparator' => ' · ',

    'pair' => [
        'title' => 'Associer ce scanner',
        'subtitle' => 'Prenez un code d’association dans le panneau de l’organisateur. Il sert une fois et expire.',
        'address' => 'Adresse Seatmap',
        'code' => 'Code d’association',
        'deviceName' => 'Nommer cet appareil',
        'deviceNameHint' => 'Visible dans le panneau, pour que l’équipe sache quelle porte est laquelle.',
        'submit' => 'Associer',
        'incomplete' => 'Remplissez l’adresse et le code d’association.',
        'unreachable' => 'Impossible de joindre cette adresse. Vérifiez le Wi-Fi de la salle et l’adresse ci-dessus.',
    ],

    'events' => [
        'title' => 'Choisir un événement',
        'refresh' => 'Actualiser',
        'queuedOne' => '1 scan reste à envoyer',
        'queuedMany' => ':count scans restent à envoyer',
        'unpair' => 'Dissocier cet appareil',
        'emptyTitle' => 'Cet appareil n’a encore le droit de rien scanner.',
        'emptyBody' => 'Donnez-lui un événement dans le panneau de l’organisateur, puis actualisez.',
    ],

    'unpair' => [
        'title' => 'Dissocier cet appareil ?',
        'withQueue' => 'Il reste :count scans à envoyer. Ils sont conservés, mais cet appareil devra être ré-associé avant de pouvoir les envoyer.',
        'clean' => 'Il vous faudra un nouveau code d’association pour réutiliser ce scanner.',
        'confirm' => 'Dissocier',
    ],

    'scan' => [
        'typeTitle' => 'Taper le code',
        'ticketCode' => 'Code du billet',
        'check' => 'Vérifier',
        'typeCode' => 'Taper un code',
        'torch' => 'Lampe',
        'switchCamera' => 'Changer de caméra',
        'countedIn' => ':count entrés',
        'stillToCome' => ':count encore attendus',
        'noCameraTitle' => 'Pas de caméra ici',
        'noCameraBody' => 'Autorisez la caméra dans le navigateur, ou tapez les codes à la main.',
        'next' => 'Suivant',
        'firstScanned' => 'Premier scan à :time',
        'firstScannedBy' => 'Premier scan à :time · :by',
        'row' => 'rang :row',
        'seat' => 'place :seat',
    ],

    'result' => [
        'valid' => ['headline' => 'Entrez', 'detail' => 'Admis.'],
        'alreadyUsed' => ['headline' => 'Déjà utilisé', 'detail' => 'Quelqu’un est déjà entré avec ce billet.'],
        'cancelled' => ['headline' => 'Annulé', 'detail' => 'Cette réservation a été annulée.'],
        'refunded' => ['headline' => 'Remboursé', 'detail' => 'Cette réservation a été remboursée.'],
        'wrongEvent' => ['headline' => 'Mauvais événement', 'detail' => 'Ce billet est pour une autre représentation.'],
        'invalid' => ['headline' => 'Pas un billet', 'detail' => 'Ce code n’est pas des nôtres.'],
        'notOnList' => ['headline' => 'Pas sur la liste', 'detail' => 'Absent de la copie que ce scanner a sur lui.'],
        'queued' => ['headline' => 'Gardé hors ligne', 'detail' => 'Pas de connexion. Il partira dès votre retour en ligne.'],
    ],

    'door' => [
        'title' => 'Liste d’entrée',
        'taking' => 'Copie en cours…',
        'held' => ':count billets · prise :time',
        'none' => 'Aucune liste d’entrée sur cet appareil',
        'noneHint' => 'Prenez-en une tant que vous avez du réseau. Sans elle, ce scanner ne peut rien vérifier quand la connexion tombe.',
        'take' => 'Prendre une copie',
        'retake' => 'Prendre une copie fraîche',
        'failed' => 'Copie impossible.',
        'checkedAgainst' => 'Vérifié sur la copie prise :time.',
        'sinceTaken' => 'Qui a acheté depuis n’y figure pas — faites entrer, ce sera vérifié au retour du réseau.',
        'conflictsOne' => '1 scan a été tranché autrement une fois envoyé',
        'conflictsMany' => ':count scans ont été tranchés autrement une fois envoyés',
        'conflictsTitle' => 'Ce qu’a dit le système',
        'conflictsHint' => 'L’entrée les a laissés passer. Le système n’était pas d’accord.',
        'conflictLine' => ':who — l’entrée a dit :said, le système a dit :was',
        'dismiss' => 'Compris',
    ],

    'status' => [
        'online' => 'En ligne',
        'offline' => 'Hors ligne — les scans sont conservés',
        'waiting' => ':count en attente',
    ],

    'failure' => [
        'offline' => 'Pas de connexion.',
        'generic' => 'Cela n’a pas marché.',
    ],

    'months' => [
        1 => 'janv.',
        2 => 'févr.',
        3 => 'mars',
        4 => 'avr.',
        5 => 'mai',
        6 => 'juin',
        7 => 'juil.',
        8 => 'août',
        9 => 'sept.',
        10 => 'oct.',
        11 => 'nov.',
        12 => 'déc.',
    ],
    'dateTime' => ':day :month :year · :time',
];
