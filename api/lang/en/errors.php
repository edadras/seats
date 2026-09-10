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
    'challenge_expired' => 'That took too long. Please sign in again.',
    'invalid_code' => 'That code is not right. Try the next one.',
    'two_factor_off' => 'Two-step sign-in is not switched on.',
    'two_factor_required' => 'This account requires two-step sign-in, so it cannot be turned off.',
    'set_up_yours_first' => 'Set up two-step sign-in on your own account before requiring it of everybody.',
    'signin_not_configured' => 'This platform has not been given Google credentials, so buyers cannot sign in yet.',

    // --- Signing, replay and idempotency ----------------------------------------------------
    'invalid_signature' => 'The request signature does not match.',
    'nonce_reused' => 'This request has already been made.',
    'idempotency_key_reuse' => 'That idempotency key was used for a different request body.',
    'missing_credentials' => 'X-Seatmap-Key, X-Seatmap-Timestamp, X-Seatmap-Nonce and X-Seatmap-Signature are all required.',
    'stale_timestamp' => 'The request timestamp is outside the permitted window of :seconds seconds. Check the clock on the calling server.',
    'replay_check_unavailable' => 'Replay protection is temporarily unavailable. Try again shortly.',
    'invalid_idempotency_key' => 'That Idempotency-Key is too long.',
    'idempotency_key_in_flight' => 'A request with this Idempotency-Key is being processed. Try again shortly.',

    // --- Seats, holds and capacity ----------------------------------------------------------
    'seat_unavailable' => 'One of those seats is no longer available.',
    'no_seats_together' => "There is no run of that many seats side by side.",
    'capacity_unavailable' => 'There are not that many places left.',
    'hold_expired' => 'Your reservation expired. Please choose your seats again.',
    'hold_not_found' => 'That reservation no longer exists.',
    'too_many_seats' => 'You can select up to :max seats at a time.',
    'no_seats' => 'Choose at least one seat or place.',
    'unknown_seats' => 'One or more of those seats do not belong to this event.',
    'unknown_capacity_objects' => 'One or more of those areas do not belong to this event.',
    'seat_not_priced' => 'One or more of those seats have no price for this event and cannot be sold.',
    'area_not_priced' => 'One or more of those areas have no price for this event and cannot be sold.',
    'seats_not_named' => 'Those places cannot be handed back one at a time.',
    'event_not_sellable' => 'This event is not on sale.',
    'map_not_published' => 'Publish the seat map before putting this event on sale.',
    'too_many_active_holds' => 'This session is already holding seats in :count baskets. Finish or let go of one first.',
    'extend_limit_reached' => 'This reservation has already been held on :count times.',
    'hold_empty' => 'There are no seats left on this reservation.',
    'hold_missing' => 'This order has no reservation attached to it.',

    // --- Orders -----------------------------------------------------------------------------
    'order_not_found' => 'That order does not exist.',
    'payment_failed' => 'The payment did not go through. Nothing has been charged.',
    'discount_used_up' => 'That code has just been used for the last time. Nothing has been charged.',
    'entry_slot_required' => "Choose an arrival time before booking.",
    'entry_slot_full' => "That arrival time is full. Please pick another.",
    'entry_slot_closed' => "That arrival time is no longer offered.",
    'invalid_transition' => 'An order that is :status cannot be confirmed.',
    'order_not_confirmed' => 'There are no tickets to send yet.',
    'order_not_refundable' => 'Only a confirmed order can be refunded.',
    'nothing_to_refund' => 'There is nothing on this booking to hand back.',
    'nothing_to_send' => 'Every ticket on this booking has been used or voided.',
    'no_address' => 'This booking has no email address on it.',
    'no_site' => 'Tickets are sent from a website, and this account has none live.',
    'no_live_site' => 'The message links to a page on a live website, and this account has none yet.',
    'email_required' => 'An email address is needed to send the tickets.',
    'channel_required' => 'A buyer has to be told their booking was confirmed. Choose at least one way to tell them.',
    'entry_slot_not_offered' => 'This event does not sell timed entry.',
    'unknown_entry_slot' => 'There is no such arrival time.',
    'unknown_ticket_type' => 'This event does not sell ticket types.',
    'ticket_type_not_on_sale' => 'One of the ticket types chosen is not on sale.',
    'ticket_type_min' => 'At least :count :type tickets must be bought together.',
    'ticket_type_max' => 'At most :count :type tickets may be bought at once.',
    'discount_code_taken' => 'You already have a code with that name.',
    'discount_in_use' => 'This code has been used. Pause it instead — deleting it would leave those bookings unexplained.',

    // --- Seat maps --------------------------------------------------------------------------
    'invalid_geometry' => 'The seat map cannot be published until its errors are resolved.',
    'no_draft' => 'There is no draft version to publish.',
    'already_published' => 'This version is already published.',
    'unknown_zone' => 'A seat was put in a price zone this event does not have.',
    'venue_in_use' => 'This venue still has events. Delete or move them first.',

    // --- Tickets and check-in ---------------------------------------------------------------
    'ticket_already_used' => 'Someone has already come in on this ticket. Releasing the seat now would sell a place that is occupied.',
    'no_allocation' => 'This ticket is not attached to a seat.',
    'no_order' => 'This ticket is not attached to an order.',
    'device_revoked' => 'This device is no longer paired.',
    'already_used' => 'Somebody has already come in on this ticket. Letting the seat go now would sell a place that is occupied.',
    'no_tickets_to_add' => 'There are no live tickets on this booking to add.',
    'same_person' => 'That is the address the ticket is already sent to.',
    'ticket_type_sold' => 'A ticket type that has been sold cannot be removed. Hide it instead.',
    'entry_slot_sold' => 'An arrival time somebody has been sold cannot be removed. Close it instead.',
    'window_too_short' => 'That period is shorter than one arrival window.',
    'question_answered' => 'A question somebody has answered cannot be removed. Hide it instead.',

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
    'email_unverified' => 'Verify your email address before putting a site on the internet.',
    'wrong_theme' => 'That version belongs to another theme.',
    'theme_in_use' => 'Some sites still wear this theme.',

    // --- Plans and limits -------------------------------------------------------------------
    'plan_limit_reached' => 'Your plan allows :limit seats a map; this one has :count.',
    'no_plans' => 'Signups are closed at the moment.',
    'email_taken' => 'That email address already has an account. Sign in instead.',
    'code_expired' => 'That code has expired. Ask for a new one.',
    'code_wrong' => 'That code is not right.',
    'module_not_installed' => 'That module is not installed on this server.',
    'module_not_configured' => 'Fill in what this module needs before turning it on.',

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
    'already_a_member' => 'That person is already part of this account.',
    'role_exists' => 'A role already uses that name.',
    'unknown_role' => 'That role does not exist.',

    // --- The platform console ---------------------------------------------------------------
    'too_many_attempts' => 'Too many attempts. Try again in :seconds seconds.',
    'unknown_plan' => 'There is no such plan.',
    'no_owner' => 'That account has nobody to act as.',
    'support_may_not_change' => 'Support accounts can look, not change.',
    'plan_in_use' => 'Organisers are on this plan. Deactivate it instead — that takes it off the signup screen and leaves them where they are.',

    // --- Events that are called off or moved -------------------------------------------------
    'event_cancelled' => 'A cancelled event cannot be moved. Put it back on sale first.',
    'event_has_no_date' => 'This event has no date to move.',
    'confirm_with_the_name' => 'Type the event name exactly as it is written to confirm.',

    // --- Reports -----------------------------------------------------------------------------
    'unknown_source' => 'There is no such report source.',
    'unknown_filter_value' => 'That is not one of the choices for this filter.',
    'report_needs_a_measure' => 'A report has to count or add something up.',
    'report_too_slow' => 'That report took longer than :seconds seconds. Narrow it with a filter, or export it.',

    // --- Messages ----------------------------------------------------------------------------
    'unknown_message_kind' => 'There is no such message.',
    'unknown_channel' => 'That channel is not available.',

    // --- Wallet passes -----------------------------------------------------------------------
    'apple_wallet_not_set_up' => 'This account has no Apple Wallet certificate.',
    'apple_certificate_unreadable' => 'That certificate could not be read.',
    'apple_key_unreadable' => 'That private key could not be read — check the password.',
    'apple_signing_failed' => 'The pass could not be signed with that certificate.',
    'pass_not_written' => 'The pass could not be assembled.',
    'google_wallet_not_set_up' => 'This account has no Google Wallet issuer.',
    'google_service_account_unreadable' => 'That service account file could not be read.',
    'google_signing_failed' => 'The pass could not be signed with that service account.',

    // --- Presale and access codes -------------------------------------------------------------
    'access_code_required' => 'This event is in presale. A code is needed to book from it.',
    'unknown_code' => 'That code is not right.',
    'code_not_live' => 'That code is not working at the moment.',
    'code_used_up' => 'That code has been used as often as it can be.',
    'presale_not_open' => 'The presale has not opened yet.',
    'access_code_seat_limit' => 'That code is good for :count seats at a time.',
    'access_code_taken' => 'You already have a code with that name.',
    'access_code_in_use' => 'This code has let somebody in. Pause it instead — deleting it would leave those bookings unexplained.',
];
