<?php

namespace App\Domain\Privacy;

use App\Models\ConsentEvent;
use App\Models\MarketingConsent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * May we write to this person about something they have not bought?
 *
 * The line this service exists to hold is between **service** and **news**. A message about a
 * booking somebody holds — your seats have moved, the doors open at seven, here is your ticket — is
 * part of the sale, and needs no permission beyond having made it. A message about something they
 * have not bought is marketing, and needs their yes. Everything here is about the second kind; the
 * first never asks.
 *
 * Three rules, and the whole feature is these three:
 *
 * 1. **Silence is not consent.** The absence of a row means nobody has asked, which is not the same
 *    as a no and is emphatically not a yes. The tick box at the checkout starts empty, and there is
 *    no setting anywhere that changes that.
 * 2. **The log is the record.** `consent_events` is append-only. The current answer is kept beside
 *    it only so a segment over forty thousand buyers is one join rather than forty thousand folds,
 *    and `rebuild()` exists to prove the two agree.
 * 3. **Consent is given to an organiser, not to this platform.** Agreeing to hear from a theatre in
 *    Berlin is not agreeing to hear from a promoter in Tehran who uses the same software, so every
 *    row is scoped to one account and there is no cross-account view of any of it.
 */
class Consents
{
    /** Where an answer can come from. `import` is deliberately the awkward one — see `record`. */
    public const SOURCES = ['checkout', 'link', 'panel', 'import', 'erasure'];

    public function __construct(private readonly TenantContext $tenants) {}

    /** One person, normalised the way the customer directory normalises them. */
    public static function normalise(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /**
     * Whether this address may be sent marketing.
     *
     * A missing row is a no, because nobody has said yes. That is the whole of the difference
     * between this platform and one that mails a list it bought.
     */
    public function allows(?string $email): bool
    {
        $email = self::normalise($email);

        if ('' === $email) {
            return false;
        }

        return MarketingConsent::where('email', $email)->where('state', 'in')->exists();
    }

    /**
     * Write down an answer.
     *
     * `note` is required of the panel and of an import, and only of those: a person ticking a box
     * at a checkout has said what they said in a way this software watched, while a member of staff
     * typing somebody else's yes has not — so the system asks them where it came from, which is the
     * question an audit will ask them a year later.
     *
     * @param  'in'|'out'  $action
     */
    public function record(
        string $email,
        string $action,
        string $source,
        ?string $ip = null,
        ?string $note = null,
        ?string $recordedBy = null,
    ): MarketingConsent {
        $email = self::normalise($email);
        $action = 'in' === $action ? 'in' : 'out';
        $source = in_array($source, self::SOURCES, true) ? $source : 'panel';
        $now = now();

        return DB::transaction(function () use ($email, $action, $source, $ip, $note, $recordedBy, $now) {
            ConsentEvent::create([
                'tenant_id' => $this->tenants->idOrFail(),
                'email' => $email,
                'action' => $action,
                'source' => $source,
                'ip' => $ip,
                'note' => $note,
                'recorded_by' => $recordedBy,
                'created_at' => $now,
            ]);

            $consent = MarketingConsent::updateOrCreate(
                ['tenant_id' => $this->tenants->idOrFail(), 'email' => $email],
                [
                    'state' => $action,
                    'source' => $source,
                    'ip' => $ip,
                    'note' => $note,
                    'decided_at' => $now,
                ],
            );

            return $consent;
        });
    }

    /**
     * What is known about one person's answer, for a screen that shows it.
     *
     * @return array{state:string, source:?string, decided_at:?string, history:list<array<string,mixed>>}
     */
    public function forEmail(string $email): array
    {
        $email = self::normalise($email);
        $consent = MarketingConsent::where('email', $email)->first();

        return [
            // Three states, not two: "never asked" is a real answer and is the one most people are
            // in. A screen that showed it as "no" would suggest somebody had refused.
            'state' => $consent?->state ?? 'unasked',
            'source' => $consent?->source,
            'decided_at' => $consent?->decided_at?->toIso8601String(),
            'history' => ConsentEvent::where('email', $email)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (ConsentEvent $event) => [
                    'action' => $event->action,
                    'source' => $event->source,
                    'note' => $event->note,
                    'at' => $event->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * A link that lets somebody change their mind without signing in to anything.
     *
     * Signed rather than looked up, so the footer of every marketing message can carry it without
     * this platform storing a token per recipient — and so that guessing one is guessing a
     * signature. It carries the account it belongs to, because consent is per organiser.
     */
    public function linkFor(string $tenantId, string $email, string $base = ''): string
    {
        $email = self::normalise($email);
        $token = $this->tokenFor($tenantId, $email);

        return rtrim($base, '/').'/preferences/'.urlencode($email).'/'.$token;
    }

    /**
     * Half of a SHA-256, which is 128 bits of signature.
     *
     * Shortened deliberately: this token is read in the footer of an email, sometimes retyped from
     * a printed page, and a sixty-four character tail makes a link that wraps in every mail client
     * there is. 128 bits is not a number anybody guesses, and a link nobody can follow is a way out
     * that does not work.
     */
    public function tokenFor(string $tenantId, string $email): string
    {
        return substr(hash_hmac(
            'sha256',
            $tenantId.'|'.self::normalise($email),
            (string) config('app.key'),
        ), 0, 32);
    }

    /** Constant-time, because a check that leaks its answer by timing leaks the token. */
    public function tokenIsGood(string $tenantId, string $email, string $token): bool
    {
        return hash_equals($this->tokenFor($tenantId, $email), $token);
    }

    /**
     * Fold the log again and correct the row it produced.
     *
     * Nothing calls this in the ordinary run of things: it is here because a stored fold that
     * cannot be recomputed is a stored fold nobody can check, and a claim about consent that
     * nobody can check is worth nothing. The test suite calls it, and so can a console.
     *
     * @return int how many rows disagreed with the log
     */
    public function rebuild(): int
    {
        $corrected = 0;

        $latest = ConsentEvent::query()
            ->toBase()
            ->selectRaw('email, (array_agg(action ORDER BY created_at DESC))[1] as action, '.
                'max(created_at) as decided_at')
            ->groupBy('email')
            ->get();

        foreach ($latest as $row) {
            $consent = MarketingConsent::where('email', $row->email)->first();

            if ($consent && $consent->state === $row->action) {
                continue;
            }

            MarketingConsent::updateOrCreate(
                ['tenant_id' => $this->tenants->idOrFail(), 'email' => $row->email],
                ['state' => $row->action, 'source' => 'panel', 'decided_at' => $row->decided_at],
            );

            $corrected++;
        }

        return $corrected;
    }

    /**
     * Forget an address entirely — the erasure path, and the only thing that deletes any of this.
     *
     * The log goes with the answer. Keeping "this person once said no" after they have asked to be
     * forgotten would be keeping a record of them in order to respect their wish not to be on
     * record, which is a circle nobody should be asked to accept. A person who comes back and buys
     * again starts as somebody who has not been asked.
     */
    public function forget(string $email): void
    {
        $email = self::normalise($email);

        DB::transaction(function () use ($email) {
            ConsentEvent::where('email', $email)->delete();
            MarketingConsent::where('email', $email)->delete();
        });
    }

    /** A short opaque id for a link, where an address in a URL is not wanted. */
    public static function keyFor(string $email): string
    {
        return Str::lower(hash('sha256', self::normalise($email)));
    }
}
