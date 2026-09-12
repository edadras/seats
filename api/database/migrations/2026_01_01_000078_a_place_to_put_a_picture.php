<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to put a poster.
 *
 * Every picture and every film on this platform was a URL somebody else was hosting: an event's
 * artwork, a site's hero, a slideshow, the logo in the masthead, the view from a seat, and now the
 * background of a ticket. That is a reasonable thing to *allow* and a terrible thing to *require* —
 * it asks a theatre with a poster on their desktop to first find a web host, which is not a thing
 * anybody selling tickets wanted to be doing that afternoon.
 *
 * So: files the platform keeps, uploaded by dragging one onto the field that wants it.
 *
 * **A row, not just a path.** What is stored is the file's identity — its type, its size, the name
 * it arrived under, who uploaded it — because the library is a screen: an organiser picks last
 * season's poster out of it rather than finding the file again. A path on a disk answers none of
 * those questions.
 *
 * **The same file uploaded twice is one row.** `checksum` is the SHA-256 of the bytes, unique per
 * account, so a venue that drags the same poster onto four events stores it once. It also means an
 * upload is idempotent, which matters on a phone where the connection dropped halfway.
 *
 * **What is served is not what arrived.** Images are re-encoded on the way in — metadata dropped,
 * the longest side capped — so a 48-megapixel photograph straight off a phone does not become a
 * hero image that takes nine seconds on a 3G connection at a bus stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // 'image' or 'video'. Kept as a column rather than derived from the MIME type every
            // time, because the library filters on it — a field that wants a poster should not
            // offer somebody's trailer.
            $table->string('kind', 16);
            $table->string('mime', 96);

            /*
             * Where the bytes are, on whichever disk the platform was configured with.
             *
             * A path rather than a URL: the URL is built when it is asked for, so moving an
             * installation from a local disk to a bucket is a change to one setting rather than a
             * rewrite of every row that ever referred to a picture.
             */
            $table->string('path', 512);

            $table->unsignedBigInteger('bytes');

            // Null for a film: reading a video's dimensions means decoding it, which means a
            // dependency this platform does not otherwise need. A picture's are known, and are
            // what lets a field warn that a hero is 300 pixels wide.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // What the file was called on the organiser's own machine. Shown in the library
            // because "poster-final-FINAL-2.jpg" is how they will recognise it, and never used to
            // build the path — a name from outside is not a safe path.
            $table->string('original_name', 255);

            $table->string('checksum', 64);

            // Who uploaded it. Nulled rather than deleted when they leave: the picture is the
            // account's, the attribution was only ever a courtesy.
            $table->foreignUuid('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'checksum']);
            $table->index(['tenant_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
