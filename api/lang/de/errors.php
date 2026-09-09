<?php

/**
 * Jede Meldung, die die API ausgibt, wenn sie etwas ablehnt.
 *
 * Der `code` neben diesen Meldungen wird nicht übersetzt und wird es nie: Programme lesen den Code,
 * Menschen lesen die Meldung.
 */
return [
    // --- Anmeldung und Berechtigung ---------------------------------------------------------
    'unauthenticated' => 'Bitte melden Sie sich an.',
    'invalid_credentials' => 'E-Mail-Adresse und Passwort passen nicht zusammen.',
    'forbidden' => 'Ihre Rolle erlaubt keine Änderungen an diesem Konto.',
    'forbidden_permission' => 'Ihnen fehlt die Berechtigung :permission.',
    'tenant_suspended' => 'Dieses Konto ist gesperrt. Bitte wenden Sie sich an uns.',
    'not_found' => 'Nicht gefunden.',

    // --- Signatur, Wiedereinreichung und Idempotenz -----------------------------------------
    'invalid_signature' => 'Die Signatur der Anfrage stimmt nicht.',
    'signature_expired' => 'Der Zeitstempel liegt außerhalb des erlaubten Fensters. Prüfen Sie die Uhr des aufrufenden Servers.',
    'nonce_reused' => 'Diese Anfrage wurde bereits gestellt.',
    'idempotency_key_reuse' => 'Dieser Idempotenzschlüssel wurde bereits für einen anderen Anfrageinhalt verwendet.',
    'rate_limited' => 'Zu viele Anfragen. Bitte versuchen Sie es gleich noch einmal.',

    // --- Plätze, Reservierungen und Kapazität -----------------------------------------------
    'seat_unavailable' => 'Einer dieser Plätze ist nicht mehr verfügbar.',
    'capacity_unavailable' => 'So viele Plätze sind nicht mehr frei.',
    'hold_expired' => 'Ihre Reservierung ist abgelaufen. Bitte wählen Sie Ihre Plätze erneut.',
    'hold_not_found' => 'Diese Reservierung gibt es nicht mehr.',
    'too_many_seats' => 'Sie können höchstens :max Plätze auf einmal wählen.',
    'too_many_holds' => 'Sie haben bereits so viele Reservierungen, wie eine Sitzung halten darf.',
    'seat_not_in_event' => 'Dieser Platz gehört nicht zu dieser Veranstaltung.',
    'event_not_on_sale' => 'Diese Veranstaltung ist nicht im Verkauf.',

    // --- Bestellungen -----------------------------------------------------------------------
    'order_not_found' => 'Diese Bestellung gibt es nicht.',
    'order_already_confirmed' => 'Diese Bestellung wurde bereits bestätigt.',
    'order_cancelled' => 'Diese Bestellung wurde storniert.',
    'refund_exceeds_order' => 'Sie können nicht mehr erstatten, als die Bestellung wert ist.',
    'payment_failed' => 'Die Zahlung ist nicht zustande gekommen. Es wurde nichts abgebucht.',
    'discount_used_up' => 'Dieser Code wurde soeben zum letzten Mal eingelöst. Es wurde nichts abgebucht.',
    'entry_slot_required' => "Wählen Sie eine Einlasszeit, bevor Sie buchen.",
    'entry_slot_full' => "Diese Einlasszeit ist voll. Bitte wählen Sie eine andere.",
    'entry_slot_closed' => "Diese Einlasszeit wird nicht mehr angeboten.",
    'payment_verification_failed' => 'Wir konnten diese Zahlung beim Anbieter nicht bestätigen. Falls Geld abgebucht wurde, melden Sie sich – wir finden es.',

    // --- Saalpläne --------------------------------------------------------------------------
    'map_invalid' => 'Der Saalplan kann erst veröffentlicht werden, wenn seine Fehler behoben sind.',
    'map_published' => 'Ein veröffentlichter Plan wird nicht bearbeitet. Speichern Sie stattdessen eine neue Fassung.',
    'map_in_use' => 'Dieser Plan wird von einer Veranstaltung genutzt, die bereits Plätze verkauft hat.',
    'map_too_large' => 'Dieser Plan ist größer, als Ihr Tarif erlaubt.',

    // --- Tickets und Einlass ----------------------------------------------------------------
    'ticket_already_used' => 'Mit diesem Ticket ist bereits jemand hereingekommen. Den Platz jetzt freizugeben hieße, einen besetzten Platz zu verkaufen.',
    'ticket_no_allocation' => 'Dieses Ticket hängt an keinem Platz.',
    'ticket_no_order' => 'Dieses Ticket hängt an keiner Bestellung.',
    'pairing_code_expired' => 'Dieser Kopplungscode ist abgelaufen. Fordern Sie einen neuen an.',
    'device_revoked' => 'Dieses Gerät ist nicht mehr gekoppelt.',

    // --- Websites und Domains ---------------------------------------------------------------
    'unknown_theme' => 'Dieses Design gibt es nicht.',
    'no_verified_domain' => 'Fügen Sie eine Domain hinzu und bestätigen Sie sie, bevor Sie die Website live schalten – sonst gibt es keine Adresse zu besuchen.',
    'slug_taken' => 'Eine Seite benutzt diese Adresse bereits.',
    'home_slug_fixed' => 'Die Startseite liegt an der Wurzel und lässt sich nicht verschieben.',
    'page_required' => 'Start- und Veranstaltungsseite gehören zur Funktionsweise der Website und lassen sich nicht entfernen.',
    'too_many_pages' => 'Diese Website hat so viele Seiten, wie sie fassen kann.',
    'too_many_domains' => 'Diese Website hat bereits so viele Adressen, wie sie fassen kann.',
    'hostname_taken' => 'Diese Adresse ist bereits vergeben.',
    'invalid_hostname' => 'Das ist kein Hostname, den wir ausliefern können.',
    'domain_unverified' => 'Bestätigen Sie die Adresse, bevor Sie sie zur Hauptadresse machen.',
    'primary_domain' => 'Machen Sie zuerst eine andere Adresse zur Hauptadresse.',
    'site_unavailable' => 'Diese Website ist nicht verfügbar.',
    'no_site_here' => 'Unter dieser Adresse ist keine Website veröffentlicht.',

    // --- Tarife und Grenzen -----------------------------------------------------------------
    'tenant_limit_reached' => 'Ihr Tarif erlaubt :limit :resource. Für mehr wechseln Sie den Tarif.',
    'subscription_inactive' => 'Für dieses Konto besteht kein aktives Abonnement.',

    // --- Allgemein --------------------------------------------------------------------------
    'validation_failed' => 'Der Inhalt der Anfrage ist ungültig.',
    'server_error' => 'Es ist ein unerwarteter Fehler aufgetreten.',
    'http_error' => 'Die Anfrage ist fehlgeschlagen.',

    // --- Nach ihrem Fehlercode benannt: der Code ist der Schlüssel, kein Aufrufer nennt einen -
    'client_disabled' => 'Dieser API-Client ist nicht aktiv.',
    'invalid_key' => 'Der API-Schlüssel ist unbekannt, abgelaufen oder widerrufen.',
    'invalid_nonce' => 'Das Format der Nonce ist nicht zulässig.',
    'invalid_pairing_code' => 'Dieser Kopplungscode ist unbekannt oder abgelaufen.',
    'device_token_required' => 'Es wird ein Token eines Einlassgeräts benötigt.',
    'device_not_authorised' => 'Dieses Gerät darf diese Veranstaltung nicht scannen.',
    'no_membership' => 'Dieses Konto gehört zu keinem Veranstalter.',
    'not_part_of_site' => 'Gehört nicht zu dieser Website.',
    'unknown_event' => 'Unbekannte Veranstaltung.',
    'unknown_tenant' => 'Unbekannter Veranstalter.',

// --- Team, Rollen und Einladungen -------------------------------------------------------
    'member_suspended' => 'Ihr Zugang zu diesem Konto wurde gesperrt.',
    'reserved_role' => 'Dieser Name gehört zu einer eingebauten Rolle. Wählen Sie einen anderen.',
    'role_in_use' => 'Jemand hat diese Rolle noch. Weisen Sie ihr zuerst eine andere zu.',
    'last_owner' => 'Ein Konto muss mindestens eine Inhaberin oder einen Inhaber behalten.',
    'cannot_change_own_role' => 'Sie können Ihre eigene Rolle nicht ändern.',
    'invitation_invalid' => 'Diese Einladung ist ungültig oder wurde bereits benutzt.',
    'invitation_expired' => 'Diese Einladung ist abgelaufen. Fordern Sie eine neue an.',
    'already_a_member' => 'Diese Person gehört bereits zu diesem Konto.',

    // --- Die Plattform-Konsole --------------------------------------------------------------
    'too_many_attempts' => 'Zu viele Versuche. Versuchen Sie es in :seconds Sekunden erneut.',
    'unknown_plan' => 'Diesen Tarif gibt es nicht.',
    'no_owner' => 'Für dieses Konto gibt es niemanden, in dessen Namen gehandelt werden könnte.',
    'support_may_not_change' => 'Support-Konten dürfen sehen, nicht ändern.',
    'plan_in_use' => 'Auf diesem Tarif sind Veranstalter. Deaktivieren Sie ihn stattdessen — das nimmt ihn von der Anmeldeseite und lässt die Bestehenden, wo sie sind.',
];
