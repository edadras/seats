<?php

/**
 * Strings the hosted site shows a buyer.
 *
 * The picker's own vocabulary lives under `picker` and is handed to it as one object, because the
 * picker is shared with the WordPress plugin and takes every string from whoever boots it.
 *
 * The placeholders are `%s` / `%d` / `%1$s` rather than Laravel's `:name`: the picker substitutes
 * them itself, in the browser, and it learned that shape from WordPress. Changing it would mean
 * two versions of the picker, which is the thing tools/sync-seat-picker.sh exists to prevent.
 */
return [
    'picker' => [
        'selectSeats' => 'Select your seats',
        'available' => 'Available',
        'unavailable' => 'Unavailable',
        'selected' => 'Selected',
        'yourSelection' => 'Your selection',
        'noneSelected' => 'No seats selected yet.',
        'total' => 'Total',
        'addToCart' => 'Reserve these seats',
        'working' => 'Reserving…',
        'seatTaken' => 'Sorry, one of those seats was just taken. It has been removed from your selection.',
        'genericError' => 'Something went wrong. Please try again.',
        'maxSeats' => 'You can select up to %d seats.',
        'seatLabel' => '%1$s, row %2$s, seat %3$s — %4$s',
        'seatUnavailable' => '%1$s, row %2$s, seat %3$s — unavailable',
        'zoomIn' => 'Zoom in',
        'zoomOut' => 'Zoom out',
        'resetView' => 'Reset view',
        'held' => 'Seats held until %s',
        'expired' => 'Your reservation expired. Please choose your seats again.',
        'stage' => 'Stage',
        'standingAreas' => 'Standing and tables',
        'placesLeft' => '%d left',
        'soldOut' => 'Sold out',
        'addOne' => 'Add one place in %s',
        'removeOne' => 'Remove one place in %s',
        'areaFull' => 'That area filled up while you were choosing. Please pick a different number of places.',
        'floors' => 'Floor',
    ],
];
