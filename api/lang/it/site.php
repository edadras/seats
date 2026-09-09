<?php

/**
 * Testi che un sito evento ospitato mostra a chi acquista.
 *
 * Il vocabolario del selettore di posti sta sotto `picker` e gli viene consegnato come un unico
 * oggetto: il selettore è condiviso con il plugin WordPress e prende ogni testo da ciò che lo avvia.
 *
 * I segnaposto sotto `picker` sono %s / %d / %1$s e non :name — il selettore li sostituisce da sé,
 * nel browser. Fuori da `picker` si usa :name.
 */
return [
    'picker' => [
        'selectSeats' => 'Scegli i tuoi posti',
        'available' => 'Libero',
        'unavailable' => 'Occupato',
        'selected' => 'Scelto',
        'yourSelection' => 'La tua scelta',
        'noneSelected' => 'Nessun posto scelto per ora.',
        'total' => 'Totale',
        'addToCart' => 'Prenota questi posti',
        'working' => 'Prenotazione…',
        'seatTaken' => 'Spiacenti, uno di quei posti è stato appena preso ed è stato tolto dalla tua scelta.',
        'genericError' => 'Qualcosa è andato storto. Riprova.',
        'maxSeats' => 'Puoi scegliere fino a %d posti.',
        'seatLabel' => '%1$s, fila %2$s, posto %3$s — %4$s',
        'seatUnavailable' => '%1$s, fila %2$s, posto %3$s — occupato',
        'zoomIn' => 'Ingrandisci',
        'zoomOut' => 'Riduci',
        'resetView' => 'Reimposta la vista',
        'held' => 'Posti tenuti fino alle %s',
        'expired' => 'La tua prenotazione è scaduta. Scegli di nuovo i posti.',
        'stage' => 'Palco',
        'standingAreas' => 'In piedi e tavoli',
        'placesLeft' => 'ne restano %d',
        'soldOut' => 'Esaurito',
        'addOne' => 'Aggiungi un posto in %s',
        'removeOne' => 'Togli un posto da %s',
        'areaFull' => 'Quest’area si è riempita mentre sceglievi. Scegli un numero di posti diverso.',
        'floors' => 'Livello',
        'chooseSection' => 'Scegli un’area',
        'backToPlan' => 'Torna alla pianta della sala',
        'sectionFrom' => 'Da %s',
        'sectionSeatsLeft' => 'restano %d posti',
        'sectionSoldOut' => 'Esaurito',
        'openSection' => 'Mostra i posti in %s',
        'inSection' => 'In %s',
    ],


    'blocks' => [
        'heading' => 'Titolo',
        'richText' => 'Testo',
        'image' => 'Immagine',
        'buttons' => 'Pulsanti',
        'eventList' => 'In programma',
        'eventDetail' => 'Evento e scelta dei posti',
        'faq' => 'Domande',
        'venueMap' => 'Come raggiungerci',
        'divider' => 'Divisore',
        'html' => 'HTML personalizzato',
    ],

    'seed' => [
        'home' => 'Home',
        'event' => 'Evento',
        'visiting' => 'Come visitarci',
        'welcome' => 'Benvenuti. I biglietti per tutto ciò che arriva sono qui sotto.',
        'whatsOn' => 'In programma',
        'findingUs' => 'Come raggiungerci',
        'beforeYouCome' => 'Prima di venire',
        'doorsQuestion' => 'A che ora aprono le porte?',
        'doorsAnswer' => 'Di solito mezz’ora prima dell’inizio.',
        'refundQuestion' => 'Posso avere un rimborso?',
        'refundAnswer' => 'Scrivete qui la vostra politica per il pubblico.',
        'header' => 'Intestazione',
        'footer' => 'Piè di pagina',
    ],

    // --- Struttura del sito -----------------------------------------------------------------
    'skipToContent' => 'Vai al contenuto',
    'mainNav' => 'Principale',
    'footerNav' => 'Piè di pagina',
    'language' => 'Lingua',

    // --- Programmazione e pagina evento -----------------------------------------------------
    'whatsOn' => 'In programma',
    'noEvents' => 'Al momento non c’è nulla in vendita.',
    'bookNow' => 'Prenota',
    'from' => 'Da :price',
    'soldOut' => 'Esaurito',
    'doorsOpen' => 'Apertura porte alle :time',
    'eventDate' => ':date alle :time',

    // --- Pagamento --------------------------------------------------------------------------
    'checkout' => 'Pagamento',
    'whoFor' => 'A chi sono intestati i biglietti?',
    'name' => 'Nome',
    'email' => 'Email',
    'emailHint' => 'I tuoi biglietti vengono inviati a questo indirizzo.',
    'phone' => 'Telefono',
    'optional' => '(facoltativo)',
    'howToPay' => 'Come vuoi pagare?',
    'confirmBooking' => 'Conferma la prenotazione',
    'yourSeats' => 'I tuoi posti',
    'total' => 'Totale',
    'heldUntil' => 'Tenuti fino alle :time.',
    'holdExpired' => 'La tua prenotazione è scaduta. Scegli di nuovo i posti.',

    // --- Conferma ---------------------------------------------------------------------------
    'orderConfirmed' => 'Ci sei',
    'orderReference' => 'Prenotazione :reference',
    'ticketsSentTo' => 'I tuoi biglietti stanno arrivando a :email.',
    'ticketsBelow' => 'Sono anche qui sotto — mostrane uno all’ingresso.',
    'payAtDoor' => 'Pagherai al botteghino quando arrivi.',
    'addToCalendar' => 'Aggiungi al calendario',
    'printTickets' => 'Stampa i biglietti',
    'seat' => 'Posto',
    'section' => 'Area',
    'row' => 'Fila',
    'admits' => 'Valido per una persona',

    // --- Come arrivare ----------------------------------------------------------------------
    'findingUs' => 'Come trovarci',
    'questions' => 'Domande',

    // --- Pagina di conferma e stati vuoti ---------------------------------------------------
    'bookedHeading' => 'Prenotato',
    'orderLine' => 'Prenotazione :reference · :event',
    'showCodeAtDoor' => 'Mostra un codice all’ingresso — uno per posto. Te li abbiamo mandati anche per email.',
    'codesByEmail' => 'I tuoi biglietti stanno arrivando per email. I codici compaiono qui una sola volta, quindi controlla la posta.',
    'bookingStatus' => 'Questa prenotazione è :status. Se ti sembra sbagliato, contatta il botteghino citando :reference.',
    'standing' => 'In piedi',
    'emptyPage' => 'In questa pagina non c’è ancora nulla.',
    'nothingOnSale' => 'Al momento non c’è nulla in vendita. Torna presto.',
    'book' => 'Prenota',
    'status' => [
        'pending' => 'in attesa di pagamento',
        'confirmed' => 'confermata',
        'cancelled' => 'annullata',
        'refunded' => 'rimborsata',
        'partially_refunded' => 'parzialmente rimborsata',
    ],
    'closed' => [
        'cancelled' => 'Questa rappresentazione è stata annullata.',
        'closed' => 'La vendita per questa rappresentazione è chiusa.',
        'notYet' => 'I biglietti per questa rappresentazione non sono ancora in vendita.',
    ],
];
