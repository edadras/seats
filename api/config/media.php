<?php

/*
 * Where the platform keeps the pictures, and how big one may be.
 *
 * The disk is a setting rather than a decision, because the answer differs by installation: one
 * server with a volume wants `media`, and anything behind more than one web server wants a bucket —
 * two servers with two local disks is a site whose hero image appears on every other page load.
 * Nothing else in the application knows which was chosen: a file is addressed by its row.
 */
return [
    'disk' => env('SEATMAP_MEDIA_DISK', 'media'),

    /*
     * The longest side a picture is kept at, in pixels.
     *
     * 2560 is a hero image on a large screen at twice the pixel density, which is the largest thing
     * any of these pictures is ever drawn as. Above it is a file a buyer waits for and never sees
     * the benefit of.
     */
    'longest_side' => (int) env('SEATMAP_MEDIA_LONGEST_SIDE', 2560),

    /* JPEG and WebP quality. 82 is the point where the next percent costs more bytes than it is worth. */
    'quality' => (int) env('SEATMAP_MEDIA_QUALITY', 82),

    'max_image_megabytes' => (int) env('SEATMAP_MEDIA_MAX_IMAGE_MB', 12),

    /*
     * A film is a different size of thing, and this is the one limit worth thinking about before an
     * installation goes up: it has to be under PHP's own `upload_max_filesize` and `post_max_size`,
     * or the refusal happens in the web server and the organiser gets a blank page instead of a
     * sentence. `seatmap:preflight` says so out loud.
     */
    'max_video_megabytes' => (int) env('SEATMAP_MEDIA_MAX_VIDEO_MB', 64),
];
