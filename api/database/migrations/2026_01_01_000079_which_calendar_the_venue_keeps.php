<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which calendar a venue writes its dates in.
 *
 * Until now this followed the reader's language: Persian got the Persian calendar and everyone else
 * Gregorian. That is a sensible default and it answers the wrong question. The language is a fact
 * about the *reader*; the calendar is a decision by the *organisation*. An Iranian theatre whose
 * page is in English still programmes its season in Jalali and prints ۱۴۰۵ on the ticket, and a
 * venue in Dubai reading Persian may keep its books in Gregorian and want the door list to match.
 *
 * So both get the setting, and they are two different questions rather than one duplicated:
 *
 *   `sites.calendar`   what a *buyer* reads — on the programme, in the basket, on the ticket.
 *   `tenants.calendar` what *staff* read and type — the panel, the door list, the reports.
 *
 * They are usually the same and they are not always: a venue selling to visitors from abroad may
 * publish Gregorian dates and still run the box office in Jalali, which is a real arrangement and
 * not one this platform should have an opinion about.
 *
 * `auto` is the old behaviour, kept as a deliberate choice rather than as the only one — and it is
 * the default, so nothing changes for anybody until somebody decides otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sites', 'tenants'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                // Short and a plain string rather than an enum: an enum is a migration every time
                // somebody's calendar is added, and the list is validated in one place in PHP.
                $table->string('calendar', 16)->default('auto');
            });
        }
    }

    public function down(): void
    {
        foreach (['sites', 'tenants'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('calendar');
            });
        }
    }
};
