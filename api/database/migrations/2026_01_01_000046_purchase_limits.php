<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many one person may buy, and how to make a script's evening harder than a buyer's.
 *
 * `max_seats_per_order` has existed since the beginning and stops nothing: four at a time, six
 * times over, is twenty-four. A limit that means anything has to be counted across everything one
 * person has already bought, which is what `max_per_buyer` is.
 *
 * **A person is an email address here, and that is stated rather than hidden.** It is the only
 * identity a ticket buyer has on this platform: there is no account to sign into and no device to
 * fingerprint. Somebody determined can use a second address, and this is not the defence against
 * that — it is the defence against the ordinary case, which is one person quietly buying half the
 * front row for a show that is going to sell out.
 *
 * The second column is the one that costs a script something. `checkout_min_seconds` is how long a
 * checkout form must have been on screen before it may be submitted: a person filling in their
 * name and card takes fifteen seconds and a script takes none. With the hidden field the checkout
 * form already carries, it is the whole of the bot defence here — no CAPTCHA, no third-party
 * scoring service, no cookie, nothing that makes a blind buyer's evening worse than a sighted
 * one's, and nothing that sends a buyer's behaviour to somebody else's server to be judged.
 *
 * Both are per event, because both are decisions about *this* sale: a limit that made sense for a
 * stadium on-sale would be an insult on a Tuesday in a studio theatre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Null means no limit, which is the ordinary case and stays the default: most nights
            // do not need one, and a limit invented for a night that did not need it is a support
            // conversation with somebody buying for their whole family.
            $table->unsignedSmallInteger('max_per_buyer')->nullable()->after('max_seats_per_order');

            // Zero means the form may be submitted the instant it is drawn.
            $table->unsignedSmallInteger('checkout_min_seconds')->default(0)->after('max_per_buyer');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['max_per_buyer', 'checkout_min_seconds']);
        });
    }
};
