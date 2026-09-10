<?php

namespace App\Domain\Wallet;

use App\Exceptions\ApiException;
use App\Models\WalletSetting;
use ZipArchive;

/**
 * A `.pkpass`: a small zip, signed by the organiser's own Apple certificate.
 *
 * Four things go in it. `pass.json` is the ticket. Two PNGs, because Apple refuses a pass without
 * an icon and shows a blank card without a logo. `manifest.json` is a SHA-1 of every other file.
 * And `signature` is a detached PKCS#7 over that manifest — which is the whole security model:
 * change one pixel and the manifest no longer matches, change the manifest and the signature no
 * longer verifies.
 *
 * The certificate is the organiser's, always. A pass signed by this platform would say this
 * platform sold the ticket, which is both untrue and, on somebody else's Apple Developer account,
 * impossible. Where none is configured there is no button — an "Add to Apple Wallet" that hands
 * back an error is worse than no offer at all.
 */
class ApplePass
{
    public function __construct(private readonly PassArtwork $artwork) {}

    /**
     * @throws ApiException when the organiser's certificate is missing or will not sign
     */
    public function build(WalletSetting $wallet, PassContent $pass): string
    {
        if (! $wallet->appleReady()) {
            throw ApiException::unprocessable(
                'apple_wallet_not_set_up',
                'This account has no Apple Wallet certificate.'
            );
        }

        $work = $this->workspace();

        try {
            $files = [
                'pass.json' => json_encode($this->document($wallet, $pass), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'icon.png' => $this->artwork->icon($wallet, 29),
                'icon@2x.png' => $this->artwork->icon($wallet, 58),
                'logo.png' => $this->artwork->icon($wallet, 50),
            ];

            foreach ($files as $name => $contents) {
                file_put_contents($work.'/'.$name, $contents);
            }

            // The manifest is a hash per file; the signature is over the manifest and nothing else.
            $manifest = [];

            foreach (array_keys($files) as $name) {
                $manifest[$name] = sha1_file($work.'/'.$name);
            }

            file_put_contents($work.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
            file_put_contents($work.'/signature', $this->sign($wallet, $work.'/manifest.json', $work));

            return $this->zip($work, array_merge(array_keys($files), ['manifest.json', 'signature']));
        } finally {
            $this->sweep($work);
        }
    }

    /**
     * The ticket itself.
     *
     * `eventTicket` rather than `generic`: it is the style Apple gives a strip of colour and a
     * large barcode, and the one a person at a door recognises without reading it.
     */
    private function document(WalletSetting $wallet, PassContent $pass): array
    {
        $secondary = array_values(array_filter([
            $pass->seat ? ['key' => 'seat', 'label' => __('site.wallet.seat'), 'value' => $pass->seat] : null,
            $pass->entry ? ['key' => 'entry', 'label' => __('site.wallet.entry'), 'value' => $pass->entry] : null,
        ]));

        $auxiliary = array_values(array_filter([
            $pass->ticketType
                ? ['key' => 'type', 'label' => __('site.wallet.ticketType'), 'value' => $pass->ticketType]
                : null,
            ['key' => 'reference', 'label' => __('site.wallet.reference'), 'value' => $pass->reference],
        ]));

        return [
            'formatVersion' => 1,
            'passTypeIdentifier' => $wallet->apple_pass_type_id,
            'teamIdentifier' => $wallet->apple_team_id,
            'organizationName' => $pass->organiser,
            'serialNumber' => $pass->serial,
            'description' => $pass->event,
            'logoText' => $wallet->logo_text ?: $pass->organiser,
            'backgroundColor' => $wallet->background_colour ?: 'rgb(23, 26, 33)',
            'foregroundColor' => $wallet->text_colour ?: 'rgb(255, 255, 255)',
            'labelColor' => $wallet->text_colour ?: 'rgb(255, 255, 255)',
            // What makes the pass appear on the lock screen as the doors open.
            'relevantDate' => $pass->whenIso,
            'barcodes' => [[
                'format' => 'PKBarcodeFormatQR',
                'message' => $pass->barcode,
                'messageEncoding' => 'iso-8859-1',
                'altText' => $pass->reference,
            ]],
            'eventTicket' => [
                'headerFields' => [
                    ['key' => 'when', 'label' => __('site.wallet.doors'), 'value' => $pass->when],
                ],
                'primaryFields' => [
                    ['key' => 'event', 'label' => __('site.wallet.event'), 'value' => $pass->event],
                ],
                'secondaryFields' => $secondary,
                'auxiliaryFields' => $auxiliary,
                'backFields' => [
                    ['key' => 'venue', 'label' => __('site.wallet.venue'), 'value' => $pass->venue],
                    ['key' => 'code', 'label' => __('site.wallet.code'), 'value' => $pass->barcode],
                ],
            ],
        ];
    }

    /** A detached PKCS#7 signature over the manifest, in DER, which is what Apple reads. */
    private function sign(WalletSetting $wallet, string $manifestPath, string $work): string
    {
        // Suppressed on purpose, all three of these: PHP raises a warning as well as returning
        // false, and a warning is an exception in this application. What an organiser needs back
        // is which of their files is wrong, not a stack trace from inside OpenSSL.
        $certificate = @openssl_x509_read($wallet->apple_certificate);

        if (false === $certificate) {
            throw ApiException::unprocessable(
                'apple_certificate_unreadable',
                'That certificate could not be read.'
            );
        }

        $key = @openssl_pkey_get_private($wallet->apple_key, (string) $wallet->apple_key_password);

        if (false === $key) {
            throw ApiException::unprocessable(
                'apple_key_unreadable',
                'That private key could not be read — check the password.'
            );
        }

        // Apple's intermediate has to travel with the signature or the pass will not verify on a
        // phone, whatever it does on a desk.
        $wwdr = $work.'/wwdr.pem';
        $signed = $work.'/signature.der';

        file_put_contents($wwdr, $wallet->apple_wwdr);

        $ok = @openssl_pkcs7_sign(
            $manifestPath,
            $signed,
            $certificate,
            $key,
            [],
            PKCS7_BINARY | PKCS7_DETACHED,
            $wwdr,
        );

        if (! $ok) {
            $this->drain();

            throw ApiException::unprocessable(
                'apple_signing_failed',
                'The pass could not be signed with that certificate.'
            );
        }

        return $this->der(file_get_contents($signed));
    }

    /**
     * PHP signs to S/MIME; Apple wants the DER payload on its own.
     *
     * So the MIME headers are stripped and the base64 body decoded. Doing this by hand rather than
     * with a library is deliberate: it is fifteen lines, and the alternative is a dependency that
     * exists only to remove an email header.
     */
    private function der(string $smime): string
    {
        $parts = preg_split('/\r?\n\r?\n/', $smime, 2);
        $body = $parts[1] ?? '';

        // The signature is the last base64 block before the closing boundary.
        if (preg_match('/^(?:[A-Za-z0-9+\/=\r\n]+)$/m', $body, $matches)) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $matches[0]), true);

            if (false !== $decoded && '' !== $decoded) {
                return $decoded;
            }
        }

