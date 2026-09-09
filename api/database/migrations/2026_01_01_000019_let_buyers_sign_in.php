<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a site offers "sign in with Google" to its buyers.
 *
 * A column rather than a row in some settings table: it is a property of the site, it is asked on
 * every render of the page that shows the button, and a boolean with a default is the cheapest
 * possible answer.
 *
 * There is deliberately no `buyer_accounts` table beside it. Signing in with Google proves an
 * email address; the address is the identity, and the orders are already keyed by it. Storing a
 * second copy of every buyer's name and address would be a new place for that data to leak from
 * and a new thing to keep in step with the orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('google_signin')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('google_signin');
        });
    }
};
