<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document the buyer's accounts department will accept.
 *
 * Two halves. The organiser's own details — who is issuing this, under what tax number — live on
 * the site, because that is the shop the buyer transacted with and the name they will recognise on
 * a bank statement. The buyer's details are asked for at checkout and only when they want an
 * invoice, because most buyers are people and a company address field is a question they cannot
 * answer.
 *
 * The invoice row exists so a number, once issued, never changes and never repeats. A number
 * derived on the fly from the order id would renumber itself the moment anything about the order
 * changed, and a tax authority takes a dim view of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('invoices_enabled')->default(false)->after('currency');
            // The legal entity, which is not always what the website is called.
            $table->string('legal_name', 160)->nullable()->after('invoices_enabled');
            $table->string('tax_number', 60)->nullable()->after('legal_name');
            $table->text('billing_address')->nullable()->after('tax_number');
            // "Company number 12345678. Registered in Ireland." — whatever the law requires there.
            $table->string('invoice_footer', 300)->nullable()->after('billing_address');
            // What the numbers start with: INV-2026-0001.
            $table->string('invoice_prefix', 12)->nullable()->after('invoice_footer');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('external_order_row_id')->constrained('external_orders')->cascadeOnDelete();
            $table->string('number', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->timestampTz('issued_at');
            $table->string('currency', 3);
            /*
             * Both parties and every amount, copied at the moment of issue.
             *
             * An invoice is a statement about a past transaction. Rendering it from live rows would
             * mean an organiser who corrects their address, or an event whose tax rate changes,
             * silently rewrites documents somebody has already filed.
             */
            $table->jsonb('issuer');
            $table->jsonb('buyer');
            $table->jsonb('totals');
            $table->jsonb('lines');
            $table->timestamps();

            // One invoice per order, and one number per site per year.
            $table->unique('external_order_row_id');
            $table->unique(['site_id', 'year', 'sequence']);
            $table->index(['tenant_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'invoices_enabled', 'legal_name', 'tax_number', 'billing_address',
                'invoice_footer', 'invoice_prefix',
            ]);
        });
    }
};
