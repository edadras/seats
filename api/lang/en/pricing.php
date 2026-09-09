<?php

/**
 * The pricing screen.
 *
 * The currency belongs to the event, because a company that tours plays Tehran in rials and Berlin
 * in euros. How it is *written* belongs to whoever is reading it (ADR-0005 §5).
 */
return [
    'title' => 'Prices',
    'subtitle' => 'What :event charges, and in what.',
    'back' => 'Back to events',
    'save' => 'Save prices',
    'currency' => 'Currency',
    'currencyHint' => 'The event charges in this. What each member of staff sees it written as follows their own language.',
    'zone' => 'Area',
    'amount' => 'Price',
    'notOnMap' => 'No longer on the map',
    'noZones' => 'This map has no categories yet',
    'noZonesHint' => 'Give the seat map some categories and they appear here to price.',
    'needOne' => 'Set at least one price before saving.',
    'saved' => 'Prices saved.',
    'savedWithGaps' => 'Prices saved. :count areas on the map still have no price — nobody can book those seats.',
    'openPrices' => 'Prices',
    'prices' => 'Prices',
    'unpriced' => 'Not priced',

    'seats' => [
        'open' => 'Individual seats',
        'title' => 'Individual seats',
        'subtitle' => 'A section is a name, not a price. Any seat can carry its own.',
        'backToBlocks' => 'All sections',
        'noSections' => 'This chart has no seated sections',
        'noSectionsHint' => 'Standing areas are priced by zone, on the previous screen.',
        'seatCount' => ':count seats',
        'ownPrices' => ':count priced on their own',
        'blockedCount' => ':count blocked',
        'selected' => ':count selected',
        'selectRow' => 'Select the whole row',
        'selectSection' => 'Select the whole section',
        'clearSelection' => 'Clear',
        'amount' => 'Price',
        'apply' => 'Set this price',
        'useZone' => 'Move to a zone',
        'block' => 'Block',
        'unblock' => 'Unblock',
        'reset' => 'Back to the chart’s price',
        'save' => 'Save',
        'saveCount' => 'Save :count seats',
        'saved' => ':count seats changed.',
        'nothingToSave' => 'Nothing has changed yet.',
        'needSelection' => 'Choose some seats first.',
        'needAmount' => 'Type a price first.',
        'discard' => 'Leave without saving the seats you changed?',
        'soldWarning' => ':count of those seats are already sold. Those tickets keep the price they were sold at; this is what the next buyer pays.',
        'legendOwn' => 'Its own price',
        'legendBlocked' => 'Blocked',
        'legendSold' => 'Sold',
        'unpriced' => 'No price',
    ],
];
