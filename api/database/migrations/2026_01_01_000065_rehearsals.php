<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rehearsal: the whole buying path walked through, with no money in it.
 *
 * Everything an organiser sets up before an onsale — the prices, the fees, the confirmation email,
 * the QR code at the door — is currently first exercised by a stranger with a card. That is the one
 * dress rehearsal this platform never offered, and the workarounds are all worse than the problem:
 * a one-cent ticket type left on a live event, a second "TEST" event that stays in the listing for
 * a year, or a real card charged and refunded at the bank's expense.
 *
 * The flag lives on the event and nowhere else, and that is the design rather than an economy.
 *
 * The obvious alternative is a flag on each order, set when the order is placed. It divides the
 * same question in two and invites them to disagree: an order marked live on an event marked test,
 * or the reverse, is a row whose truth nobody can recover afterwards. With one flag there is one
 * answer, and it is the event's.
 *
 * What makes that safe is the pair of refusals in {@see \App\Domain\Rehearsals\Rehearsals}: an
 * event with orders on it cannot become a rehearsal, and a rehearsal with orders on it cannot go
 * back to selling until they are cleared. So "an order on a rehearsal event" and "a test order"
 * are the same set, permanently, and no figure has to ask twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_rehearsal')->default(false);

            // Asked the other way round by every account-wide figure — "and not a rehearsal" — so
            // the index is on the pair rather than on the flag alone.
            $table->index(['tenant_id', 'is_rehearsal']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_rehearsal']);
            $table->dropColumn('is_rehearsal');
        });
    }
};