        throw ApiException::unprocessable(
            'apple_signing_failed',
            'The signature could not be read back.'
        );
    }

    /**
     * Empty OpenSSL's error queue.
     *
     * It is process-wide and it accumulates. Left full, the next organiser's signing failure is
     * reported with the last one's reason, which is worse than no reason.
     */
    private function drain(): void
    {
        while (openssl_error_string()) {
            // Discarded: the reason is already in the exception, and these are somebody else's
            // certificates — the strings can name their files.
        }
    }

    /** @param  list<string>  $names */
    private function zip(string $work, array $names): string
    {
        $path = $work.'/pass.pkpass';
        $zip = new ZipArchive;

        if (true !== $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw ApiException::unprocessable('pass_not_written', 'The pass could not be assembled.');
        }

        foreach ($names as $name) {
            $zip->addFile($work.'/'.$name, $name);
        }

        $zip->close();

        return (string) file_get_contents($path);
    }

    private function workspace(): string
    {
        $work = storage_path('app/passes/'.bin2hex(random_bytes(8)));

        if (! is_dir($work) && ! mkdir($work, 0700, true) && ! is_dir($work)) {
            throw ApiException::unprocessable('pass_not_written', 'The pass could not be assembled.');
        }

        return $work;
    }

    /** Nothing is left on disk: what was in there was a private key and somebody's ticket. */
    private function sweep(string $work): void
    {
        foreach (glob($work.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($work);
    }
}
