<?php

/**
 * The platform's own console — the screens the people who run this read.
 *
 * This catalogue exists because the earlier decision not to translate the console was wrong. The
 * reasoning at the time was that a handful of operators read it, and they can read English. That
 * holds only for as long as the platform is run by one office: support staff, resellers and the
 * on-call operator of a self-hosted deployment are not required to be English speakers, and a
 * console is a poor place to guess.
 *
 * It is delivered differently from `panel`: the console page renders its own catalogue into the
 * document rather than fetching `/v1/i18n`. That endpoint is public and serves every panel visitor,
 * and there is no reason for an organiser's browser to download the words of a screen they may not
 * open.
 *
 * The words for *what an operator did* — the audit actions — are not here. They are stable
 * identifiers written into a log that is read back years later, and translating them at write time
 * would make a log that no longer means one thing.
 */
return [
    'brand' => 'Console',
    'title' => 'Platform console',
    'subtitle' => 'For the people who run this, not the people who use it.',
    'signIn' => 'Sign in',
    'email' => 'Email',
    'password' => 'Password',
    'platform' => 'Platform',
    'signOut' => 'Sign out',
    'sections' => 'Sections',
    'language' => 'Language',
    'failed' => 'Request failed',
    'save' => 'Save',
    'none' => '—',
    'loading' => 'Loading…',

    'levels' => [
        'support' => 'Support',
        'operator' => 'Operator',
    ],

    'nav' => [
        'overview' => 'Overview',
        'tenants' => 'Organisers',
        'sites' => 'Websites',
        'plans' => 'Plans',
        'audit' => 'Operator log',
    ],

    'status' => [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'cancelled' => 'Cancelled',
        'live' => 'Live',
        'draft' => 'Draft',
        'recorded' => 'Recorded',
        'paid' => 'Paid',
        'void' => 'Voided',
    ],

    'overview' => [
        'description' => 'The platform, in numbers.',
        'tenants' => 'Organisers',
        'tenantsMeta' => ':active active · :suspended suspended',
        'newThisMonth' => 'New this month',
        'sitesLive' => 'Websites live',
        'sitesMeta' => ':count in total',
        'domains' => 'Verified domains',
        'events' => 'Events on sale',
        'tickets' => 'Tickets issued',
        'ticketsMeta' => ':count seats this month',
        'takings' => 'Takings, by currency',
        'currency' => 'Currency',
        'orders' => 'Orders',
        'takenThisMonth' => 'Taken this month',
        'nothingSold' => 'Nothing has been sold this month.',
    ],

    'tenants' => [
        'description' => 'Every account on the platform.',
        'name' => 'Name',
        'status' => 'Status',
        'plan' => 'Plan',
        'people' => 'People',
        'sites' => 'Sites',
        'since' => 'Since',
        'open' => 'Open',
        'back' => 'All organisers',
        'noPlan' => 'no plan',
        'summary' => ':slug · :plan · :events events · :tickets tickets issued',
        'peopleHeading' => 'People',
        'sitesHeading' => 'Websites',
        'email' => 'Email',
        'role' => 'Role',
        'siteName' => 'Name',
        'addresses' => 'Addresses',
        'impersonate' => 'Open their panel',
        'suspend' => 'Suspend',
        'reinstate' => 'Reinstate',
        'whySuspend' => 'Why is this account being suspended?',
    ],

    'sites' => [
        'description' => 'Every site this platform serves.',
        'site' => 'Site',
        'tenant' => 'Organiser',
        'status' => 'Status',
        'theme' => 'Theme',
        'addresses' => 'Addresses',
        'unverified' => '(unverified)',
        'noAddress' => 'no address yet',
    ],

    'plans' => [
        'description' => 'What an account costs, and what it may do.',
        'plan' => 'Plan',
        'price' => 'Price',
        'commission' => 'Commission',
        'commissionAt' => ':rate%',
        'commissionField' => 'Commission, in basis points',
        'commissionHint' => '250 is 2.5% of what an organiser keeps, before tax. Zero takes nothing.',
        'limits' => 'Limits',
        'subscribers' => 'On it',
        'status' => 'Status',
        'edit' => 'Edit',
        'new' => 'New plan',
        'free' => 'Free',
        'noLimits' => 'No limits',
        'separator' => ' · ',
        'unlimited' => '∞',
        'perMonth' => ':price / month',
        'perYear' => ':price / year',
        'back' => 'All plans',
        'editHint' => 'Changing a price changes what people pay at their next renewal.',
        'key' => 'Key',
        'keyHint' => 'Lower case and dashes. Cannot be changed later — a subscription points at it.',
        'name' => 'Name',
        'priceField' => 'Price, in minor units',
        'priceHint' => '4900 is €49.00. Zero is free.',
        'currency' => 'Currency',
        'billed' => 'Billed',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'limitField' => ':limit limit',
        'limitHint' => 'Blank means no limit.',
        'onSignup' => 'On the signup screen',
        'limitNames' => [
            'max_venues' => 'Venues',
            'max_events' => 'Events',
            'max_seats_per_map' => 'Seats per map',
        ],
    ],

    'audit' => [
        'description' => 'What the people who run this platform did inside other people’s accounts.',
        'when' => 'When',
        'action' => 'Action',
        'operator' => 'Operator',
        'tenant' => 'Organiser',
        'detail' => 'Detail',
        'from' => 'From',
    ],

    'payouts' => [
        'heading' => 'Payouts',
        'period' => 'Period',
        'range' => ':from to :to',
        'currency' => 'Currency',
        'charged' => 'Charged',
        'refunded' => 'Refunded',
        'commission' => 'Commission',
        'payable' => 'Payable',
        'reference' => 'Reference',
        'none' => 'Nothing has been settled for this organiser yet.',
        'from' => 'From',
        'to' => 'To',
        'preview' => 'Work it out',
        'settle' => 'Settle this period',
        'markPaid' => 'Mark as paid',
        'void' => 'Void',
        'askReference' => 'Bank reference for this payout (leave blank if there is none yet):',
        'whyVoid' => 'Why is this payout being voided? The period becomes free again.',
        'clash' => 'Those days overlap a payout that already exists: :periods. Void it, or choose different dates.',
        'nothing' => 'Nothing was taken in that period, so there is nothing to settle.',
    ],

];
