<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each scanner last took a copy of the door list.
 *
 * One column, and it earns its place at a door rather than in a report: a tablet whose list was
 * taken at four o'clock does not know about the forty tickets sold since, and the only person who
 * can notice that is the manager looking at the devices screen before the house opens. Without
 * this, a stale device and a fresh one look identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkin_devices', function (Blueprint $table) {
            $table->timestamp('door_list_taken_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkin_devices', function (Blueprint $table) {
            $table->dropColumn('door_list_taken_at');
        });
    }
};
