<?php

namespace App\Domain\Wallet;

use App\Models\WalletSetting;

/**
 * The square Apple refuses to open a pass without.
 *
 * Drawn rather than fetched. A logo URL would mean an outbound request while somebody is standing
 * in a queue waiting for their pass to download, and a request that fails is a pass that does not
 * exist — so this makes a plain tile from the organiser's own colours and the first letters of
 * their name, which is what the rest of this platform already does where artwork is missing.
 *
 * An organiser who wants their own mark on it can put one there through their site's theme; this
 * is the floor, not the ceiling.
 */
class PassArtwork
{
    public function icon(WalletSetting $wallet, int $size): string
    {
        $image = imagecreatetruecolor($size, $size);

        [$r, $g, $b] = $this->rgb($wallet->background_colour ?: 'rgb(23, 26, 33)');
        [$tr, $tg, $tb] = $this->rgb($wallet->text_colour ?: 'rgb(255, 255, 255)');

        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        $initials = $this->initials($wallet->logo_text ?: '');

        if ('' !== $initials) {
            // The built-in font, deliberately: a TrueType face would have to be found on the
            // server, and a pass that fails because a font moved is a pass nobody can debug.
            $font = 5;
            $width = imagefontwidth($font) * strlen($initials);
            $height = imagefontheight($font);

            imagestring(
                $image,
                $font,
                (int) (($size - $width) / 2),
                (int) (($size - $height) / 2),
                $initials,
                imagecolorallocate($image, $tr, $tg, $tb),
            );
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** Two letters, from the words of a name — the same rule the site's event covers use. */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach ($words as $word) {
            if ('' === $word) {
                continue;
            }

            $first = mb_substr($word, 0, 1);

            // ASCII only: the built-in GD font cannot draw anything else, and a box where a
            // Persian letter should be is worse than a blank tile.
            if (preg_match('/[A-Za-z0-9]/', $first)) {
                $letters .= mb_strtoupper($first);
            }

            if (2 === strlen($letters)) {
                break;
            }
        }

        return $letters;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $colour): array
    {
        if (preg_match('/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $colour, $m)) {
            return [(int) $m[1] % 256, (int) $m[2] % 256, (int) $m[3] % 256];
        }

        if (preg_match('/^#?([0-9a-f]{6})$/i', trim($colour), $m)) {
            return [
                hexdec(substr($m[1], 0, 2)),
                hexdec(substr($m[1], 2, 2)),
                hexdec(substr($m[1], 4, 2)),
            ];
        }

        return [23, 26, 33];
    }
}
