<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the organiser needs to ask, and what the buyer answered.
 *
 * Dietary requirements for a dinner, a car registration for a venue with a barrier, the name of
 * each person for an event where tickets are not transferable, whether somebody needs a wheelchair
 * space. Every organiser has two or three of these and every one of them is different, so the
 * platform holds the shape of the question rather than a list of the questions it thinks people ask.
 *
 * Scope is the interesting part. "Do you have any access requirements" is asked once of the person
 * booking; "what is this guest's name" has to be asked of every seat, and the door list needs it
 * against the right one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('label', 160);
            $table->string('help', 240)->nullable();
            $table->string('kind', 12)->default('text');   // text|choice|checkbox
            $table->jsonb('options')->nullable();          // choice only
            $table->boolean('required')->default(false);
            // order: asked once of whoever is booking. ticket: asked of every seat.
            $table->string('scope', 8)->default('order');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 10)->default('active'); // active|hidden
            $table->timestamps();

            $table->index(['tenant_id', 'event_id', 'status']);
        });

        Schema::create('question_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_question_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('external_order_row_id')->constrained('external_orders')->cascadeOnDelete();
            // Null for an order-scoped question: it was asked of the booking, not of a seat.
            $table->foreignUuid('allocation_id')->nullable()->constrained()->cascadeOnDelete();
            /*
             * The question as it was asked, beside the answer.
             *
             * An organiser who rewords a question next season must not change what a past answer
             * appears to be an answer to — the same reason a ticket carries the name of its type.
             */
            $table->string('label', 160);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'external_order_row_id']);
            $table->index(['event_id', 'allocation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_answers');
        Schema::dropIfExists('event_questions');
    }
};
