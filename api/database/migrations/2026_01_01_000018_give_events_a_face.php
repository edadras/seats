<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artwork and a kind, for the pages a buyer actually sees.
 *
 * A listing of dates and prices is a spreadsheet. Every ticketing site a person recognises leads
 * with the poster and says in one word what sort of evening this is, and neither of those was
 * anywhere in the schema — so the hosted sites could only ever look like a spreadsheet.
 *
 * Both are optional. A site with no artwork gets a poster drawn from the event's own name rather
 * than a broken image, which is why nothing here is required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Held as a URL, not a file: the platform is not an image host, and an organiser who
            // already has a CDN should not be made to upload their poster a second time.
            $table->string('image_url', 500)->nullable()->after('description');
            $table->string('category', 40)->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'category']);
        });
    }
};
