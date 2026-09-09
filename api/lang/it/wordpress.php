<?php

/**
 * What the WordPress plugin says on its own.
 *
 * Keyed by the English string rather than by a symbolic name, because in gettext the English string
 * *is* the key: the plugin's source says `__( 'Total', 'seatmap-connect' )` and WordPress looks the
 * catalogue up by exactly those words. `tools/sync-wordpress-strings.mjs` turns this file into the
 * `.po` and `.mo` the plugin ships, and CI fails if the two have drifted.
 *
 * The seat picker's own vocabulary is *not* here. It lives under `picker` in site.php and the tool
 * matches it by key, so a buyer choosing a seat on a WooCommerce shop reads the same words as a
 * buyer on a hosted site. Duplicating them here would be two translations of one component.
 *
 * A plural keeps both shapes in one entry, separated by a pipe.
 */
return [
    '%1$d place in %2$s' => '%1$d posto in %2$s|%1$d posti in %2$s',
    '%1$s, row %2$s, seat %3$s' => '%1$s, fila %2$s, posto %3$s',
    'A simple, virtual product used as the cart line for a seat. Its own price is ignored — the price always comes from the signed Seatmap response.' => 'Un semplice prodotto virtuale, usato come riga del carrello per un posto. Il suo prezzo viene ignorato: il prezzo arriva sempre dalla risposta firmata di Seatmap.',
    'API URL' => 'URL dell’API',
    'Check the key ID and secret, and that the key has not been revoked.' => 'Controlla l’ID della chiave e il segreto, e che la chiave non sia stata revocata.',
    'Checks the credentials and the clock. Signed requests are rejected if this server\'s time is more than five minutes from the API\'s.' => 'Controlla le credenziali e l’orologio. Le richieste firmate vengono rifiutate se l’ora di questo server si discosta di oltre cinque minuti da quella dell’API.',
    'Choose at least one seat or place.' => 'Scegli almeno un posto.',
    'Choosing seats needs JavaScript. Please enable it, or contact the box office.' => 'Per scegliere i posti serve JavaScript. Attivalo, oppure contatta il botteghino.',
    'Connect this store to your Seatmap account. Seats, prices and availability come from Seatmap; the cart, payment and refunds stay here in WooCommerce.' => 'Collega questo negozio al tuo account Seatmap. Posti, prezzi e disponibilità arrivano da Seatmap; carrello, pagamento e rimborsi restano qui in WooCommerce.',
    'Connected. Credentials and clock are good.' => 'Connesso. Credenziali e orologio sono a posto.',
    'Connected.' => 'Connesso.',
    'Connection' => 'Connessione',
    'Copy this from the event in your Seatmap panel. It looks like evt_xxxxxxxx.' => 'Copialo dall’evento nel tuo pannello Seatmap. Ha la forma evt_xxxxxxxx.',
    'Customers will pick their seats here.' => 'Qui i clienti sceglieranno i loro posti.',
    'Enter the event ID to show its seating plan.' => 'Inserisci l’ID dell’evento per mostrarne la piantina.',
    'Event public ID' => 'ID pubblico dell’evento',
    'Every five minutes (Seatmap)' => 'Ogni cinque minuti (Seatmap)',
    'Fill in the API URL, key ID and secret first.' => 'Compila prima l’URL dell’API, l’ID della chiave e il segreto.',
    'Key ID' => 'ID della chiave',
    'Let customers pick their seats for a Seatmap event.' => 'Lascia che i clienti scelgano i posti per un evento Seatmap.',
    'No event was selected for this seat map.' => 'Per questa piantina non è stato scelto alcun evento.',
    'Not permitted.' => 'Non consentito.',
    'Reserve and add to cart' => 'Prenota e aggiungi al carrello',
    'Seat booking is not available right now.' => 'La prenotazione dei posti non è disponibile al momento.',
    'Seat map' => 'Piantina',
    'Seat product' => 'Prodotto posto',
    'Seat' => 'Posto',
    'Seatmap %1$s failed: %2$s' => 'Seatmap %1$s non riuscito: %2$s',
    'Seatmap Connect is not configured yet.' => 'Seatmap Connect non è ancora configurato.',
    'Seatmap Connect needs WooCommerce to be installed and active.' => 'Seatmap Connect ha bisogno che WooCommerce sia installato e attivo.',
    'Seatmap Connect' => 'Seatmap Connect',
    'Seatmap' => 'Seatmap',
    'Seatmap: could not confirm the seats yet. This will be retried automatically.' => 'Seatmap: i posti non sono ancora stati confermati. Il tentativo verrà ripetuto automaticamente.',
    'Seatmap: reconciliation found this order already confirmed.' => 'Seatmap: la riconciliazione ha trovato questo ordine già confermato.',
    'Seatmap: seats allocated and tickets issued.' => 'Seatmap: posti assegnati e biglietti emessi.',
    'Seats allocated and tickets issued.' => 'Posti assegnati e biglietti emessi.',
    'Seats are held, awaiting payment.' => 'Posti trattenuti, in attesa del pagamento.',
    'Secret' => 'Segreto',
    'Shown once by Seatmap when the key was created. Leave the masked value alone to keep the current secret.' => 'Mostrato una sola volta da Seatmap alla creazione della chiave. Lascia il valore mascherato com’è per mantenere il segreto attuale.',
    'Standing' => 'In piedi',
    'Test connection' => 'Prova la connessione',
    'Testing…' => 'Prova in corso…',
    'The API URL must use HTTPS. Requests carry your API credentials.' => 'L’URL dell’API deve usare HTTPS. Le richieste portano le tue credenziali.',
    'The seating plan for this event is not published yet.' => 'La piantina di questo evento non è ancora pubblicata.',
    'The seating service returned status %d.' => 'Il servizio dei posti ha risposto con lo stato %d.',
    'The test request itself failed.' => 'La richiesta di prova stessa non è riuscita.',
    'This event could not be loaded.' => 'Non è stato possibile caricare questo evento.',
    'This server\'s clock is out of step with the API. Fix NTP on this host.' => 'L’orologio di questo server è fuori passo rispetto all’API. Sistema NTP su questo host.',
    'This store is not finished setting up seat sales yet.' => 'Questo negozio non ha ancora finito di configurare la vendita dei posti.',
    'Waiting to reach the seating service. This retries automatically every five minutes.' => 'In attesa del servizio dei posti. Il tentativo si ripete automaticamente ogni cinque minuti.',
    'Without the /v1 suffix.' => 'Senza il suffisso /v1.',
    'You do not have permission to manage these settings.' => 'Non hai il permesso di gestire queste impostazioni.',
    'Your seat reservation ran out and the seats were released. Please choose your seats again.' => 'La tua prenotazione è scaduta e i posti sono tornati liberi. Scegli di nuovo i posti.',
    'Your seats could not be added to your cart.' => 'Non è stato possibile aggiungere i posti al carrello.',
];
