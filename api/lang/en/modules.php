<?php

/**
 * The modules screen, and the strings of every first-party module.
 *
 * First-party module strings live here rather than beside their code so that the same CI check
 * that guards the rest of the six languages guards them too. A third-party module registers its
 * own translation namespace — see docs/MODULES.md.
 */
return [
    'title' => 'Modules',
    'subtitle' => 'What this account can do beyond the basics. Switch one on, configure it, and it appears where it belongs.',
    'installed' => 'Available',
    'enabled' => 'On',
    'disabled' => 'Off',
    'enable' => 'Turn on',
    'disable' => 'Turn off',
    'configure' => 'Settings',
    'secretSet' => 'Set — replace to change',
    'secretUnset' => 'Not set',
    'byVendor' => 'by :vendor',
    'firstParty' => 'Built in',
    'health' => 'Health',
    'healthy' => 'Working',
    'failing' => ':count problems in the last day',
    'autoDisabled' => 'Switched off automatically after repeated failures: :reason',
    'lastFailure' => 'Last problem :when',
    'noFailures' => 'Nothing has gone wrong.',
    'extends' => [
        'payments' => 'Payments',
        'messaging' => 'Messaging',
        'reports' => 'Reports',
        'blocks' => 'Page blocks',
        'themes' => 'Themes',
        'panel' => 'Panel screens',
        'events' => 'Automations',
    ],
    'errors' => [
        'required' => 'This is needed before the module can be turned on.',
        'invalid' => 'That value is not one this setting accepts.',
        'not_installed' => 'That module is not installed on this server.',
        'not_configured' => 'Fill in what this module needs before turning it on.',
    ],
    'seatmap' => [
        'kavenegar' => [
            'name' => 'Kavenegar',
            'description' => 'Send SMS in Iran through Kavenegar.',
            'settings' => [
                'api_key' => 'API key',
                'api_key_hint' => 'From your Kavenegar panel. Write-only: stored encrypted and never shown again.',
                'sender' => 'Sender line',
                'sender_hint' => 'Your dedicated line. Blank uses the shared line on your account.',
            ],
        ],
        'sms_ir' => [
            'name' => 'SMS.ir',
            'description' => 'Send SMS in Iran through SMS.ir.',
            'settings' => [
                'api_key' => 'API key',
                'api_key_hint' => 'From your SMS.ir dashboard. Write-only: stored encrypted and never shown again.',
                'line_number' => 'Line number',
                'line_number_hint' => 'SMS.ir will not send without one, so it is required here rather than discovered at eight in the evening.',
            ],
        ],
        'twilio' => [
            'name' => 'Twilio',
            'description' => 'Send SMS outside Iran through Twilio.',
            'settings' => [
                'account_sid' => 'Account SID',
                'account_sid_hint' => 'Starts with AC, from your Twilio console.',
                'auth_token' => 'Auth token',
                'auth_token_hint' => 'Write-only: stored encrypted and never shown again.',
                'from' => 'From number',
                'from_hint' => 'A number or alphanumeric sender id Twilio has approved for you.',
            ],
        ],
        'telegram' => [
            'name' => 'Telegram',
            'description' => 'Message buyers through a Telegram bot you own.',
            'settings' => [
                'bot_token' => 'Bot token',
                'bot_token_hint' => 'From @BotFather. A bot can only message people who have started a conversation with it, so this reaches buyers who chose to be reachable.',
            ],
        ],
        'whatsapp' => [
            'name' => 'WhatsApp',
            'description' => 'Message buyers through the WhatsApp Cloud API. Free text only works within 24 hours of their last message; outside that Meta requires an approved template, and this sends plain text.',
            'settings' => [
                'access_token' => 'Access token',
                'access_token_hint' => 'From your Meta app. Write-only: stored encrypted and never shown again.',
                'phone_number_id' => 'Phone number ID',
                'phone_number_id_hint' => 'The number id from WhatsApp Manager — not the phone number itself.',
            ],
        ],
        'zarinpal' => [
            'name' => 'Zarinpal',
            'description' => 'Take Iranian bank cards through Zarinpal. Prices in rials or tomans — say which, because the difference is a factor of ten.',
            'settings' => [
                'merchant_id' => 'Merchant ID',
                'merchant_id_hint' => 'The UUID from your Zarinpal panel.',
                'amount_unit' => 'Your prices are in',
                'amount_unit_hint' => 'Zarinpal is paid in rials. If you price in tomans, choose tomans and we multiply.',
                'sandbox' => 'Use the sandbox',
                'sandbox_hint' => 'Test payments against Zarinpal’s sandbox. No money moves.',
            ],
        ],
        'idpay' => [
            'name' => 'IDPay',
            'description' => 'Take Iranian bank cards through IDPay, which sits in front of the bank PSPs.',
            'settings' => [
                'api_key' => 'API key',
                'api_key_hint' => 'From your IDPay dashboard, for this website.',
                'amount_unit' => 'Your prices are in',
                'amount_unit_hint' => 'IDPay is paid in rials. If you price in tomans, choose tomans and we multiply.',
                'sandbox' => 'Use the sandbox',
                'sandbox_hint' => 'Sends the sandbox header. No money moves.',
            ],
        ],
        'nextpay' => [
            'name' => 'NextPay',
            'description' => 'Take Iranian bank cards through NextPay.',
            'settings' => [
                'api_key' => 'API key',
                'api_key_hint' => 'From your NextPay panel.',
                'amount_unit' => 'Your prices are in',
                'amount_unit_hint' => 'NextPay is paid in rials. If you price in tomans, choose tomans and we multiply.',
            ],
        ],
        'stripe' => [
            'name' => 'Stripe',
            'description' => 'Take cards worldwide through Stripe Checkout. Card details go to Stripe, never to this server.',
            'settings' => [
                'secret_key' => 'Secret key',
                'secret_key_hint' => 'Starts with sk_live_ or sk_test_. Write-only: it is stored encrypted and never shown again.',
                'statement_descriptor' => 'On the buyer’s statement',
                'statement_descriptor_hint' => 'Up to 22 characters after your business name. Blank uses your Stripe default.',
            ],
        ],
        'paypal' => [
            'name' => 'PayPal',
            'description' => 'Take PayPal balances and cards through PayPal Orders.',
            'settings' => [
                'client_id' => 'Client ID',
                'client_id_hint' => 'From your PayPal app credentials.',
                'client_secret' => 'Client secret',
                'client_secret_hint' => 'Write-only: stored encrypted and never shown again.',
                'sandbox' => 'Use the sandbox',
                'sandbox_hint' => 'Talks to PayPal’s sandbox instead of the live API.',
            ],
        ],
        'offline_payments' => [
            'name' => 'Pay at the box office',
            'description' => 'Let buyers reserve online and pay when they arrive. Seats are held and tickets issued exactly as for a card sale; only the money is collected elsewhere.',
            'settings' => [
                'instructions' => 'What to tell the buyer',
                'instructions_hint' => 'Shown at checkout instead of the default wording. Say where and when they can pay.',
            ],
        ],
    ],
];
