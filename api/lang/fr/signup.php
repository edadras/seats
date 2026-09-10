<?php

/**
 * Signing yourself up.
 *
 * The verification email is here rather than in a Blade template because it is four lines long and
 * says one thing. Its placeholders are Laravel's `:name` — unlike the messaging templates, this is
 * not a string an organiser edits.
 */
return [
    'title' => 'Créer un compte',
    'subtitle' => 'Une salle, un site et une billetterie. Rien à installer.',
    'name' => 'Votre nom',
    'organisation' => 'Lieu ou société',
    'email' => [
        'subject' => 'Votre code de vérification Seatmap',
        'body' => "Bonjour :name,\n\nVotre code de vérification est :code.\n\nIl est valable une journée. Si vous n’avez pas créé de compte, ignorez ce message — rien ne se passe sans le code.",
    ],
    'password' => 'Mot de passe',
    'passwordHint' => 'Douze caractères au moins. Il protège une billetterie.',
    'plan' => 'Formule',
    'create' => 'Créer le compte',
    'haveAccount' => 'Déjà un compte ? Se connecter',
    'newAccount' => 'Créer un compte',
    'creating' => 'Installation en cours…',
    'welcome' => 'Bienvenue. Votre site est prêt à être modifié.',
    'verifyTitle' => 'Regardez votre boîte mail',
    'verifyBody' => 'Nous avons envoyé un code à six chiffres à :email. Saisissez-le ici — vous pouvez continuer entre-temps.',
    'code' => 'Code',
    'verify' => 'Valider',
    'verified' => 'E-mail validé. Merci.',
    'resend' => 'Renvoyer',
    'resent' => 'Envoyé. Cela peut prendre une minute.',
    'free' => 'Gratuit',
    'perMonth' => 'par mois',
    'perYear' => 'par an',
    'limits' => [
        'events' => ':count événements',
        'seats' => ':count places par plan',
        'sites' => ':count sites',
        'unlimited' => 'Illimité',
    ],
];
