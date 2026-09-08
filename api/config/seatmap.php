<?php

return [
    /*
     | Defaults for new events. Each event stores its own copy so changing this never alters the
     | terms an in-flight sale was made under.
     */
    'hold' => [
        'ttl_seconds' => (int) env('SEATMAP_HOLD_TTL_SECONDS', 600),
        'max_extends' => (int) env('SEATMAP_HOLD_MAX_EXTENDS', 2),
        'max_seats' => (int) env('SEATMAP_MAX_SEATS_PER_HOLD', 20),
        // Cap on concurrent live holds per browser session, to blunt inventory-denial attacks.
        'max_active_per_session' => (int) env('SEATMAP_MAX_HOLDS_PER_SESSION', 3),
    ],

    'hmac_window_seconds' => (int) env('SEATMAP_HMAC_WINDOW_SECONDS', 300),

    /*
     | Key for signing price snapshots handed to storefronts. Rotating it invalidates signatures on
     | holds already in flight; those holds still work server-side because the server trusts its own
     | stored snapshot, not the client's copy.
     */
    'signing_key' => env('SEATMAP_SIGNING_KEY', ''),

    /*
     | Ceilings applied when validating a seat map, before anything is persisted. These stop a
     | crafted or accidental upload from exhausting memory (threat T11).
     */
    'limits' => [
        'max_seats_per_map' => (int) env('SEATMAP_MAX_SEATS_PER_MAP', 50000),
        'max_sections_per_map' => (int) env('SEATMAP_MAX_SECTIONS', 500),
        'max_rows_per_section' => (int) env('SEATMAP_MAX_ROWS_PER_SECTION', 500),
        'max_geometry_bytes' => (int) env('SEATMAP_MAX_GEOMETRY_BYTES', 8 * 1024 * 1024),
        'max_shapes' => (int) env('SEATMAP_MAX_SHAPES', 2000),
        'max_texts' => (int) env('SEATMAP_MAX_TEXTS', 2000),
        'canvas_max' => 20000,
    ],

    'webhooks' => [
        // Exponential backoff, in seconds, one entry per attempt.
        'retry_delays' => [10, 60, 300, 1800, 7200, 21600],
        'timeout_seconds' => 10,
        'dead_after_failures' => 20,
    ],

    'checkin' => [
        'pairing_code_ttl_minutes' => 30,
        'max_batch_scans' => 500,
    ],
];
