<?php

/**
 * What the platform tells an organiser about, in their own language.
 *
 * The rows in `notifications` hold a kind and its facts, never a sentence: the wording is composed
 * when it is read, so a notice raised while one colleague was reading Persian is not read in
 * Persian by the German one who opens the panel afterwards (ADR-0005 §3).
 */

return [
    'kinds' => [
        'order_refunded' => [
            'title' => 'Order refunded',
            'body' => 'Booking :reference for :event was refunded — :seats seat(s).',
        ],
        'refund_requested' => [
            'title' => 'Refund asked for',
            'body' => 'Booking :reference for :event — the buyer asked for their money back. :reason',
        ],
        'event_sold_out' => [
            'title' => 'Sold out',
            'body' => ':event has sold every place: :seats in all.',
        ],
        'announcement_finished' => [
            'title' => 'Announcement sent',
            'body' => '“:subject” went to :sent of :total messages.',
        ],
        'message_refused' => [
            'title' => 'A message was refused',
            'body' => 'The :channel channel refused a message and will not retry it: :reason. Check its settings — a buyer may not have been told something.',
        ],
        'domain_verified' => [
            'title' => 'Address verified',
            'body' => ':hostname is verified and can serve :site.',
        ],
        'billing_invoiced' => [
            'title' => 'Invoice raised',
            'body' => 'Invoice :number is ready. It is due on :due.',
        ],
        'billing_payment_failed' => [
            'title' => 'A payment did not go through',
            'body' => 'Invoice :number could not be charged: :reason. It will be tried again.',
        ],
        'billing_past_due' => [
            'title' => 'This account is past due',
            'body' => 'Invoice :number has not been paid and the retries are finished: :reason. Settle it on the billing screen.',
        ],
    ],
];
