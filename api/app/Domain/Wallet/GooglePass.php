<?php

namespace App\Domain\Wallet;

use App\Exceptions\ApiException;
use App\Models\WalletSetting;

/**
 * A "save to Google Wallet" link: one signed JWT in a URL.
 *
 * Google's model is the opposite of Apple's. Nothing is downloaded and nothing is packaged — the
 * pass is described inside a token signed by the organiser's own service account, and Google
 * builds the card from it when the buyer follows the link. Which means no zip, no images and no
 * temporary files, and the whole of the work is getting the claims right and the signature valid.
 *
 * The object is declared inline rather than created through Google's API first. That is a
 * deliberate trade: it costs nothing at issue time and needs no outbound call while somebody is
 * standing in a queue, at the price of the card's class being described on every pass rather than
 * once. For a ticket that exists for one night, that is the right way round.
 */
class GooglePass
{
    /** Long enough to press the button, short enough that a leaked link is not a leaked ticket. */
    private const LIFETIME = 3600;

    /**
     * One link for the whole booking.
     *
     * A family of four gets four cards from one press, because the alternative is four buttons
     * and a buyer who saves two of them.
     *
     * @param  list<PassContent>  $passes
     */
    public function link(WalletSetting $wallet, array $passes): string
    {
        if (! $wallet->googleReady()) {
            throw ApiException::unprocessable(
                'google_wallet_not_set_up',
                'This account has no Google Wallet issuer.'
            );
        }

        $account = json_decode((string) $wallet->google_service_account, true);

        if (! is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
            throw ApiException::unprocessable(
                'google_service_account_unreadable',
                'That service account file could not be read.'
            );
        }

        $claims = [
            'iss' => $account['client_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'exp' => time() + self::LIFETIME,
            'payload' => ['eventTicketObjects' => array_map(
                fn (PassContent $pass) => $this->object($wallet, $pass),
                $passes,
            )],
        ];

        return 'https://pay.google.com/gp/v/save/'.$this->jwt($claims, (string) $account['private_key']);
    }

    /**
     * The card.
     *
     * `id` carries the issuer prefix Google requires and the allocation's own identifier, so a
     * reissued ticket replaces the card in the wallet rather than adding a second one for the
     * same chair.
     */
    private function object(WalletSetting $wallet, PassContent $pass): array
    {
        $issuer = $wallet->google_issuer_id;
        $rows = array_values(array_filter([
            $pass->seat ? $this->row('seat', __('site.wallet.seat'), $pass->seat) : null,
            $pass->entry ? $this->row('entry', __('site.wallet.entry'), $pass->entry) : null,
            $pass->ticketType
                ? $this->row('type', __('site.wallet.ticketType'), $pass->ticketType)
                : null,
            $this->row('reference', __('site.wallet.reference'), $pass->reference),
        ]));

        return [
            'id' => $issuer.'.'.preg_replace('/[^A-Za-z0-9_.-]/', '', $pass->serial),
            'classId' => $issuer.'.seatmap-event-ticket',
            'state' => 'ACTIVE',
            'hexBackgroundColor' => $this->hex($wallet->background_colour),
            'eventName' => ['defaultValue' => $this->text($pass->event)],
            'venue' => [
                'name' => ['defaultValue' => $this->text($pass->venue)],
                'address' => ['defaultValue' => $this->text($pass->venue)],
            ],
            'dateTime' => array_filter(['start' => $pass->whenIso]),
            'ticketHolderName' => $pass->reference,
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $pass->barcode,
                'alternateText' => $pass->reference,
            ],
            'textModulesData' => $rows,
            // Declared with the object rather than created first: no outbound call while a buyer
            // is waiting, at the cost of repeating the class on every pass.
            'classReference' => [
                'id' => $issuer.'.seatmap-event-ticket',
                'issuerName' => $pass->organiser,
                'eventName' => ['defaultValue' => $this->text($pass->event)],
                'reviewStatus' => 'UNDER_REVIEW',
            ],
        ];
    }

    private function row(string $id, string $header, string $body): array
    {
        return ['id' => $id, 'header' => $header, 'body' => $body];
    }

    /** Google's localised-string shape, which every text field on a card wants. */
    private function text(string $value): array
    {
        return ['language' => app()->getLocale(), 'value' => $value];
    }

    private function hex(?string $colour): string
    {
        if ($colour && preg_match('/^#?([0-9a-f]{6})$/i', trim($colour), $m)) {
            return '#'.strtolower($m[1]);
        }

        if ($colour && preg_match('/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $colour, $m)) {
            return sprintf('#%02x%02x%02x', (int) $m[1] % 256, (int) $m[2] % 256, (int) $m[3] % 256);
        }

        return '#171a21';
    }

    /**
     * RS256, by hand.
     *
     * Three base64url segments and one signature. Pulling in a JWT library to produce sixty bytes
     * of header would be a dependency whose only job is `json_encode`, and this is the one place
     * in the platform that needs it.
     */
    private function jwt(array $claims, string $privateKey): string
    {
        // Suppressed on purpose: PHP raises a warning as well as returning false, and a warning is
        // an exception in this application. The organiser needs to be told which file is wrong,
        // not handed a stack trace from inside OpenSSL.
        $key = @openssl_pkey_get_private($privateKey);

        if (false === $key) {
            throw ApiException::unprocessable(
                'google_service_account_unreadable',
                'That service account key could not be read.'
            );
        }

        $segments = [
            $this->segment(['alg' => 'RS256', 'typ' => 'JWT']),
            $this->segment($claims),
        ];

        $signed = implode('.', $segments);
        $signature = '';

        if (! openssl_sign($signed, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw ApiException::unprocessable(
                'google_signing_failed',
                'The pass could not be signed with that service account.'
            );
        }

        return $signed.'.'.$this->base64url($signature);
    }

    private function segment(array $value): string
    {
        return $this->base64url((string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
