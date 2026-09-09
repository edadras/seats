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
    '%1$d place in %2$s' => '%1$d place dans %2$s|%1$d places dans %2$s',
    '%1$s, row %2$s, seat %3$s' => '%1$s, rang %2$s, place %3$s',
    'A simple, virtual product used as the cart line for a seat. Its own price is ignored — the price always comes from the signed Seatmap response.' => 'Un produit virtuel simple, utilisé comme ligne de panier pour une place. Son propre prix est ignoré : le prix vient toujours de la réponse signée de Seatmap.',
    'API URL' => 'URL de l’API',
    'Check the key ID and secret, and that the key has not been revoked.' => 'Vérifiez l’identifiant de clé et le secret, et que la clé n’a pas été révoquée.',
    'Checks the credentials and the clock. Signed requests are rejected if this server\'s time is more than five minutes from the API\'s.' => 'Vérifie les identifiants et l’horloge. Les requêtes signées sont rejetées si l’heure de ce serveur s’écarte de plus de cinq minutes de celle de l’API.',
    'Choose at least one seat or place.' => 'Choisissez au moins une place.',
    'Choosing seats needs JavaScript. Please enable it, or contact the box office.' => 'Le choix des places nécessite JavaScript. Activez-le, ou contactez la billetterie.',
    'Connect this store to your Seatmap account. Seats, prices and availability come from Seatmap; the cart, payment and refunds stay here in WooCommerce.' => 'Reliez cette boutique à votre compte Seatmap. Les places, les prix et la disponibilité viennent de Seatmap ; le panier, le paiement et les remboursements restent ici, dans WooCommerce.',
    'Connected. Credentials and clock are good.' => 'Connecté. Identifiants et horloge sont bons.',
    'Connected.' => 'Connecté.',
    'Connection' => 'Connexion',
    'Copy this from the event in your Seatmap panel. It looks like evt_xxxxxxxx.' => 'Copiez-le depuis l’événement dans votre panneau Seatmap. Il ressemble à evt_xxxxxxxx.',
    'Customers will pick their seats here.' => 'C’est ici que vos clients choisiront leurs places.',
    'Enter the event ID to show its seating plan.' => 'Saisissez l’identifiant de l’événement pour afficher son plan de salle.',
    'Event public ID' => 'Identifiant public de l’événement',
    'Every five minutes (Seatmap)' => 'Toutes les cinq minutes (Seatmap)',
    'Fill in the API URL, key ID and secret first.' => 'Renseignez d’abord l’URL de l’API, l’identifiant de clé et le secret.',
    'Key ID' => 'Identifiant de clé',
    'Let customers pick their seats for a Seatmap event.' => 'Laissez vos clients choisir leurs places pour un événement Seatmap.',
    'No event was selected for this seat map.' => 'Aucun événement n’a été choisi pour ce plan de salle.',
    'Not permitted.' => 'Non autorisé.',
    'Reserve and add to cart' => 'Réserver et ajouter au panier',
    'Seat booking is not available right now.' => 'La réservation de places n’est pas disponible pour le moment.',
    'Seat map' => 'Plan de salle',
    'Seat product' => 'Produit « place »',
    'Seat' => 'Place',
    'Seatmap %1$s failed: %2$s' => 'Seatmap %1$s a échoué : %2$s',
    'Seatmap Connect is not configured yet.' => 'Seatmap Connect n’est pas encore configuré.',
    'Seatmap Connect needs WooCommerce to be installed and active.' => 'Seatmap Connect a besoin que WooCommerce soit installé et actif.',
    'Seatmap Connect' => 'Seatmap Connect',
    'Seatmap' => 'Seatmap',
    'Seatmap: could not confirm the seats yet. This will be retried automatically.' => 'Seatmap : les places n’ont pas encore pu être confirmées. L’opération sera réessayée automatiquement.',
    'Seatmap: reconciliation found this order already confirmed.' => 'Seatmap : le rapprochement a trouvé cette commande déjà confirmée.',
    'Seatmap: seats allocated and tickets issued.' => 'Seatmap : places attribuées et billets émis.',
    'Seats allocated and tickets issued.' => 'Places attribuées et billets émis.',
    'Seats are held, awaiting payment.' => 'Places réservées, en attente de paiement.',
    'Secret' => 'Secret',
    'Shown once by Seatmap when the key was created. Leave the masked value alone to keep the current secret.' => 'Affiché une seule fois par Seatmap à la création de la clé. Laissez la valeur masquée telle quelle pour conserver le secret actuel.',
    'Standing' => 'Debout',
    'Test connection' => 'Tester la connexion',
    'Testing…' => 'Test en cours…',
    'The API URL must use HTTPS. Requests carry your API credentials.' => 'L’URL de l’API doit utiliser HTTPS. Les requêtes transportent vos identifiants d’API.',
    'The seating plan for this event is not published yet.' => 'Le plan de salle de cet événement n’est pas encore publié.',
    'The seating service returned status %d.' => 'Le service de placement a renvoyé le statut %d.',
    'The test request itself failed.' => 'La requête de test elle-même a échoué.',
    'This event could not be loaded.' => 'Cet événement n’a pas pu être chargé.',
    'This server\'s clock is out of step with the API. Fix NTP on this host.' => 'L’horloge de ce serveur est décalée par rapport à l’API. Corrigez NTP sur cet hôte.',
    'This store is not finished setting up seat sales yet.' => 'Cette boutique n’a pas fini de configurer la vente de places.',
    'Waiting to reach the seating service. This retries automatically every five minutes.' => 'En attente du service de placement. Nouvelle tentative automatique toutes les cinq minutes.',
    'Without the /v1 suffix.' => 'Sans le suffixe /v1.',
    'You do not have permission to manage these settings.' => 'Vous n’avez pas la permission de gérer ces réglages.',
    'Your seat reservation ran out and the seats were released. Please choose your seats again.' => 'Votre réservation de places a expiré et les places ont été libérées. Choisissez-les à nouveau.',
    'Your seats could not be added to your cart.' => 'Vos places n’ont pas pu être ajoutées au panier.',
];
