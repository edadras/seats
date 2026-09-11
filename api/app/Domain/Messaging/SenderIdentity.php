<?php

namespace App\Domain\Messaging;

use App\Exceptions\ApiException;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Who a buyer's confirmation appears to come from.
 *
 * Until now: one address for every venue on the platform. A buyer who bought from Northgate Theatre
 * got an email from a name they had never heard of, replying to it reached nobody, and the venue
 * had no way to change either.
 *
 * The answer is in three parts, and the reason they are three parts is deliverability rather than
 * squeamishness:
 *
 *   **The name is theirs immediately.** "Northgate Theatre" in the From line is most of what a
 *   buyer reads, and a display name is not an identity anybody can receive mail at, so there is
 *   nothing to prove.
 *
 *   **The reply address is theirs once proved.** A code goes to the address; typing it back is what
 *   turns it into `Reply-To`. Without that step, an organiser could put a stranger's address in the
 *   header and forward a venue's complaints to them.
 *
 *   **The From *address* stays the platform's unless the operator has said otherwise.** This is the
 *   part that looks like a missing feature and is not. Sending as `tickets@northgate.example` from
 *   a server that domain's SPF does not list, with no DKIM key for it, is how confirmations land in
 *   spam — and a ticketing platform that silently ruins deliverability has done something worse
 *   than not offering the option. An operator who has set up the DNS for a venue adds that domain
 *   to `SEATMAP_SENDER_DOMAINS`, and then it is theirs in full.
 *
 * The screen says which of the three states an account is in, in those words.
 */
class SenderIdentity
{
    public const MAX_ATTEMPTS = 6;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * The From and Reply-To for anything this account sends.
     *
     * Null tenant is the platform writing on its own behalf — a signup code, an invoice — and gets
     * the operator's own identity, which is also the fallback for everybody else.
     *
     * @return array{name: string, address: string, reply_to: ?string, address_is_theirs: bool}
     */
    public function current(?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenants->id() ? Tenant::find($this->tenants->id()) : null;

        $ours = (string) config('mail.from.address');
        $name = (string) config('mail.from.name');

        if (! $tenant) {
            return ['name' => $name, 'address' => $ours, 'reply_to' => null, 'address_is_theirs' => false];
        }

        $proved = $tenant->sender_verified_at && $tenant->sender_email;
        $theirs = $proved && self::operatorAllows((string) $tenant->sender_email);

        return [
            // Their own name, or their account's, or ours. A venue that has typed nothing still
            // gets something better than the platform's brand on their customers' email.
            'name' => (string) ($tenant->sender_name ?: $tenant->name ?: $name),
            'address' => $theirs ? (string) $tenant->sender_email : $ours,
            // No point repeating the address in Reply-To when it is already the From.
            'reply_to' => ($proved && ! $theirs) ? (string) $tenant->sender_email : null,
            'address_is_theirs' => $theirs,
        ];
    }

    /**
     * What the panel shows: the state, and what would have to happen for the next one.
     *
     * @return array<string, mixed>
     */
    public function standing(Tenant $tenant): array
    {
        $current = $this->current($tenant);

        return [
            'name' => $tenant->sender_name,
            'email' => $tenant->sender_email,
            'verified' => (bool) $tenant->sender_verified_at,
            'verified_at' => $tenant->sender_verified_at?->toIso8601String(),
            'awaiting_code' => (bool) ($tenant->sender_code_hash && $tenant->sender_code_expires_at?->isFuture()),
            // Whether the operator has set this domain up to send as. Shown because it is the
            // difference between "replies reach us" and "it is entirely our email".
            'address_is_theirs' => $current['address_is_theirs'],
            'sends_as' => ['name' => $current['name'], 'address' => $current['address']],
            'reply_to' => $current['reply_to'],
            'platform_address' => (string) config('mail.from.address'),
        ];
    }

