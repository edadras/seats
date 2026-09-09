<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's cut, so a settlement can say what an organiser is actually owed.
 *
 * A percentage and nothing else. A fixed amount per ticket would have to be in some currency, and
 * a plan priced in euro settling an event sold in rial would need a conversion the platform does
 * not have a rate for — a percentage is the one shape that is honest in every currency at once.
 *
 * Basis points, like `events.tax_rate`: 250 is 2.5%. Whole percents cannot express the rates
 * ticketing is actually sold at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('commission_rate')->default(0)->after('price_amount');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });
    }
};
