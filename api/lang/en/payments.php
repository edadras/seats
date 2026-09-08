<?php

/**
 * What a buyer reads about each way of paying.
 *
 * A gateway's own name is not translated — Zarinpal is Zarinpal everywhere — but the sentence
 * explaining what choosing it means is, because that sentence is the one that decides whether
 * somebody is comfortable clicking it.
 */
return [
    'offline' => [
        'label' => 'Pay at the box office',
        'description' => 'Your seats are reserved now. Pay when you collect your tickets.',
    ],
];
