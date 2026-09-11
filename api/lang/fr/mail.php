<?php

/**
 * E-mail des billets.
 *
 * C'est le seul message qui doit survivre à une lecture dans le bus, sur un téléphone, six semaines
 * après son arrivée — il indique donc l'événement, la date et les places, et rien qui ait besoin du
 * réseau pour s'afficher.
 */
return [
    'title' => 'Vos billets',
    'subject' => 'Vos billets pour :event',
    'intro' => 'Merci. Présentez l’un des codes ci-dessous à l’entrée — un par place. Ils fonctionnent depuis cet e-mail comme depuis votre page de réservation.',
    'standing' => 'Debout',
    'keepThis' => 'Référence de réservation :reference. Conservez cet e-mail — quiconque détient un code peut entrer avec.',
    'sender' => [
        'subject' => 'Confirmez cette adresse pour :venue',
        'body' => "Quelqu’un chez :venue souhaite que les réponses aux e-mails de billets arrivent à cette adresse.\n\nSaisissez ce code dans l’écran Messages pour la confirmer :\n\n:code\n\nLe code est valable une journée. Si ce n’était pas vous, ignorez cet e-mail : rien ne change tant que le code n’est pas saisi.",
    ],
];
