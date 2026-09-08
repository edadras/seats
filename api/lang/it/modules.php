<?php

/**
 * La schermata dei moduli e i testi di ogni modulo integrato.
 *
 * I testi dei moduli integrati stanno qui e non accanto al loro codice, così lo stesso controllo CI
 * che tutela le altre sei lingue tutela anche questi. Un modulo di terze parti registra il proprio
 * spazio di traduzione — vedi docs/MODULES.md.
 */
return [
    'title' => 'Moduli',
    'subtitle' => 'Cosa sa fare questo account oltre alle basi. Attivane uno, configuralo, e compare dove serve.',
    'installed' => 'Disponibili',
    'enabled' => 'Attivo',
    'disabled' => 'Spento',
    'enable' => 'Attiva',
    'disable' => 'Disattiva',
    'configure' => 'Impostazioni',
    'secretSet' => 'Impostato — sostituiscilo per cambiarlo',
    'secretUnset' => 'Non impostato',
    'byVendor' => 'di :vendor',
    'firstParty' => 'Integrato',
    'health' => 'Stato',
    'healthy' => 'Funziona',
    'failing' => ':count problemi nell’ultimo giorno',
    'autoDisabled' => 'Disattivato in automatico dopo errori ripetuti: :reason',
    'lastFailure' => 'Ultimo problema :when',
    'noFailures' => 'Non è andato storto nulla.',
    'extends' => [
        'payments' => 'Pagamenti',
        'messaging' => 'Messaggi',
        'reports' => 'Report',
        'blocks' => 'Blocchi di pagina',
        'themes' => 'Temi',
        'panel' => 'Schermate del pannello',
        'events' => 'Automazioni',
    ],
    'errors' => [
        'required' => 'Serve prima di poter attivare il modulo.',
        'invalid' => 'Questa impostazione non accetta quel valore.',
        'not_installed' => 'Questo modulo non è installato su questo server.',
        'not_configured' => 'Compila ciò che serve al modulo prima di attivarlo.',
    ],
    'seatmap' => [
        'offline_payments' => [
            'name' => 'Paga al botteghino',
            'description' => 'Chi acquista prenota online e paga quando arriva. I posti vengono tenuti e i biglietti emessi esattamente come per una carta; solo l’incasso avviene altrove.',
            'settings' => [
                'instructions' => 'Cosa dire a chi acquista',
                'instructions_hint' => 'Mostrato al pagamento al posto del testo predefinito. Indica dove e quando si può pagare.',
            ],
        ],
    ],
];
