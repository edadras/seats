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
];
