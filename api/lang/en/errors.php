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
    'unauthenticated' => 'You need to sign in.',
    'invalid_credentials' => 'That email and password do not match.',
    'forbidden' => 'Your role does not permit changes to this organiser.',
    'forbidden_permission' => 'You do not have permission to :permission.',
    'tenant_suspended' => 'This account is suspended. Please contact us.',
    'not_found' => 'Not found.',

    // --- Signing, replay and idempotency ----------------------------------------------------
    'invalid_signature' => 'The request signature does not match.',
    'signature_expired' => 'The request timestamp is outside the allowed window. Check the clock on the calling server.',
    'nonce_reused' => 'This request has already been made.',
    'idempotency_key_reuse' => 'That idempotency key was used for a different request body.',
    'rate_limited' => 'Too many requests. Try again in a moment.',

    // --- Seats, holds and capacity ----------------------------------------------------------
    'seat_unavailable' => 'One of those seats is no longer available.',
    'capacity_unavailable' => 'There are not that many places left.',
    'hold_expired' => 'Your reservation expired. Please choose your seats again.',
    'hold_not_found' => 'That reservation no longer exists.',
    'too_many_seats' => 'You can select up to :max seats at a time.',
    'too_many_holds' => 'You already have as many reservations as one session may hold.',
    'seat_not_in_event' => 'That seat is not part of this event.',
    'event_not_on_sale' => 'This event is not on sale.',

    // --- Orders -----------------------------------------------------------------------------
    'order_not_found' => 'That order does not exist.',
    'order_already_confirmed' => 'This order has already been confirmed.',
    'order_cancelled' => 'This order was cancelled.',
    'refund_exceeds_order' => 'You cannot refund more than the order is worth.',
    'payment_failed' => 'The payment did not go through. Nothing has been charged.',
    'payment_verification_failed' => 'We could not verify that payment with the gateway. If money left your account, contact us and we will find it.',

    // --- Seat maps --------------------------------------------------------------------------
    'map_invalid' => 'The seat map cannot be published until its errors are resolved.',
    'map_published' => 'A published map cannot be edited. Save a new version instead.',
    'map_in_use' => 'This map is in use by an event that has sold seats.',
    'map_too_large' => 'This map is larger than the plan allows.',

    // --- Tickets and check-in ---------------------------------------------------------------
    'ticket_already_used' => 'Someone has already come in on this ticket. Releasing the seat now would sell a place that is occupied.',
    'ticket_no_allocation' => 'This ticket is not attached to a seat.',
    'ticket_no_order' => 'This ticket is not attached to an order.',
    'pairing_code_expired' => 'That pairing code has expired. Ask for a new one.',
    'device_revoked' => 'This device is no longer paired.',

    // --- Sites and domains ------------------------------------------------------------------
    'unknown_theme' => 'That theme does not exist.',
    'no_verified_domain' => 'Add and verify a domain before putting the site live — otherwise there is no address to visit.',
    'slug_taken' => 'A page already uses that address.',
    'home_slug_fixed' => 'The home page lives at the root and cannot move.',
    'page_required' => 'The home and event pages are part of how the site works and cannot be removed.',
    'too_many_pages' => 'This site has as many pages as it can hold.',
    'too_many_domains' => 'This site already has as many addresses as it can hold.',
    'hostname_taken' => 'That address is already in use.',
    'invalid_hostname' => 'That is not a hostname we can serve.',
    'domain_unverified' => 'Verify the address before making it the main one.',
    'primary_domain' => 'Make another address the main one first.',
    'site_unavailable' => 'This site is not available.',
    'no_site_here' => 'No site is published at this address.',

    // --- Plans and limits -------------------------------------------------------------------
    'tenant_limit_reached' => 'Your plan allows :limit :resource. Upgrade to add more.',
    'subscription_inactive' => 'This account has no active subscription.',

    // --- Generic ----------------------------------------------------------------------------
    'validation_failed' => 'The request payload is invalid.',
    'server_error' => 'An unexpected error occurred.',
    'http_error' => 'Request failed.',

    // --- Named by their error code, so the code is the key and no call site names one -------
    'client_disabled' => 'This API client is not active.',
    'invalid_key' => 'API key is unknown, expired or revoked.',
    'invalid_nonce' => 'Nonce format is not acceptable.',
    'invalid_pairing_code' => 'This pairing code is unknown or expired.',
    'device_token_required' => 'A check-in device token is required.',
    'device_not_authorised' => 'This device is not authorised to scan that event.',
    'no_membership' => 'This account is not a member of any organiser.',
    'not_part_of_site' => 'Not part of this site.',
    'unknown_event' => 'Unknown event.',
    'unknown_tenant' => 'Unknown organiser.',

// --- Team, roles and invitations --------------------------------------------------------
    'member_suspended' => 'Your access to this account has been suspended.',
    'reserved_role' => 'That name belongs to a built-in role. Choose another.',
    'role_in_use' => 'Someone still has this role. Move them first.',
    'last_owner' => 'An account must keep at least one owner.',
    'cannot_change_own_role' => 'You cannot change your own role.',
    'invitation_invalid' => 'That invitation is not valid, or it has already been used.',
    'invitation_expired' => 'That invitation has expired. Ask for a new one.',
    'already_a_member' => 'That person is already part of this account.',
];
