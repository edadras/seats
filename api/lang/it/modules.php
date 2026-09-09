<?php

/**
 * La schermata dei moduli e i testi di ogni modulo integrato.
 *
 * I testi dei moduli integrati stanno qui e non accanto al loro codice, così lo stesso controllo CI
 * che tutela le altre sei lingue tutela anche questi. Un modulo di terze parti registra il proprio
 * spazio di traduzione — vedi docs/MODULES.md.
 */
return [
    'title' => 'Moduli',
    'subtitle' => 'Cosa sa fare questo account oltre alle basi. Attivane uno, configuralo, e compare dove serve.',
    'installed' => 'Disponibili',
    'enabled' => 'Attivo',
    'disabled' => 'Spento',
    'enable' => 'Attiva',
    'disable' => 'Disattiva',
    'configure' => 'Impostazioni',
    'secretSet' => 'Impostato — sostituiscilo per cambiarlo',
    'secretUnset' => 'Non impostato',
    'byVendor' => 'di :vendor',
    'firstParty' => 'Integrato',
    'health' => 'Stato',
    'healthy' => 'Funziona',
    'failing' => ':count problemi nell’ultimo giorno',
    'autoDisabled' => 'Disattivato in automatico dopo errori ripetuti: :reason',
    'lastFailure' => 'Ultimo problema :when',
    'noFailures' => 'Non è andato storto nulla.',
    'extends' => [
        'payments' => 'Pagamenti',
        'messaging' => 'Messaggi',
        'reports' => 'Report',
        'blocks' => 'Blocchi di pagina',
        'themes' => 'Temi',
        'panel' => 'Schermate del pannello',
        'events' => 'Automazioni',
    ],
    'errors' => [
        'required' => 'Serve prima di poter attivare il modulo.',
        'invalid' => 'Questa impostazione non accetta quel valore.',
        'not_installed' => 'Questo modulo non è installato su questo server.',
        'not_configured' => 'Compila ciò che serve al modulo prima di attivarlo.',
    ],
    'seatmap' => [
        'kavenegar' => [
            'name' => 'Kavenegar',
            'description' => 'Invia SMS in Iran tramite Kavenegar.',
            'settings' => [
                'api_key' => 'Chiave API',
                'api_key_hint' => 'Dal tuo pannello Kavenegar. Sola scrittura: salvata cifrata e mai più mostrata.',
                'sender' => 'Linea mittente',
                'sender_hint' => 'La tua linea dedicata. Vuoto usa quella condivisa del conto.',
            ],
        ],
        'sms_ir' => [
            'name' => 'SMS.ir',
            'description' => 'Invia SMS in Iran tramite SMS.ir.',
            'settings' => [
                'api_key' => 'Chiave API',
                'api_key_hint' => 'Dal cruscotto SMS.ir. Sola scrittura: salvata cifrata e mai più mostrata.',
                'line_number' => 'Numero di linea',
                'line_number_hint' => 'SMS.ir non invia senza: si chiede qui, non alle otto di sera nel registro.',
            ],
        ],
        'twilio' => [
            'name' => 'Twilio',
            'description' => 'Invia SMS fuori dall’Iran tramite Twilio.',
            'settings' => [
                'account_sid' => 'Account SID',
                'account_sid_hint' => 'Inizia con AC, dalla console Twilio.',
                'auth_token' => 'Auth token',
                'auth_token_hint' => 'Sola scrittura: salvato cifrato e mai più mostrato.',
                'from' => 'Numero mittente',
                'from_hint' => 'Un numero o un identificativo alfanumerico approvato da Twilio.',
            ],
        ],
        'telegram' => [
            'name' => 'Telegram',
            'description' => 'Scrivi agli acquirenti con un bot Telegram tuo.',
            'settings' => [
                'bot_token' => 'Token del bot',
                'bot_token_hint' => 'Da @BotFather. Un bot può scrivere solo a chi ha iniziato la conversazione: arriva quindi a chi ha scelto di essere raggiungibile.',
            ],
        ],
        'whatsapp' => [
            'name' => 'WhatsApp',
            'description' => 'Scrivi agli acquirenti con la Cloud API di WhatsApp. Il testo libero vale solo entro 24 ore dal loro ultimo messaggio; oltre, Meta richiede un modello approvato, e qui si invia testo semplice.',
            'settings' => [
                'access_token' => 'Token di accesso',
                'access_token_hint' => 'Dalla tua app Meta. Sola scrittura: salvato cifrato e mai più mostrato.',
                'phone_number_id' => 'ID del numero',
                'phone_number_id_hint' => 'L’id del numero in WhatsApp Manager — non il numero stesso.',
            ],
        ],
        'zarinpal' => [
            'name' => 'Zarinpal',
            'description' => 'Accetta carte bancarie iraniane tramite Zarinpal. Prezzi in rial o toman — indicalo, la differenza è dieci volte.',
            'settings' => [
                'merchant_id' => 'ID esercente',
                'merchant_id_hint' => 'L’UUID dal tuo pannello Zarinpal.',
                'amount_unit' => 'I tuoi prezzi sono in',
                'amount_unit_hint' => 'Zarinpal regola in rial. Se prezzi in toman, scegli toman e moltiplichiamo noi.',
                'sandbox' => 'Usa la sandbox',
                'sandbox_hint' => 'Pagamenti di prova. Nessun denaro si muove.',
            ],
        ],
        'idpay' => [
            'name' => 'IDPay',
            'description' => 'Accetta carte bancarie iraniane tramite IDPay.',
            'settings' => [
                'api_key' => 'Chiave API',
                'api_key_hint' => 'Dal cruscotto IDPay, per questo sito.',
                'amount_unit' => 'I tuoi prezzi sono in',
                'amount_unit_hint' => 'IDPay regola in rial. Se prezzi in toman, scegli toman.',
                'sandbox' => 'Usa la sandbox',
                'sandbox_hint' => 'Invia l’intestazione sandbox. Nessun denaro si muove.',
            ],
        ],
        'nextpay' => [
            'name' => 'NextPay',
            'description' => 'Accetta carte bancarie iraniane tramite NextPay.',
            'settings' => [
                'api_key' => 'Chiave API',
                'api_key_hint' => 'Dal tuo pannello NextPay.',
                'amount_unit' => 'I tuoi prezzi sono in',
                'amount_unit_hint' => 'NextPay regola in rial. Se prezzi in toman, scegli toman.',
            ],
        ],
        'stripe' => [
            'name' => 'Stripe',
            'description' => 'Accetta carte in tutto il mondo con Stripe Checkout. I dati della carta vanno a Stripe, mai a questo server.',
            'settings' => [
                'secret_key' => 'Chiave segreta',
                'secret_key_hint' => 'Inizia con sk_live_ o sk_test_. Sola scrittura: salvata cifrata e mai più mostrata.',
                'statement_descriptor' => 'Sull’estratto conto',
                'statement_descriptor_hint' => 'Fino a 22 caratteri dopo il nome dell’attività. Vuoto usa l’impostazione di Stripe.',
            ],
        ],
        'paypal' => [
            'name' => 'PayPal',
            'description' => 'Accetta saldi PayPal e carte tramite PayPal Orders.',
            'settings' => [
                'client_id' => 'Client ID',
                'client_id_hint' => 'Dalle credenziali della tua app PayPal.',
                'client_secret' => 'Client secret',
                'client_secret_hint' => 'Sola scrittura: salvata cifrata e mai più mostrata.',
                'sandbox' => 'Usa la sandbox',
                'sandbox_hint' => 'Parla con la sandbox di PayPal invece dell’API reale.',
            ],
        ],
        'offline_payments' => [
            'name' => 'Paga al botteghino',
            'description' => 'Chi acquista prenota online e paga quando arriva. I posti vengono tenuti e i biglietti emessi esattamente come per una carta; solo l’incasso avviene altrove.',
            'settings' => [
                'instructions' => 'Cosa dire a chi acquista',
                'instructions_hint' => 'Mostrato al pagamento al posto del testo predefinito. Indica dove e quando si può pagare.',
            ],
        ],
    ],
];
