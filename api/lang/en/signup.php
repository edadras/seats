<?php

/**
 * Signing yourself up.
 *
 * The verification email is here rather than in a Blade template because it is four lines long and
 * says one thing. Its placeholders are Laravel's `:name` — unlike the messaging templates, this is
 * not a string an organiser edits.
 */
return [
    'title' => 'Create an account',
    'subtitle' => 'A venue, a website and a box office. Nothing to install.',
    'name' => 'Your name',
    'organisation' => 'Venue or company',
    'email' => [
        'subject' => 'Your Seatmap verification code',
        'body' => "Hello :name,\n\nYour verification code is :code.\n\nIt is good for a day. If you did not create an account, ignore this message — nothing will happen without the code.",
    ],
    'password' => 'Password',
    'passwordHint' => 'At least twelve characters. This protects a box office.',
    'plan' => 'Plan',
    'create' => 'Create the account',
    'haveAccount' => 'Already have an account? Sign in',
    'newAccount' => 'Create an account',
    'creating' => 'Setting things up…',
    'welcome' => 'Welcome. Your website is ready to edit.',
    'verifyTitle' => 'Check your email',
    'verifyBody' => 'We sent a six-digit code to :email. Type it here — you can carry on setting things up in the meantime.',
    'code' => 'Code',
    'verify' => 'Verify',
    'verified' => 'Email verified. Thank you.',
    'resend' => 'Send it again',
    'resent' => 'Sent. It can take a minute to arrive.',
    'unverifiedNotice' => 'Verify your email address to put a website on the internet.',
    'free' => 'Free',
    'perMonth' => 'a month',
    'perYear' => 'a year',
    'limits' => [
        'events' => ':count events',
        'seats' => ':count seats a map',
        'sites' => ':count websites',
        'unlimited' => 'Unlimited',
    ],
];
