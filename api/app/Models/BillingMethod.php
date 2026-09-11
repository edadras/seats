<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * How one organiser pays the platform.
 *
 * `invoice` is a first-class answer, not the absence of one: plenty of venues are public bodies
 * that cannot put a card on a form and pay everything by transfer against a purchase order. An
 * account that has chosen it should be invoiced and left alone, not nagged on every screen to add
 * a card it is never going to add.
 *
 * `card` holds two opaque handles and four digits. The handles mean something only to the gateway
 * that issued them and nothing to anybody who reads this table; the four digits are there so a
 * person can tell which of their cards they are looking at. No card number is ever stored, which is
 * the difference between a PCI questionnaire and a PCI audit.
 */
class BillingMethod extends Model
{
    use HasUuids;

    public const KINDS = ['card', 'invoice'];

    protected $fillable = [
        'tenant_id', 'kind', 'gateway', 'customer_reference', 'method_reference',
        'brand', 'last4', 'exp_month', 'exp_year',
        'billing_name', 'billing_email', 'billing_address', 'vat_number', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'exp_month' => 'integer',
        'exp_year' => 'integer',
        /*
         * Encrypted at rest, though neither is a secret in the way a card number is.
         *
         * Both handles are worthless without the platform's own gateway key, so encrypting them
         * buys nothing against somebody who has that. What it buys is against a database dump, a
         * mislaid backup or a read-only replica somebody was given for reporting: a list of every
         * customer at a payment provider, tied to named venues, is not something to leave lying in
         * a table because it happens not to be a card number.
         */
        'customer_reference' => 'encrypted',
        'method_reference' => 'encrypted',
    ];

    protected $hidden = ['customer_reference', 'method_reference'];

    public function isCard(): bool
    {
        return 'card' === $this->kind && (string) $this->method_reference !== '';
    }

    /** Expired, or expiring inside the month — worth saying before a charge fails. */
    public function isExpiring(): bool
    {
        if (! $this->exp_year || ! $this->exp_month) {
            return false;
        }

        return now()->startOfMonth()->addMonth()
            ->greaterThanOrEqualTo(now()->setDate($this->exp_year, $this->exp_month, 1)->startOfMonth());
    }
}
