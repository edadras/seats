<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who agreed to be written to, when, and how they said so.
 *
 * Saved audiences (the segments work) made it possible to write to four thousand strangers in one
 * press, and nothing in this platform recorded whether any of them had agreed to hear from it. That
 * is not a missing feature so much as a debt: a confirmation of a booking somebody made and an
 * advertisement for next season are different acts, and the law of most of the places this software
 * runs in treats them differently.
 *
 * **The distinction this schema is built on: service or news.** A message about a booking the
 * reader holds — your seats have moved, the doors open at seven, here is your ticket — is part of
 * selling them the ticket, and needs no permission beyond the sale. A message about something they
 * have not bought is marketing, and needs their yes. That line is drawn once, in MessageKinds and
 * in the announcement composer, and this table is the record of the yes.
 *
 * **The log is the record; the row is an index into it.** `consent_events` is append-only, because
 * what an audit asks a year later is *when did this person agree and what were they shown* — a
 * question a single mutable column cannot answer. `marketing_consents` carries the current answer
 * so that a query over forty thousand buyers does not have to fold forty thousand logs, and it is
 * rebuilt by folding rather than by being trusted: see `Consents::rebuild`.
 *
 * **Keyed by email address, per account.** There is no buyer account here to hang it off, and
 * consent is given to *an organiser*, never to this platform: agreeing to hear from a theatre in
 * Berlin is not agreeing to hear from a promoter in Tehran who happens to use the same software.
 *
 * **Nothing is opted in by default, and there is no way to import a list quietly.** A staff member
 * may record a yes that was given on paper, and must say where it came from — which is exactly
 * what an audit will ask for, and exactly what a bulk import of a bought list cannot supply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Normalised the way the customer directory normalises it: two orders typed with
            // different capitals are one person, and one person has one answer.
            $table->string('email', 190);

            // in|out. Nothing else: "not asked" is the absence of a row, and a person who has
            // never been asked is not a person who said no.
            $table->string('state', 8);

            // Where the answer came from — checkout, a link in a message, the panel, an import —
            // and, where a person gave it themselves, the address they gave it from.
            $table->string('source', 20);
            $table->string('ip', 45)->nullable();
            $table->string('note', 200)->nullable();
            $table->timestampTz('decided_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'state']);
        });

        /*
         * Append-only, and never updated.
         *
         * A year later the question is not "does this person consent" — the row above answers that
         * — but "when did they agree, and to what, and how do you know". Only a log answers that,
         * and a log that anything rewrites answers it badly.
         */
        Schema::create('consent_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('action', 8);   // in|out
            $table->string('source', 20);  // checkout|link|panel|import|erasure
            $table->string('ip', 45)->nullable();
            // What they were shown, or what a member of staff wrote down about a paper form.
            $table->string('note', 200)->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'email', 'created_at']);
        });

        // Every message already sent stays as it is. Nothing here retrospectively decides that
        // somebody consented, which is the whole point of the table.
        DB::statement('COMMENT ON TABLE marketing_consents IS
            $$The current answer. The record is consent_events, which this is folded from.$$');
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_events');
        Schema::dropIfExists('marketing_consents');
    }
};
