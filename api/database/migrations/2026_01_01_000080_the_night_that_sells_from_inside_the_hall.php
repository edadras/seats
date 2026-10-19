<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The night that sells from inside the hall.
 *
 * The room already exists: an organiser sets up the rake, the stage height and where each block
 * starts, and a buyer can press a button to lift the plan into three dimensions and look at it.
 * What was missing is the decision of which of the two somebody is shown *first*, and that is a
 * decision about the night rather than about the chart.
 *
 * It belongs on the event because the same hall is both kinds of room on different evenings. A
 * cinema sells every seat in the same chair and the only question is where in the room it is —
 * there the plan is the diagram and the room is the thing. The same building hosting a conference
 * sells four hundred unnamed places at a table, and opening that in a 3D auditorium would be
 * answering a question nobody asked.
 *
 * It is a preference rather than a capability: a chart with no room set up has nothing to show, so
 * the picker falls back to the plan and the panel says why instead of offering a tick that does
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('hall_in_3d')->default(false)->after('ask_access_needs');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('hall_in_3d');
        });
    }
};
