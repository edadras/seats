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
    '%1$d place in %2$s' => '%1$d Platz in %2$s|%1$d Plätze in %2$s',
    '%1$s, row %2$s, seat %3$s' => '%1$s, Reihe %2$s, Platz %3$s',
    'A simple, virtual product used as the cart line for a seat. Its own price is ignored — the price always comes from the signed Seatmap response.' => 'Ein einfaches, virtuelles Produkt als Warenkorbzeile für einen Platz. Sein eigener Preis wird ignoriert — der Preis kommt immer aus der signierten Antwort von Seatmap.',
    'API URL' => 'API-URL',
    'Check the key ID and secret, and that the key has not been revoked.' => 'Prüfen Sie Schlüssel-ID und Geheimnis, und ob der Schlüssel widerrufen wurde.',
    'Checks the credentials and the clock. Signed requests are rejected if this server\'s time is more than five minutes from the API\'s.' => 'Prüft die Zugangsdaten und die Uhr. Signierte Anfragen werden abgelehnt, wenn die Zeit dieses Servers mehr als fünf Minuten von der des API abweicht.',
    'Choose at least one seat or place.' => 'Wählen Sie mindestens einen Platz.',
    'Choosing seats needs JavaScript. Please enable it, or contact the box office.' => 'Für die Platzwahl wird JavaScript gebraucht. Bitte schalten Sie es ein oder wenden Sie sich an die Kasse.',
    'Connect this store to your Seatmap account. Seats, prices and availability come from Seatmap; the cart, payment and refunds stay here in WooCommerce.' => 'Verbinden Sie diesen Shop mit Ihrem Seatmap-Konto. Plätze, Preise und Verfügbarkeit kommen von Seatmap; Warenkorb, Zahlung und Erstattungen bleiben hier in WooCommerce.',
    'Connected. Credentials and clock are good.' => 'Verbunden. Zugangsdaten und Uhr sind in Ordnung.',
    'Connected.' => 'Verbunden.',
    'Connection' => 'Verbindung',
    'Copy this from the event in your Seatmap panel. It looks like evt_xxxxxxxx.' => 'Kopieren Sie das aus der Veranstaltung in Ihrem Seatmap-Panel. Es sieht aus wie evt_xxxxxxxx.',
    'Customers will pick their seats here.' => 'Hier wählen Ihre Kundinnen und Kunden ihre Plätze.',
    'Enter the event ID to show its seating plan.' => 'Geben Sie die Veranstaltungs-ID ein, um ihren Saalplan zu zeigen.',
    'Event public ID' => 'Öffentliche Veranstaltungs-ID',
    'Every five minutes (Seatmap)' => 'Alle fünf Minuten (Seatmap)',
    'Fill in the API URL, key ID and secret first.' => 'Tragen Sie zuerst API-URL, Schlüssel-ID und Geheimnis ein.',
    'Key ID' => 'Schlüssel-ID',
    'Let customers pick their seats for a Seatmap event.' => 'Lassen Sie Kundinnen und Kunden ihre Plätze für eine Seatmap-Veranstaltung wählen.',
    'No event was selected for this seat map.' => 'Für diesen Saalplan wurde keine Veranstaltung ausgewählt.',
    'Not permitted.' => 'Nicht erlaubt.',
    'Reserve and add to cart' => 'Reservieren und in den Warenkorb',
    'Seat booking is not available right now.' => 'Die Platzbuchung ist gerade nicht verfügbar.',
    'Seat map' => 'Saalplan',
    'Seat product' => 'Platzprodukt',
    'Seat' => 'Platz',
    'Seatmap %1$s failed: %2$s' => 'Seatmap %1$s fehlgeschlagen: %2$s',
    'Seatmap Connect is not configured yet.' => 'Seatmap Connect ist noch nicht eingerichtet.',
    'Seatmap Connect needs WooCommerce to be installed and active.' => 'Seatmap Connect braucht ein installiertes und aktives WooCommerce.',
    'Seatmap Connect' => 'Seatmap Connect',
    'Seatmap' => 'Seatmap',
    'Seatmap: could not confirm the seats yet. This will be retried automatically.' => 'Seatmap: Die Plätze konnten noch nicht bestätigt werden. Das wird automatisch wiederholt.',
    'Seatmap: reconciliation found this order already confirmed.' => 'Seatmap: Der Abgleich hat diese Bestellung bereits als bestätigt vorgefunden.',
    'Seatmap: seats allocated and tickets issued.' => 'Seatmap: Plätze zugeteilt und Tickets ausgestellt.',
    'Seats allocated and tickets issued.' => 'Plätze zugeteilt und Tickets ausgestellt.',
    'Seats are held, awaiting payment.' => 'Plätze sind reserviert, Zahlung ausstehend.',
    'Secret' => 'Geheimnis',
    'Shown once by Seatmap when the key was created. Leave the masked value alone to keep the current secret.' => 'Von Seatmap einmal bei der Erstellung des Schlüssels gezeigt. Lassen Sie den maskierten Wert stehen, um das aktuelle Geheimnis zu behalten.',
    'Standing' => 'Stehplatz',
    'Test connection' => 'Verbindung testen',
    'Testing…' => 'Wird getestet …',
    'The API URL must use HTTPS. Requests carry your API credentials.' => 'Die API-URL muss HTTPS verwenden. Die Anfragen tragen Ihre API-Zugangsdaten.',
    'The seating plan for this event is not published yet.' => 'Der Saalplan für diese Veranstaltung ist noch nicht veröffentlicht.',
    'The seating service returned status %d.' => 'Der Sitzplatzdienst hat Status %d zurückgegeben.',
    'The test request itself failed.' => 'Die Testanfrage selbst ist fehlgeschlagen.',
    'This event could not be loaded.' => 'Diese Veranstaltung konnte nicht geladen werden.',
    'This server\'s clock is out of step with the API. Fix NTP on this host.' => 'Die Uhr dieses Servers weicht vom API ab. Bringen Sie NTP auf diesem Host in Ordnung.',
    'This store is not finished setting up seat sales yet.' => 'Dieser Shop ist mit der Einrichtung des Platzverkaufs noch nicht fertig.',
    'Waiting to reach the seating service. This retries automatically every five minutes.' => 'Wartet auf den Sitzplatzdienst. Das wird alle fünf Minuten automatisch wiederholt.',
    'Without the /v1 suffix.' => 'Ohne das Suffix /v1.',
    'You do not have permission to manage these settings.' => 'Sie haben keine Berechtigung, diese Einstellungen zu verwalten.',
    'Your seat reservation ran out and the seats were released. Please choose your seats again.' => 'Ihre Platzreservierung ist abgelaufen und die Plätze wurden freigegeben. Bitte wählen Sie erneut.',
    'Your seats could not be added to your cart.' => 'Ihre Plätze konnten nicht in den Warenkorb gelegt werden.',
];
