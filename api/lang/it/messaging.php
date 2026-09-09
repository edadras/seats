<?php

/**
 * Messages, and the screen an organiser writes them on.
 *
 * `defaults` is the wording this platform sends when an organiser has written none — which is
 * every account on its first day. It is a real message in every language rather than a placeholder,
 * because the alternative to a good default is not a better message; it is a blank one.
 *
 * The braces are placeholders substituted at send time (App\Domain\Messaging\MessageRenderer), not
 * Laravel's `:name`: an organiser edits these strings in the panel, and one syntax is enough.
 */
return [
    'title' => 'Messaggi',
    'subtitle' => 'Cosa viene detto all’acquirente, su quali canali, nella sua lingua.',
    'kinds' => [
        'order_confirmed' => [
            'name' => 'Ordine confermato',
            'description' => 'Parte appena il pagamento va a buon fine. L’unico messaggio a cui un acquirente ha diritto.',
        ],
        'order_cancelled' => [
            'name' => 'Ordine annullato',
            'description' => 'Parte quando un ordine viene annullato o rimborsato.',
        ],
        'event_reminder' => [
            'name' => 'Promemoria',
            'description' => 'Il giorno prima, a chi ha un biglietto. Spento finché non lo accendi.',
        ],
        'waitlist_available' => [
            'name' => 'Si è liberato un posto',
            'description' => 'Inviato ai successivi in lista d’attesa quando tornano dei posti. È esattamente ciò che hanno chiesto, quindi non si può disattivare.',
        ],
    ],
    'channels' => [
        'email' => 'E-mail',
    ],
    'defaults' => [
        'system_notice' => [
            'subject' => 'Un messaggio da {account}: {title}',
            'body' => "{title}\n\n{body}\n\nQuesto è un avviso automatico dal vostro account Seatmap.",
        ],
        'order_confirmed' => [
            'subject' => 'I tuoi biglietti per {event}',
            'body' => "{buyer}, i tuoi biglietti sono prenotati.\n\n{event}\n{venue}\n{starts}\nPosti: {seats}\nTotale: {total}\n\nRiferimento {reference}.",
        ],
        'order_cancelled' => [
            'subject' => 'La prenotazione {reference} è annullata',
            'body' => "{buyer}, la tua prenotazione per {event} è stata annullata.\n\nRiferimento {reference}.",
        ],
        'event_reminder' => [
            'subject' => '{event} è domani',
            'body' => "{buyer}, {event} è domani.\n\n{venue}\n{starts}\nPosti: {seats}\n\nRiferimento {reference}.",
        ],
        'waitlist_available' => [
            'subject' => 'Si è liberato un posto per {event}',
            'body' => "{buyer}, è tornato un posto per {event}.\n\n{venue}\n{starts}\n\nNe volevi {quantity}. La vendita è aperta per le prossime {hours} ore, chi prima arriva:\n{link}\n\nPer uscire da questa lista: {leave}",
        ],
    ],
    'logKinds' => [
        'announcement' => 'Annuncio',
        'system_notice' => 'Avviso di sistema',
    ],
    'template' => 'Testo',
    'subject' => 'Oggetto',
    'body' => 'Messaggio',
    'locale' => 'Lingua',
    'usingDefault' => 'Vale il nostro testo. Scrivi il tuo per sostituirlo.',
    'placeholders' => 'Disponibili: :list',
    'save' => 'Salva il testo',
    'saved' => 'Testo salvato.',
    'reset' => 'Torna al nostro testo',
    'resetDone' => 'Tornato al nostro testo.',
    'channelsOn' => 'Inviato su',
    'channelHint' => 'Chi non ha un recapito per un canale semplicemente non riceve quello.',
    'required' => 'Sempre inviato',
    'log' => 'Registro invii',
    'noLog' => 'Non è stato ancora inviato nulla',
    'noLogHint' => 'Le conferme compaiono qui al primo acquisto.',
    'status' => [
        'queued' => 'In coda',
        'sent' => 'Inviato',
        'refused' => 'Rifiutato',
        'unavailable' => 'Irraggiungibile',
    ],
    'recipient' => 'A',
    'when' => 'Quando',
    'attempts' => 'Tentativi',
    'reason' => 'Motivo',
    'test' => 'Invia una prova',
    'testTo' => 'Invia a',
    'testSent' => 'Prova inviata. Guarda il registro qui sotto.',
    'testHint' => 'Usa il tuo testo con valori di esempio, per vedere cosa vede l’acquirente.',
    'filterAll' => 'Tutti gli stati',
    'preview' => 'Anteprima',
    'example' => [
        'buyer' => 'Alex Rossi',
        'event' => 'Prima serata',
        'venue' => 'Teatro Northgate',
        'seats' => 'Platea A 12, Platea A 13',
    ],
    'announceHeading' => 'Annunci',
    'announceIntro' => 'Scrivete a tutti quelli che hanno comprato — una data spostata, un ingresso diverso, un grazie.',
    'announceNew' => 'Scrivi un annuncio',
    'announceAudience' => 'A chi',
    'audienceEveryone' => 'Tutti quelli che hanno comprato da voi',
    'audienceEvent' => 'Gli acquirenti di un evento',
    'announceEvent' => 'Evento',
    'announceChannels' => 'Invia tramite',
    'announceSubject' => 'Oggetto',
    'announceSubjectHint' => 'Solo per l’e-mail. Un SMS non ha oggetto.',
    'announceBody' => 'Messaggio',
    'announceBodyHint' => 'Potete usare {buyer} e {site}. {event} funziona quando scrivete agli acquirenti di un evento.',
    'announceReach' => ':people persone · :messages messaggi',
    'announceReachNobody' => 'Non corrisponde ancora nessuno.',
    'announceSend' => 'Invia',
    'announceConfirm' => 'Inviare questo annuncio?',
    'announceConfirmBody' => 'Va a :messages messaggi e non si può richiamare.',
    'announceSent' => 'In viaggio.',
    'announceNone' => 'Nessun annuncio finora',
    'announceNoneHint' => 'Un annuncio va a chi ha comprato, sui canali che scegliete.',
    'announceStatusDraft' => 'Bozza',
    'announceStatusSending' => 'In invio',
    'announceStatusSent' => 'Inviato',
    'announceProgress' => ':sent di :total inviati',
    'announceFailed' => ':count non consegnati',
    'announceWhen' => 'Scritto',
    'announceOnlyPaid' => 'Si scrive solo a chi ha pagato l’ordine.',
];
