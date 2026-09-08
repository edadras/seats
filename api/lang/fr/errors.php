<?php

/**
 * Chaque message que l'API renvoie lorsqu'elle refuse.
 *
 * Le `code` qui accompagne ces messages n'est pas traduit et ne le sera jamais : les programmes
 * lisent le code, les gens lisent le message.
 */
return [
    // --- Connexion et autorisation ----------------------------------------------------------
    'unauthenticated' => 'Vous devez vous connecter.',
    'invalid_credentials' => 'Cet e-mail et ce mot de passe ne correspondent pas.',
    'forbidden' => 'Votre rôle ne permet pas de modifier ce compte.',
    'forbidden_permission' => 'Vous n’avez pas la permission :permission.',
    'tenant_suspended' => 'Ce compte est suspendu. Merci de nous contacter.',
    'not_found' => 'Introuvable.',

    // --- Signature, rejeu et idempotence ----------------------------------------------------
    'invalid_signature' => 'La signature de la requête ne correspond pas.',
    'signature_expired' => 'L’horodatage est hors de la fenêtre autorisée. Vérifiez l’horloge du serveur appelant.',
    'nonce_reused' => 'Cette requête a déjà été envoyée.',
    'idempotency_key_reuse' => 'Cette clé d’idempotence a déjà servi pour un autre contenu de requête.',
    'rate_limited' => 'Trop de requêtes. Réessayez dans un instant.',

    // --- Places, réservations et capacité ---------------------------------------------------
    'seat_unavailable' => 'L’une de ces places n’est plus disponible.',
    'capacity_unavailable' => 'Il ne reste pas autant de places.',
    'hold_expired' => 'Votre réservation a expiré. Choisissez à nouveau vos places.',
    'hold_not_found' => 'Cette réservation n’existe plus.',
    'too_many_seats' => 'Vous pouvez choisir jusqu’à :max places à la fois.',
    'too_many_holds' => 'Vous avez déjà autant de réservations qu’une session peut en tenir.',
    'seat_not_in_event' => 'Cette place ne fait pas partie de cet événement.',
    'event_not_on_sale' => 'Cet événement n’est pas en vente.',

    // --- Commandes --------------------------------------------------------------------------
    'order_not_found' => 'Cette commande n’existe pas.',
    'order_already_confirmed' => 'Cette commande a déjà été confirmée.',
    'order_cancelled' => 'Cette commande a été annulée.',
    'refund_exceeds_order' => 'Vous ne pouvez pas rembourser plus que le montant de la commande.',
    'payment_failed' => 'Le paiement n’a pas abouti. Rien n’a été débité.',
    'payment_verification_failed' => 'Nous n’avons pas pu confirmer ce paiement auprès de la passerelle. Si de l’argent a quitté votre compte, contactez-nous et nous le retrouverons.',

    // --- Plans de salle ---------------------------------------------------------------------
    'map_invalid' => 'Le plan de salle ne peut pas être publié tant que ses erreurs ne sont pas corrigées.',
    'map_published' => 'Un plan publié ne se modifie pas. Enregistrez plutôt une nouvelle version.',
    'map_in_use' => 'Ce plan est utilisé par un événement qui a déjà vendu des places.',
    'map_too_large' => 'Ce plan dépasse ce que votre formule autorise.',

    // --- Billets et contrôle ----------------------------------------------------------------
    'ticket_already_used' => 'Quelqu’un est déjà entré avec ce billet. Libérer la place maintenant reviendrait à vendre un siège occupé.',
    'ticket_no_allocation' => 'Ce billet n’est rattaché à aucune place.',
    'ticket_no_order' => 'Ce billet n’est rattaché à aucune commande.',
    'pairing_code_expired' => 'Ce code d’appairage a expiré. Demandez-en un nouveau.',
    'device_revoked' => 'Cet appareil n’est plus appairé.',

    // --- Sites et domaines ------------------------------------------------------------------
    'unknown_theme' => 'Ce thème n’existe pas.',
    'no_verified_domain' => 'Ajoutez et vérifiez un domaine avant de mettre le site en ligne — sinon il n’y a aucune adresse à visiter.',
    'slug_taken' => 'Une page utilise déjà cette adresse.',
    'home_slug_fixed' => 'La page d’accueil se trouve à la racine et ne peut pas être déplacée.',
    'page_required' => 'Les pages d’accueil et d’événement font partie du fonctionnement du site et ne peuvent pas être supprimées.',
    'too_many_pages' => 'Ce site contient autant de pages qu’il peut en contenir.',
    'too_many_domains' => 'Ce site a déjà autant d’adresses qu’il peut en avoir.',
    'hostname_taken' => 'Cette adresse est déjà utilisée.',
    'invalid_hostname' => 'Ce n’est pas un nom d’hôte que nous pouvons servir.',
    'domain_unverified' => 'Vérifiez l’adresse avant d’en faire l’adresse principale.',
    'primary_domain' => 'Faites d’abord d’une autre adresse l’adresse principale.',
    'site_unavailable' => 'Ce site n’est pas disponible.',
    'no_site_here' => 'Aucun site n’est publié à cette adresse.',

    // --- Formules et limites ----------------------------------------------------------------
    'tenant_limit_reached' => 'Votre formule autorise :limit :resource. Changez de formule pour aller plus loin.',
    'subscription_inactive' => 'Ce compte n’a pas d’abonnement actif.',

    // --- Général ----------------------------------------------------------------------------
    'validation_failed' => 'Le contenu de la requête est invalide.',
    'server_error' => 'Une erreur inattendue s’est produite.',
    'http_error' => 'La requête a échoué.',

    // --- Nommées d’après leur code d’erreur : le code est la clé, aucun appel n’en nomme une --
    'client_disabled' => 'Ce client d’API n’est pas actif.',
    'invalid_key' => 'La clé d’API est inconnue, expirée ou révoquée.',
    'invalid_nonce' => 'Le format du nonce n’est pas acceptable.',
    'invalid_pairing_code' => 'Ce code d’appairage est inconnu ou expiré.',
    'device_token_required' => 'Un jeton d’appareil de contrôle est requis.',
    'device_not_authorised' => 'Cet appareil n’est pas autorisé à scanner cet événement.',
    'no_membership' => 'Ce compte n’appartient à aucun organisateur.',
    'not_part_of_site' => 'Ne fait pas partie de ce site.',
    'unknown_event' => 'Événement inconnu.',
    'unknown_tenant' => 'Organisateur inconnu.',
];
