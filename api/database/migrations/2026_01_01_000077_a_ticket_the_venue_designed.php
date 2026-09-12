<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ticket a venue designed for one night.
 *
 * Until now every ticket this platform printed looked the same: a bordered box, the event, the
 * seat, a QR. That is a correct document and it is nobody's. A theatre with a poster, a festival
 * with a sponsor's logo along the foot, a club whose whole brand is one photograph — each of them
 * wants the ticket to look like the night, and none of them can express that in a layout somebody
 * else chose.
 *
 * So a design is a background and a set of fields placed on it, per event.
 *
 * **Its own table rather than a corner of `events.settings`.** It is a document that is edited on
 * its own screen, on its own schedule, by somebody who may not be the person who set the prices —
 * and it is the kind of thing an organiser copies from last year's night to this one. A row that
 * can be selected, duplicated and deleted is worth more than a key in a JSON blob.
 *
 * **Geometry is in percentages, not millimetres.** The panel draws the design on a preview whose
 * width is whatever the browser gave it, and the PDF draws it on a page whose width is fixed; the
 * only way those two agree is if the stored numbers belong to neither. A field at 50% is centred
 * on both, and stays centred when the organiser switches the page from A4 to A5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_designs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // One design per night. A night with none prints the platform's own layout, which is
            // what every ticket looked like before this existed and is still a complete ticket.
            $table->foreignUuid('event_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * The picture behind everything.
             *
             * A URL rather than an upload, for the same reason an event's artwork is one: this
             * platform does not want to be somebody's image host, and every venue already has
             * somewhere to put a poster. Checked to be http(s) on the way in — it ends up in an
             * `img` tag in a document this server generates.
             *
             * Null is a legitimate design: fields on plain paper is what a venue that prints onto
             * pre-printed stock actually wants.
             */
            $table->string('background_url', 1024)->nullable();

            // One of TicketDesign::PAGES, portrait or landscape. The page a background was drawn
            // for is part of the design: the same picture on the other orientation, or on paper
            // of another shape, is a different ticket.
            $table->string('page_size', 12)->default('A5');
            $table->string('orientation', 12)->default('landscape');

            /*
             * What is printed, and where.
             *
             * A list of {key, x, y, width, size, weight, align, colour}, each `key` from the closed
             * list in TicketFields. Closed on purpose: a field nobody declared would print as its
             * own name on a document somebody takes to a door.
             */
            $table->jsonb('fields')->default('[]');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_designs');
    }
};
