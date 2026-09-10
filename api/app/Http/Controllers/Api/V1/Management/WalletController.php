<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Wallet\PassContent;
use App\Domain\Wallet\Wallets;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\WalletSetting;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Where an organiser puts their own wallet credentials.
 *
 * Behind `account.manage`, because what is stored here can mint passes in the organiser's name —
 * the same class of secret as an API key, and not a thing a box office clerk needs.
 *
 * Nothing secret ever comes back out. The screen shows whether each half is configured and what it
 * would look like; the certificate and the service account are write-only, because a screen that
 * displayed a private key would be a screen that leaked one.
 */
class WalletController extends Controller
{
    public function __construct(
        private readonly Wallets $wallets,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $wallet = $this->wallets->settings();

        return response()->json([
            'apple' => [
                'enabled' => (bool) $wallet?->apple_enabled,
                'pass_type_id' => $wallet?->apple_pass_type_id,
                'team_id' => $wallet?->apple_team_id,
                // Whether each secret is there, never what it is.
                'has_certificate' => (bool) $wallet?->apple_certificate,
                'has_key' => (bool) $wallet?->apple_key,
                'has_wwdr' => (bool) $wallet?->apple_wwdr,
                'ready' => (bool) $wallet?->appleReady(),
            ],
            'google' => [
                'enabled' => (bool) $wallet?->google_enabled,
                'issuer_id' => $wallet?->google_issuer_id,
                'has_service_account' => (bool) $wallet?->google_service_account,
                'ready' => (bool) $wallet?->googleReady(),
            ],
            'look' => [
                'background_colour' => $wallet?->background_colour,
                'text_colour' => $wallet?->text_colour,
                'logo_text' => $wallet?->logo_text,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $data = $request->validate([
            'apple_enabled' => ['sometimes', 'boolean'],
            'apple_pass_type_id' => ['nullable', 'string', 'max:190'],
            'apple_team_id' => ['nullable', 'string', 'max:60'],
            // Left out entirely means "leave what is there"; an empty string means "remove it".
            // A form that could only ever overwrite would make an organiser paste a certificate
            // again every time they changed a colour.
            'apple_certificate' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'apple_key' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'apple_key_password' => ['sometimes', 'nullable', 'string', 'max:190'],
            'apple_wwdr' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'google_enabled' => ['sometimes', 'boolean'],
            'google_issuer_id' => ['nullable', 'string', 'max:60'],
            'google_service_account' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'background_colour' => ['nullable', 'string', 'max:20'],
            'text_colour' => ['nullable', 'string', 'max:20'],
            'logo_text' => ['nullable', 'string', 'max:60'],
        ]);

        $wallet = WalletSetting::firstOrNew(['tenant_id' => $this->tenants->idOrFail()]);
        $wallet->tenant_id = $this->tenants->idOrFail();

        foreach ($data as $field => $value) {
            $wallet->{$field} = is_string($value) && '' === trim($value) ? null : $value;
        }

        $wallet->save();

        $this->audit->record('wallet.updated', $wallet, [
            'apple' => $wallet->appleReady(),
            'google' => $wallet->googleReady(),
        ]);

        return $this->show($request);
    }

    /**
     * Sign something and see.
     *
     * A certificate that cannot sign is indistinguishable from one that can until a buyer presses
     * a button, so this presses it: a throwaway pass, built and signed and thrown away, with the
     * real error if it fails. Nothing is stored and no ticket is involved.
     */
    public function test(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $data = $request->validate([
            'platform' => ['required', 'in:apple,google'],
        ]);

        $site = Site::orderBy('created_at')->first();

        if (! $site) {
            return response()->json(['ok' => false, 'reason' => 'no_site']);
        }

        $wallet = $this->wallets->settings();

        if (! $wallet) {
            // Nothing has ever been saved. The same answer as a half-filled form, because from
            // the organiser's side it is the same thing: this wallet cannot sign.
            return response()->json(['ok' => false, 'reason' => $data['platform'].'_wallet_not_set_up']);
        }

        try {
            if ('apple' === $data['platform']) {
                app(\App\Domain\Wallet\ApplePass::class)->build($wallet, $this->sample($site));
            } else {
                app(\App\Domain\Wallet\GooglePass::class)->link($wallet, [$this->sample($site)]);
            }

            return response()->json(['ok' => true]);
        } catch (\App\Exceptions\ApiException $e) {
            // The real reason, not "it did not work": these are somebody else's certificates and
            // the difference between a wrong password and a wrong file is the whole debugging.
            return response()->json(['ok' => false, 'reason' => $e->errorCode()]);
        }
    }

    /** A pass for nobody, to prove the signing works. */
    private function sample(Site $site): PassContent
    {
        $order = new \App\Models\ExternalOrder(['external_order_id' => 'test']);
        $allocation = new \App\Models\Allocation([
            'section_name' => 'Stalls', 'row_name' => 'A', 'seat_label' => '1', 'status' => 'active',
        ]);

        $allocation->id = 'test';
        $allocation->seat_id = 'test';

        return PassContent::for($site, $order, $allocation, 'TESTTESTTESTTEST');
    }
}