    /**
     * Set the name, and — where an address is given — start proving it.
     *
     * The name takes effect at once. The address does not: it is stored, the verification is reset,
     * and a code goes to it. An organiser who mistypes their address therefore loses the old
     * `Reply-To` until they fix it, which is correct — the header should never point somewhere
     * nobody has answered from.
     */
    public function propose(Tenant $tenant, ?string $name, ?string $email): Tenant
    {
        $email = $email ? mb_strtolower(trim($email)) : null;
        $changed = $email !== $tenant->sender_email;

        $tenant->forceFill(['sender_name' => $name ? mb_substr(trim($name), 0, 120) : null])->save();

        if (! $changed) {
            return $tenant->fresh();
        }

        $tenant->forceFill([
            'sender_email' => $email,
            'sender_verified_at' => null,
            'sender_code_hash' => null,
            'sender_code_expires_at' => null,
            'sender_code_attempts' => 0,
        ])->save();

        if ($email) {
            $this->sendCode($tenant->fresh());
        }

        return $tenant->fresh();
    }

    /** A new code to the address already on file, for somebody who deleted the first one. */
    public function sendCode(Tenant $tenant): void
    {
        if (! $tenant->sender_email) {
            throw ApiException::unprocessable('sender_no_address', 'There is no address to send a code to.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $tenant->forceFill([
            'sender_code_hash' => hash('sha256', $code),
            'sender_code_expires_at' => now()->addDay(),
            'sender_code_attempts' => 0,
        ])->save();

        /*
         * Sent from the platform's own identity, deliberately and unavoidably.
         *
         * This is the message that decides whether an address may be used, so it cannot be sent as
         * that address; and it has to arrive, so it goes the way everything the operator sends goes.
         */
        $locale = $tenant->locale ?: config('app.locale');

        Mail::raw(
            __('mail.sender.body', ['code' => $code, 'venue' => $tenant->name], $locale),
            function ($message) use ($tenant, $locale) {
                $message->to($tenant->sender_email)
                    ->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject(__('mail.sender.subject', ['venue' => $tenant->name], $locale));
            }
        );
    }

    /** Type the code from the email. */
    public function confirm(Tenant $tenant, string $code): Tenant
    {
        if (! $tenant->sender_code_hash || ! $tenant->sender_code_expires_at?->isFuture()) {
            throw ApiException::unprocessable('code_expired', 'That code has expired. Ask for a new one.');
        }

        if ($tenant->sender_code_attempts >= self::MAX_ATTEMPTS) {
            throw ApiException::unprocessable('code_expired', 'That code has expired. Ask for a new one.');
        }

        $tenant->increment('sender_code_attempts');
        $tenant->refresh();

        if (! hash_equals($tenant->sender_code_hash, hash('sha256', Str::of($code)->trim()->toString()))) {
            throw ApiException::unprocessable('code_wrong', 'That code is not right.');
        }

        $tenant->forceFill([
            'sender_verified_at' => now(),
            'sender_code_hash' => null,
            'sender_code_expires_at' => null,
            'sender_code_attempts' => 0,
        ])->save();

        return $tenant->fresh();
    }

    /** Back to the platform's own, name and all. */
    public function forget(Tenant $tenant): Tenant
    {
        $tenant->forceFill([
            'sender_name' => null,
            'sender_email' => null,
            'sender_verified_at' => null,
            'sender_code_hash' => null,
            'sender_code_expires_at' => null,
            'sender_code_attempts' => 0,
        ])->save();

        return $tenant->fresh();
    }

    /**
     * Whether the operator has arranged for this platform to send as that domain.
     *
     * An allow-list rather than a check of the DNS, because what matters is not whether an SPF
     * record exists but whether *this* server is in it and holds a DKIM key for the domain — which
     * is something the operator did, not something the application can discover.
     */
    public static function operatorAllows(string $email): bool
    {
        $domain = mb_strtolower((string) Str::after($email, '@'));

        if ('' === $domain) {
            return false;
        }

        foreach (self::allowedDomains() as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function allowedDomains(): array
    {
        $raw = (string) config('seatmap.messaging.sender_domains', '');

        return array_values(array_filter(array_map(
            fn (string $one) => mb_strtolower(trim($one)),
            explode(',', $raw)
        )));
    }
}
