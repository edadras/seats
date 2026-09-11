<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an organiser's marketing worked.
 *
 * A hosted site had no measurement of any kind. An organiser who spent money on a poster, a mailing
 * or a promoter's link could tell how many tickets sold in total and nothing whatever about where
 * those people came from — which is most of what the money was for.
 *
 * One column, holding ids rather than a snippet. A box an organiser can paste script tags into is a
 * stored cross-site scripting hole on a domain we serve and a checkout we run, which is the same
 * decision the `video` block already made: recognise the provider on the way in, and build the tag
 * ourselves from a narrow id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // {provider => id}. Empty means no measurement and, therefore, no consent banner: a
            // site that measures nothing has nothing to ask permission for.
            $table->jsonb('measurement')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('measurement');
        });
    }
};
