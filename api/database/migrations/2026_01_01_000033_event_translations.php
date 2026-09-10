<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An event's own words, in the six languages everything around them already speaks.
 *
 * The panel, the website's furniture, the door app and the WordPress plugin have been translated
 * since ADR-0005. The one thing that was not is what an organiser actually types: an event called
 * "Opening night" read "Opening night" to somebody reading the rest of the page in Persian, which
 * is the most visible untranslated thing on a site claiming six languages.
 *
 * A JSON column rather than a table of rows. Every read is "this event, in this language" and
 * every write is the whole set at once, so a join would buy nothing and cost a query on the
 * hottest page the platform serves. The base columns stay exactly what they were and are the
 * fallback: an event with no translations behaves precisely as it did before this existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->jsonb('translations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('translations');
        });
    }
};
