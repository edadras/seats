<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two amounts on a ticket that are not the ticket.
 *
 * A booking fee is the organiser's charge for selling; tax is the state's share. Both were
 * previously folded into whatever the seat cost, which is fine until somebody has to produce an
 * invoice — and then there is no way to say what part of €50 was VAT, because nobody wrote it down.
 *
 * They live on the event rather than on the account because they genuinely differ per event: a
 * charity night takes no fee, a festival takes three euros, and a workshop in another country is
 * taxed at another rate. An event that sets none of this behaves exactly as it did.
 *
 * Only the hosted checkout applies them. A WooCommerce sale has a shop that owns the money, its
 * own tax settings and its own fee lines, and computing a second opinion here would be this
 * platform arguing with the till (ADR-0001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // none | per_order | per_ticket — where the fixed part of the fee is applied.
            $table->string('booking_fee_kind', 12)->default('none')->after('currency');
            $table->unsignedBigInteger('booking_fee_amount')->default(0)->after('booking_fee_kind');
            // Whole per cent of the ticket subtotal, added on top of the fixed part.
            $table->unsignedSmallInteger('booking_fee_percent')->default(0)->after('booking_fee_amount');
            $table->string('booking_fee_label', 60)->nullable()->after('booking_fee_percent');

            /*
             * Basis points, not per cent: 1900 is 19%, and 875 is 8.75%.
             *
             * Rates with a fraction are ordinary — Switzerland charges 8.1%, Ireland 13.5% — and a
             * column that can only hold whole per cent is a column somebody rounds a tax rate into.
             */
            $table->unsignedSmallInteger('tax_rate')->default(0)->after('booking_fee_label');
            /*
             * Whether the prices the organiser typed already contain the tax.
             *
             * Most of Europe advertises tax-inclusive prices to consumers and most of North America
             * does not, and getting this backwards is a 19% error in either direction — so it is
             * asked rather than assumed.
             */
            $table->boolean('tax_included')->default(true)->after('tax_rate');
            // "VAT", "MwSt", "TVA", "مالیات بر ارزش افزوده" — the organiser's own word for it.
            $table->string('tax_label', 40)->nullable()->after('tax_included');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'booking_fee_kind', 'booking_fee_amount', 'booking_fee_percent', 'booking_fee_label',
                'tax_rate', 'tax_included', 'tax_label',
            ]);
        });
    }
};
