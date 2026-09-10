<?php

/**
 * Every message the API gives a person when it refuses.
 *
 * These were English literals at the call sites until ADR-0005. They are here now for the same
 * reason every other string is: a buyer whose seat was taken while they were choosing should read
 * that sentence in their own language, and that sentence is the one they are most likely to read.
 *
 * The `code` beside each of these is *not* translated and never will be. A client parses the code;
 * a person reads the message. Translating a code would break every integration at once.
 */
return [
    // --- Authentication and authorisation ---------------------------------------------------
    'unauthenticated' => 'Devi accedere.',
    'invalid_credentials' => 'Questa email e questa password non corrispondono.',
    'forbidden' => 'Il tuo ruolo non consente modifiche a questo account.',
    'forbidden_permission' => 'Non hai il permesso :permission.',
    'tenant_suspended' => 'Questo account è sospeso. Contattaci.',
    'not_found' => 'Non trovato.',
    'challenge_expired' => 'Ci è voluto troppo. Accedi di nuovo.',
    'invalid_code' => 'Quel codice non è giusto. Prova il prossimo.',
    'two_factor_off' => "L'accesso in due passaggi non è attivo.",
    'two_factor_required' => "Questo account richiede l'accesso in due passaggi, quindi non si può disattivare.",
    'set_up_yours_first' => "Attiva l'accesso in due passaggi sul tuo account prima di richiederlo a tutti.",
    'signin_not_configured' => 'A questa piattaforma non sono state date credenziali Google, quindi gli acquirenti non possono ancora accedere.',

    // --- Signing, replay and idempotency ----------------------------------------------------
    'invalid_signature' => 'La firma della richiesta non corrisponde.',
    'nonce_reused' => 'Questa richiesta è già stata inviata.',
    'idempotency_key_reuse' => 'Questa chiave di idempotenza è già stata usata per un altro contenuto.',
    'missing_credentials' => 'X-Seatmap-Key, X-Seatmap-Timestamp, X-Seatmap-Nonce e X-Seatmap-Signature sono tutti obbligatori.',
    'stale_timestamp' => "L'orario della richiesta è fuori dalla finestra consentita di :seconds secondi. Controlla l'orologio del server chiamante.",
    'replay_check_unavailable' => 'La protezione dai replay non è momentaneamente disponibile. Riprova tra poco.',
    'invalid_idempotency_key' => 'Quella Idempotency-Key è troppo lunga.',
    'idempotency_key_in_flight' => 'Una richiesta con questa Idempotency-Key è in elaborazione. Riprova tra poco.',

    // --- Seats, holds and capacity ----------------------------------------------------------
    'seat_unavailable' => 'Uno di quei posti non è più disponibile.',
    'no_seats_together' => 'Non ci sono così tanti posti vicini.',
    'capacity_unavailable' => 'Non restano così tanti posti.',
    'hold_expired' => 'La tua prenotazione è scaduta. Scegli di nuovo i posti.',
    'hold_not_found' => 'Quella prenotazione non esiste più.',
    'too_many_seats' => 'Puoi scegliere al massimo :max posti per volta.',
    'no_seats' => 'Scegli almeno un posto.',
    'unknown_seats' => 'Uno o più di quei posti non appartengono a questo evento.',
    'unknown_capacity_objects' => 'Una o più di quelle aree non appartengono a questo evento.',
    'seat_not_priced' => 'Uno o più di quei posti non hanno un prezzo per questo evento e non si possono vendere.',
    'area_not_priced' => 'Una o più di quelle aree non hanno un prezzo per questo evento e non si possono vendere.',
    'seats_not_named' => 'Quei posti non si possono restituire uno alla volta.',
    'event_not_sellable' => 'Questo evento non è in vendita.',
    'map_not_published' => 'Pubblica la mappa dei posti prima di mettere in vendita questo evento.',
    'too_many_active_holds' => 'Questa sessione tiene già posti in :count carrelli. Completane uno o lascialo.',
    'extend_limit_reached' => 'Questa prenotazione è già stata prolungata :count volte.',
    'hold_empty' => 'Su questa prenotazione non restano posti.',
    'hold_missing' => "A quest'ordine non è collegata alcuna prenotazione.",

    // --- Orders -----------------------------------------------------------------------------
    'order_not_found' => 'Questo ordine non esiste.',
    'payment_failed' => 'Il pagamento non è andato a buon fine. Non è stato addebitato nulla.',
    'discount_used_up' => 'Questo codice è appena stato usato per l’ultima volta. Non è stato addebitato nulla.',
    'entry_slot_required' => "Scegliete un orario d'ingresso prima di prenotare.",
    'entry_slot_full' => "Questo orario d'ingresso è esaurito. Sceglietene un altro.",
    'entry_slot_closed' => "Questo orario d'ingresso non è più offerto.",
    'invalid_transition' => 'Un ordine che è :status non può essere confermato.',
    'order_not_confirmed' => 'Non ci sono ancora biglietti da inviare.',
    'order_not_refundable' => 'Solo un ordine confermato può essere rimborsato.',
    'nothing_to_refund' => "Su questa prenotazione non c'è nulla da restituire.",
    'nothing_to_send' => 'Ogni biglietto di questa prenotazione è stato usato o annullato.',
    'no_address' => "Su questa prenotazione non c'è un indirizzo email.",
    'no_site' => 'I biglietti partono da un sito web, e questo account non ne ha nessuno online.',
    'no_live_site' => 'Il messaggio rimanda a una pagina di un sito online, e questo account non ne ha ancora uno.',
    'email_required' => 'Serve un indirizzo email per inviare i biglietti.',
    'channel_required' => "All'acquirente va detto che la prenotazione è confermata. Scegli almeno un modo per dirglielo.",
    'entry_slot_not_offered' => 'Questo evento non vende ingressi a orario.',
    'unknown_entry_slot' => 'Non esiste un orario di ingresso simile.',
    'unknown_ticket_type' => 'Questo evento non vende tipi di biglietto.',
    'ticket_type_not_on_sale' => 'Uno dei tipi di biglietto scelti non è in vendita.',
    'ticket_type_min' => 'Almeno :count biglietti :type vanno acquistati insieme.',
    'ticket_type_max' => 'Al massimo :count biglietti :type si possono comprare in una volta.',
    'discount_code_taken' => 'Hai già un codice con quel nome.',
    'discount_in_use' => 'Questo codice è stato usato. Mettilo in pausa invece di cancellarlo — cancellarlo lascerebbe quelle prenotazioni senza spiegazione.',

    // --- Seat maps --------------------------------------------------------------------------
    'invalid_geometry' => 'La pianta non può essere pubblicata finché non sono risolti i suoi errori.',
    'no_draft' => "Non c'è una bozza da pubblicare.",
    'already_published' => 'Questa versione è già pubblicata.',
    'unknown_zone' => 'Un posto è stato messo in una fascia di prezzo che questo evento non ha.',
    'venue_in_use' => 'Questa sede ha ancora eventi. Prima eliminali o spostali.',

    // --- Tickets and check-in ---------------------------------------------------------------
    'ticket_already_used' => 'Qualcuno è già entrato con questo biglietto. Liberare il posto ora significherebbe vendere un posto occupato.',
    'no_allocation' => 'Questo biglietto non è collegato a un posto.',
    'no_order' => 'Questo biglietto non è collegato a un ordine.',
    'device_revoked' => 'Questo dispositivo non è più abbinato.',
    'already_used' => 'Qualcuno è già entrato con questo biglietto. Liberare il posto ora significherebbe vendere un posto occupato.',
    'no_tickets_to_add' => 'Su questa prenotazione non ci sono biglietti validi da aggiungere.',
    'same_person' => "È già l'indirizzo a cui il biglietto viene inviato.",
    'ticket_type_sold' => 'Un tipo di biglietto già venduto non si può eliminare. Nascondilo invece.',
    'entry_slot_sold' => 'Un orario di ingresso già venduto non si può togliere. Chiudilo invece.',
    'window_too_short' => "Quel periodo è più corto di una finestra d'ingresso.",
    'question_answered' => 'Una domanda a cui qualcuno ha risposto non si può eliminare. Nascondila invece.',

    // --- Sites and domains ------------------------------------------------------------------
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
    'email_unverified' => 'Verifica il tuo indirizzo email prima di mettere un sito su internet.',
    'wrong_theme' => 'Quella versione appartiene a un altro tema.',
    'theme_in_use' => 'Alcuni siti indossano ancora questo tema.',

    // --- Plans and limits -------------------------------------------------------------------
    'plan_limit_reached' => 'Il tuo piano consente :limit posti per mappa; questa ne ha :count.',
    'no_plans' => 'Le iscrizioni sono chiuse al momento.',
    'email_taken' => 'Questo indirizzo email ha già un account. Accedi invece.',
    'code_expired' => 'Quel codice è scaduto. Chiedine uno nuovo.',
    'code_wrong' => 'Quel codice non è giusto.',
    'module_not_installed' => 'Quel modulo non è installato su questo server.',
    'module_not_configured' => 'Compila ciò che serve a questo modulo prima di attivarlo.',

    // --- Generic ----------------------------------------------------------------------------
    'validation_failed' => 'Il contenuto della richiesta non è valido.',
    'server_error' => 'Si è verificato un errore imprevisto.',
    'http_error' => 'Richiesta non riuscita.',

    // --- Named by their error code, so the code is the key and no call site names one -------
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

    // --- Team, roles and invitations --------------------------------------------------------
    'member_suspended' => 'Il tuo accesso a questo account è stato sospeso.',
    'reserved_role' => 'Questo nome appartiene a un ruolo integrato. Scegline un altro.',
    'role_in_use' => 'Qualcuno ha ancora questo ruolo. Spostalo prima.',
    'last_owner' => 'Un account deve conservare almeno un proprietario.',
    'cannot_change_own_role' => 'Non puoi cambiare il tuo stesso ruolo.',
    'already_a_member' => 'Questa persona fa già parte di questo account.',
    'role_exists' => 'Un ruolo usa già quel nome.',
    'unknown_role' => 'Quel ruolo non esiste.',

    // --- The platform console ---------------------------------------------------------------
    'too_many_attempts' => 'Troppi tentativi. Riprova fra :seconds secondi.',
    'unknown_plan' => 'Questo piano non esiste.',
    'no_owner' => 'Questo account non ha nessuno per cui agire.',
    'support_may_not_change' => 'Gli account di supporto possono guardare, non cambiare.',
    'plan_in_use' => 'Ci sono organizzatori su questo piano. Disattivalo invece di eliminarlo: lo toglie dalla schermata di iscrizione e lascia loro dove sono.',

    // --- Events that are called off or moved -------------------------------------------------
    'event_cancelled' => 'Un evento annullato non si può spostare. Rimettilo prima in vendita.',
    'event_has_no_date' => 'Questo evento non ha una data da spostare.',
    'confirm_with_the_name' => "Scrivi il nome dell'evento esattamente com'è per confermare.",

    // --- Reports -----------------------------------------------------------------------------
    'unknown_source' => 'Non esiste una fonte di report simile.',
    'unknown_filter_value' => 'Non è una delle scelte di questo filtro.',
    'report_needs_a_measure' => 'Un report deve contare o sommare qualcosa.',
    'report_too_slow' => 'Quel report ha impiegato più di :seconds secondi. Restringilo con un filtro, o esportalo.',

    // --- Messages ----------------------------------------------------------------------------
    'unknown_message_kind' => 'Non esiste un messaggio simile.',
    'unknown_channel' => 'Quel canale non è disponibile.',

    // --- Wallet passes -----------------------------------------------------------------------
    'apple_wallet_not_set_up' => 'Questo account non ha un certificato Apple Wallet.',
    'apple_certificate_unreadable' => 'Quel certificato non si è potuto leggere.',
    'apple_key_unreadable' => 'Quella chiave privata non si è potuta leggere — controlla la password.',
    'apple_signing_failed' => 'Il pass non si è potuto firmare con quel certificato.',
    'pass_not_written' => 'Il pass non si è potuto assemblare.',
    'google_wallet_not_set_up' => 'Questo account non ha un emittente Google Wallet.',
    'google_service_account_unreadable' => 'Quel file di account di servizio non si è potuto leggere.',
    'google_signing_failed' => "Il pass non si è potuto firmare con quell'account di servizio.",

    // --- Presale and access codes -------------------------------------------------------------
    'access_code_required' => 'Questo evento è in prevendita. Per prenotare serve un codice.',
    'unknown_code' => 'Quel codice non è giusto.',
    'code_not_live' => 'Quel codice al momento non funziona.',
    'code_used_up' => 'Quel codice è stato usato tutte le volte che poteva.',
    'presale_not_open' => 'La prevendita non è ancora aperta.',
    'access_code_seat_limit' => 'Quel codice vale per :count posti alla volta.',
    'access_code_taken' => 'Hai già un codice con quel nome.',
    'access_code_in_use' => "Questo codice ha fatto entrare qualcuno. Mettilo in pausa invece di cancellarlo — cancellarlo lascerebbe quelle prenotazioni senza spiegazione.",

    // --- Add-ons and donations ----------------------------------------------------------------
    'unknown_addon' => 'Non è una cosa che questo evento vende.',
    'addon_too_many' => 'Al massimo :count di ":name" si possono comprare in una volta.',
    'addon_sold_out' => 'Non ne restano così tanti di ":name".',
    'addon_sold' => 'Ciò che è stato venduto non si può rimuovere. Nascondilo invece.',
    'donation_negative' => 'Una donazione non può essere meno di niente.',
    'donation_too_large' => "È più di quanto questa cassa accetti. Parlane con l'organizzatore.",
    'voucher_spent' => 'Questo buono è appena finito.',
    'voucher_code_taken' => 'Avete già un buono con quel codice.',
    'no_address_for_credit' => 'Questa prenotazione non ha un indirizzo e-mail, quindi non c’è nessuno a cui accreditare il credito.',
    'credit_needs_whole_booking' => 'Il credito si emette per una prenotazione intera. Rimborsate i posti, poi emettete un buono per quanto dovete.',
    'season_map_differs' => '«:name» ha una disposizione diversa, quindi gli stessi posti non possono essere tenuti.',
    'season_night_unavailable' => 'Quei posti sono già presi per «:name», quindi non si può tenere tutta la serie.',
    'not_in_this_run' => 'Quella serata non fa parte di questo abbonamento.',
    'season_too_few_nights' => 'Questo abbonamento vale per almeno :count serate.',
    'season_pass_named' => 'Questa serie ha già un abbonamento con quel nome.',
    'season_pass_sold' => 'Qualcuno ha comprato questo abbonamento. Sospendetelo invece di eliminarlo — quelle prenotazioni resterebbero senza spiegazione.',
    'season_needs_nights' => 'Un abbonamento di cui l’acquirente sceglie le serate deve dire quante.',
    'season_not_payable' => 'Questo abbonamento non ha pagamenti da saldare.',
    'discount_over_100' => 'Una percentuale non può superare 100.',
    'basket_already_settled' => 'Quel carrello è già stato sistemato.',
    'basket_gone' => 'Di quel carrello non resta nulla.',
    'basket_event_closed' => '«:name» non è più in vendita.',
    'basket_seats_gone' => 'Nel frattempo qualcun altro ha preso quei posti. Scegliete di nuovo — il resto della sala è ancora lì.',
    'basket_already_written' => 'A questo acquirente è già stato scritto per questo carrello.',
    'basket_has_no_site' => 'Non c’è nessun posto a cui rimandare questo acquirente.',
    'basket_message_off' => 'Accendete prima «Prenotazione a metà» in Messaggi — finché non lo fate non parte nulla.',
    'waiting_your_turn' => 'Non è ancora il vostro turno. Il posto in coda resta vostro.',
    'channel_quota_reached' => 'A questo canale di vendita restano :count posti per questo evento.',
    'channel_quota_gone' => 'Questo canale di vendita ha venduto tutta la sua quota per questo evento.',
    'unknown_segment' => 'Non esiste un pubblico salvato con quel nome.',
    'spend_needs_currency' => 'Indica in quale valuta è quell’importo — un account che vende in due valute ha due risposte.',
];
