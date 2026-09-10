<?php

namespace App\Domain\Wallet;

use App\Exceptions\ApiException;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\WalletSetting;
use App\Support\Tenancy\TenantContext;
use ZipArchive;

/**
 * Whether a ticket can go into a phone's wallet, and the pass if it can.
 *
 * The one thing this file exists to enforce is that a button is only offered where pressing it
 * will work. "Enabled" is a switch somebody flicked; ready is whether flicking it can actually
 * sign anything, and an "Add to Apple Wallet" that hands back an error is worse than no offer.
 *
 * The plaintext ticket codes live for one request, at issue time and nowhere else — the platform
 * keeps a hash and cannot recover them — so a pass can only be made from tokens the caller is
 * already holding. That is the same constraint the ticket PDF has, and for the same reason.
 */
class Wallets
{
    public function __construct(
        private readonly ApplePass $apple,
        private readonly GooglePass $google,
        private readonly TenantContext $tenants,
    ) {}

    public function settings(): ?WalletSetting
    {
        return WalletSetting::first();
    }

    /** @return array{apple: bool, google: bool} */
    public function offered(): array
    {
        $wallet = $this->settings();

        return [
            'apple' => (bool) $wallet?->appleReady(),
            'google' => (bool) $wallet?->googleReady(),
        ];
    }

    /**
     * The whole booking, as one download.
     *
     * A single seat is a `.pkpass`. Several are a `.pkpasses` — Apple's own bundle — because four
     * separate downloads is four chances to save three.
     *
     * @param  array<string, string>  $tokens  allocation id => plaintext code
     * @return array{body: string, type: string, filename: string}
     */
    public function apple(Site $site, ExternalOrder $order, array $tokens): array
    {
        $wallet = $this->ready('apple');
        $passes = $this->contents($site, $order, $tokens);

        if (1 === count($passes)) {
            return [
                'body' => $this->apple->build($wallet, $passes[0]),
                'type' => 'application/vnd.apple.pkpass',
                'filename' => 'ticket-'.$order->external_order_id.'.pkpass',
            ];
        }

        return [
            'body' => $this->bundle($wallet, $passes),
            'type' => 'application/vnd.apple.pkpasses',
            'filename' => 'tickets-'.$order->external_order_id.'.pkpasses',
        ];
    }

    /** @param  array<string, string>  $tokens */
    public function google(Site $site, ExternalOrder $order, array $tokens): string
    {
        return $this->google->link(
            $this->ready('google'),
            $this->contents($site, $order, $tokens),
        );
    }

    /* --------------------------------------------------------------------------- helpers */

    /** @param  list<PassContent>  $passes */
    private function bundle(WalletSetting $wallet, array $passes): string
    {
        $path = storage_path('app/passes/'.bin2hex(random_bytes(8)).'.pkpasses');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        $zip = new ZipArchive;

        if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw ApiException::unprocessable('pass_not_written', 'The passes could not be assembled.');
        }

        foreach ($passes as $index => $pass) {
            $zip->addFromString(
                'ticket-'.($index + 1).'.pkpass',
                $this->apple->build($wallet, $pass),
            );
        }

        $zip->close();

        try {
            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * One pass per seat that has a code in hand.
     *
     * A ticket whose code the caller does not hold is skipped rather than guessed at: a pass with
     * an empty barcode is a pass that gets somebody turned away at a door.
     *
     * @param  array<string, string>  $tokens
     * @return list<PassContent>
     */
    private function contents(Site $site, ExternalOrder $order, array $tokens): array
    {
        $order->loadMissing(['event.venue', 'allocations']);
        $passes = [];

        foreach ($order->allocations as $allocation) {
            $token = $tokens[$allocation->id] ?? null;

            if (! $token || 'active' !== $allocation->status) {
                continue;
            }

            $passes[] = PassContent::for($site, $order, $allocation, $token);
        }

        if ([] === $passes) {
            throw ApiException::unprocessable(
                'no_tickets_to_add',
                'There are no live tickets on this booking to add.'
            );
        }

        return $passes;
    }

    private function ready(string $platform): WalletSetting
    {
        $wallet = $this->settings();
        $ready = 'apple' === $platform ? $wallet?->appleReady() : $wallet?->googleReady();

        if (! $ready) {
            throw ApiException::unprocessable(
                $platform.'_wallet_not_set_up',
                'This account has not set up that wallet.'
            );
        }

        return $wallet;
    }
}
