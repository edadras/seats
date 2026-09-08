<?php

/**
 * Ogni messaggio che l'API restituisce quando rifiuta.
 *
 * Il `code` che accompagna questi messaggi non è tradotto e non lo sarà mai: i programmi leggono il
 * codice, le persone leggono il messaggio.
 */
return [
    // --- Accesso e autorizzazioni -----------------------------------------------------------
    'unauthenticated' => 'Devi accedere.',
    'invalid_credentials' => 'Questa email e questa password non corrispondono.',
    'forbidden' => 'Il tuo ruolo non consente modifiche a questo account.',
    'forbidden_permission' => 'Non hai il permesso :permission.',
    'tenant_suspended' => 'Questo account è sospeso. Contattaci.',
    'not_found' => 'Non trovato.',

    // --- Firma, reinvio e idempotenza -------------------------------------------------------
    'invalid_signature' => 'La firma della richiesta non corrisponde.',
    'signature_expired' => 'L’orario della richiesta è fuori dalla finestra consentita. Controlla l’orologio del server chiamante.',
    'nonce_reused' => 'Questa richiesta è già stata inviata.',
    'idempotency_key_reuse' => 'Questa chiave di idempotenza è già stata usata per un altro contenuto.',
    'rate_limited' => 'Troppe richieste. Riprova tra un momento.',

    // --- Posti, prenotazioni e capienza -----------------------------------------------------
    'seat_unavailable' => 'Uno di quei posti non è più disponibile.',
    'capacity_unavailable' => 'Non restano così tanti posti.',
    'hold_expired' => 'La tua prenotazione è scaduta. Scegli di nuovo i posti.',
    'hold_not_found' => 'Quella prenotazione non esiste più.',
    'too_many_seats' => 'Puoi scegliere al massimo :max posti per volta.',
    'too_many_holds' => 'Hai già tutte le prenotazioni che una sessione può tenere.',
    'seat_not_in_event' => 'Questo posto non fa parte di questo evento.',
    'event_not_on_sale' => 'Questo evento non è in vendita.',

    // --- Ordini -----------------------------------------------------------------------------
    'order_not_found' => 'Questo ordine non esiste.',
    'order_already_confirmed' => 'Questo ordine è già stato confermato.',
    'order_cancelled' => 'Questo ordine è stato annullato.',
    'refund_exceeds_order' => 'Non puoi rimborsare più del valore dell’ordine.',
    'payment_failed' => 'Il pagamento non è andato a buon fine. Non è stato addebitato nulla.',
    'payment_verification_failed' => 'Non siamo riusciti a verificare il pagamento presso il gateway. Se è stato addebitato del denaro, contattaci e lo rintracceremo.',

    // --- Piante della sala ------------------------------------------------------------------
    'map_invalid' => 'La pianta non può essere pubblicata finché non sono risolti i suoi errori.',
    'map_published' => 'Una pianta pubblicata non si modifica. Salva invece una nuova versione.',
    'map_in_use' => 'Questa pianta è usata da un evento che ha già venduto posti.',
    'map_too_large' => 'Questa pianta supera quanto consente il tuo piano.',

    // --- Biglietti e ingresso ---------------------------------------------------------------
    'ticket_already_used' => 'Qualcuno è già entrato con questo biglietto. Liberare il posto ora significherebbe vendere un posto occupato.',
    'ticket_no_allocation' => 'Questo biglietto non è collegato a un posto.',
    'ticket_no_order' => 'Questo biglietto non è collegato a un ordine.',
    'pairing_code_expired' => 'Questo codice di abbinamento è scaduto. Chiedine uno nuovo.',
    'device_revoked' => 'Questo dispositivo non è più abbinato.',

    // --- Siti e domini ----------------------------------------------------------------------
    'unknown_theme' => 'Questo tema non esiste.',
    'no_verified_domain' => 'Aggiungi e verifica un dominio prima di pubblicare il sito — altrimenti non c’è un indirizzo da visitare.',
    'slug_taken' => 'Un’altra pagina usa già questo indirizzo.',
    'home_slug_fixed' => 'La home page sta alla radice e non può essere spostata.',
    'page_required' => 'Le pagine home ed evento fanno parte del funzionamento del sito e non si possono rimuovere.',
    'too_many_pages' => 'Questo sito ha tutte le pagine che può contenere.',
    'too_many_domains' => 'Questo sito ha già tutti gli indirizzi che può avere.',
    'hostname_taken' => 'Questo indirizzo è già in uso.',
    'invalid_hostname' => 'Non è un nome host che possiamo servire.',
    'domain_unverified' => 'Verifica l’indirizzo prima di renderlo quello principale.',
    'primary_domain' => 'Rendi prima principale un altro indirizzo.',
    'site_unavailable' => 'Questo sito non è disponibile.',
    'no_site_here' => 'A questo indirizzo non è pubblicato alcun sito.',

    // --- Piani e limiti ---------------------------------------------------------------------
    'tenant_limit_reached' => 'Il tuo piano consente :limit :resource. Passa a un piano superiore per averne di più.',
    'subscription_inactive' => 'Questo account non ha un abbonamento attivo.',

    // --- Generale ---------------------------------------------------------------------------
    'validation_failed' => 'Il contenuto della richiesta non è valido.',
    'server_error' => 'Si è verificato un errore imprevisto.',
    'http_error' => 'Richiesta non riuscita.',

    // --- Prendono il nome dal codice d’errore: il codice è la chiave, nessun chiamante la nomina
    'client_disabled' => 'Questo client API non è attivo.',
    'invalid_key' => 'La chiave API è sconosciuta, scaduta o revocata.',
    'invalid_nonce' => 'Il formato del nonce non è accettabile.',
    'invalid_pairing_code' => 'Questo codice di abbinamento è sconosciuto o scaduto.',
    'device_token_required' => 'È richiesto il token di un dispositivo di ingresso.',
    'device_not_authorised' => 'Questo dispositivo non è autorizzato a scansionare quell’evento.',
    'no_membership' => 'Questo account non appartiene a nessun organizzatore.',
    'not_part_of_site' => 'Non fa parte di questo sito.',
    'unknown_event' => 'Evento sconosciuto.',
    'unknown_tenant' => 'Organizzatore sconosciuto.',

// --- Team, ruoli e inviti ---------------------------------------------------------------
    'member_suspended' => 'Il tuo accesso a questo account è stato sospeso.',
    'reserved_role' => 'Questo nome appartiene a un ruolo integrato. Scegline un altro.',
    'role_in_use' => 'Qualcuno ha ancora questo ruolo. Spostalo prima.',
    'last_owner' => 'Un account deve conservare almeno un proprietario.',
    'cannot_change_own_role' => 'Non puoi cambiare il tuo stesso ruolo.',
    'invitation_invalid' => 'Questo invito non è valido, oppure è già stato usato.',
    'invitation_expired' => 'Questo invito è scaduto. Chiedine uno nuovo.',
    'already_a_member' => 'Questa persona fa già parte di questo account.',
];
