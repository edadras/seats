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
    '%1$d place in %2$s' => '%1$d place in %2$s|%1$d places in %2$s',
    '%1$s, row %2$s, seat %3$s' => '%1$s, row %2$s, seat %3$s',
    'A simple, virtual product used as the cart line for a seat. Its own price is ignored — the price always comes from the signed Seatmap response.' => 'A simple, virtual product used as the cart line for a seat. Its own price is ignored — the price always comes from the signed Seatmap response.',
    'API URL' => 'API URL',
    'Check the key ID and secret, and that the key has not been revoked.' => 'Check the key ID and secret, and that the key has not been revoked.',
    'Checks the credentials and the clock. Signed requests are rejected if this server\'s time is more than five minutes from the API\'s.' => 'Checks the credentials and the clock. Signed requests are rejected if this server\'s time is more than five minutes from the API\'s.',
    'Choose at least one seat or place.' => 'Choose at least one seat or place.',
    'Choosing seats needs JavaScript. Please enable it, or contact the box office.' => 'Choosing seats needs JavaScript. Please enable it, or contact the box office.',
    'Connect this store to your Seatmap account. Seats, prices and availability come from Seatmap; the cart, payment and refunds stay here in WooCommerce.' => 'Connect this store to your Seatmap account. Seats, prices and availability come from Seatmap; the cart, payment and refunds stay here in WooCommerce.',
    'Connected. Credentials and clock are good.' => 'Connected. Credentials and clock are good.',
    'Connected.' => 'Connected.',
    'Connection' => 'Connection',
    'Copy this from the event in your Seatmap panel. It looks like evt_xxxxxxxx.' => 'Copy this from the event in your Seatmap panel. It looks like evt_xxxxxxxx.',
    'Customers will pick their seats here.' => 'Customers will pick their seats here.',
    'Enter the event ID to show its seating plan.' => 'Enter the event ID to show its seating plan.',
    'Event public ID' => 'Event public ID',
    'Every five minutes (Seatmap)' => 'Every five minutes (Seatmap)',
    'Fill in the API URL, key ID and secret first.' => 'Fill in the API URL, key ID and secret first.',
    'Key ID' => 'Key ID',
    'Let customers pick their seats for a Seatmap event.' => 'Let customers pick their seats for a Seatmap event.',
    'No event was selected for this seat map.' => 'No event was selected for this seat map.',
    'Not permitted.' => 'Not permitted.',
    'Reserve and add to cart' => 'Reserve and add to cart',
    'Seat booking is not available right now.' => 'Seat booking is not available right now.',
    'Seat map' => 'Seat map',
    'Seat product' => 'Seat product',
    'Seat' => 'Seat',
    'Seatmap %1$s failed: %2$s' => 'Seatmap %1$s failed: %2$s',
    'Seatmap Connect is not configured yet.' => 'Seatmap Connect is not configured yet.',
    'Seatmap Connect needs WooCommerce to be installed and active.' => 'Seatmap Connect needs WooCommerce to be installed and active.',
    'Seatmap Connect' => 'Seatmap Connect',
    'Seatmap' => 'Seatmap',
    'Seatmap: could not confirm the seats yet. This will be retried automatically.' => 'Seatmap: could not confirm the seats yet. This will be retried automatically.',
    'Seatmap: reconciliation found this order already confirmed.' => 'Seatmap: reconciliation found this order already confirmed.',
    'Seatmap: seats allocated and tickets issued.' => 'Seatmap: seats allocated and tickets issued.',
    'Seats allocated and tickets issued.' => 'Seats allocated and tickets issued.',
    'Seats are held, awaiting payment.' => 'Seats are held, awaiting payment.',
    'Secret' => 'Secret',
    'Shown once by Seatmap when the key was created. Leave the masked value alone to keep the current secret.' => 'Shown once by Seatmap when the key was created. Leave the masked value alone to keep the current secret.',
    'Standing' => 'Standing',
    'Test connection' => 'Test connection',
    'Testing…' => 'Testing…',
    'The API URL must use HTTPS. Requests carry your API credentials.' => 'The API URL must use HTTPS. Requests carry your API credentials.',
    'The seating plan for this event is not published yet.' => 'The seating plan for this event is not published yet.',
    'The seating service returned status %d.' => 'The seating service returned status %d.',
    'The test request itself failed.' => 'The test request itself failed.',
    'This event could not be loaded.' => 'This event could not be loaded.',
    'This server\'s clock is out of step with the API. Fix NTP on this host.' => 'This server\'s clock is out of step with the API. Fix NTP on this host.',
    'This store is not finished setting up seat sales yet.' => 'This store is not finished setting up seat sales yet.',
    'Waiting to reach the seating service. This retries automatically every five minutes.' => 'Waiting to reach the seating service. This retries automatically every five minutes.',
    'Without the /v1 suffix.' => 'Without the /v1 suffix.',
    'You do not have permission to manage these settings.' => 'You do not have permission to manage these settings.',
    'Your seat reservation ran out and the seats were released. Please choose your seats again.' => 'Your seat reservation ran out and the seats were released. Please choose your seats again.',
    'Your seats could not be added to your cart.' => 'Your seats could not be added to your cart.',
];
