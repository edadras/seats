<?php

/**
 * Email dei biglietti.
 *
 * È l'unico messaggio che deve reggere alla lettura in autobus, su un telefono, sei settimane dopo
 * essere arrivato — quindi dice l'evento, la data e i posti, e niente che abbia bisogno della rete
 * per comparire.
 */
return [
    'title' => 'I tuoi biglietti',
    'subject' => 'I tuoi biglietti per :event',
    'intro' => 'Grazie. Mostra all’ingresso uno dei codici qui sotto — uno per posto. Funzionano sia da questa email sia dalla tua pagina di prenotazione.',
    'standing' => 'In piedi',
    'keepThis' => 'Codice di prenotazione :reference. Conserva questa email — chiunque abbia un codice può entrare con quello.',
];
