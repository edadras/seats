<?php

namespace App\Domain\Sites;

use App\Models\Site;
use App\Support\Locale\Locales;

/**
 * A venue's site, installable and usable with no signal.
 *
 * A ticket shop is the web's clearest case for an app: somebody buys on the sofa and then needs the
 * thing they bought at a door, in a queue, on a phone with one bar of reception and a crowd sharing
 * it. So this gives every hosted site three things — a manifest, so it can be kept on a home screen
 * under the venue's own name and colours; an icon drawn from those colours, because no organiser
 * uploads one on their first day; and a service worker whose real job is not speed but the door:
 * an order page that has been opened once opens again with nothing to fetch.
 *
 * All of it is per site. A platform-wide manifest would put one name and one colour on every
 * venue's home screen, which is the opposite of what a white-label product is for.
 */
class WebApp
{
    /** The icon sizes a browser is actually offered. 180 is iOS's; the other two are the manifest's. */
    public const SIZES = [180, 192, 512];

    /**
     * The manifest, as an array.
     *
     * `theme_color` is the page's own surface rather than the accent: it colours the browser's
     * chrome directly above the masthead, and a violet band over a white header is a seam rather
     * than a brand.
     *
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    public function manifest(Site $site, array $brand, string $locale): array
    {
        $tokens = $brand['tokens'] ?? [];
        $name = trim($site->name) ?: 'Tickets';

        $manifest = [
            'id' => '/',
            'name' => $name,
            'short_name' => $this->shortName($name),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'lang' => $locale,
            'dir' => Locales::isRtl($locale) ? 'rtl' : 'ltr',
            'background_color' => $tokens['surface'] ?? '#ffffff',
            'theme_color' => $tokens['surface'] ?? '#ffffff',
            'categories' => ['entertainment', 'events'],
            'icons' => array_map(fn (int $size) => [
                'src' => '/app-icon-'.$size.'.png',
                'sizes' => $size.'x'.$size,
                'type' => 'image/png',
                // Declared maskable as well as any: the tile is full-bleed with the lettering well
                // inside the safe circle, so a launcher that crops it crops only colour.
                'purpose' => 'any maskable',
            ], self::SIZES),
        ];

        if ($site->offersSignIn()) {
            // The one shortcut worth having. Somebody opening this from a home screen at a door is
            // not browsing the programme; they are looking for the ticket they already bought.
            $manifest['shortcuts'] = [[
                'name' => trans('site.app.tickets', [], $locale),
                'url' => '/account',
                'icons' => [[
                    'src' => '/app-icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ]],
            ]];
        }

        return $manifest;
    }

    /**
     * The tile, drawn.
     *
     * Drawn rather than fetched, for the same reason the wallet pass draws its own: an organiser's
     * logo lives on somebody else's server, and an icon that depends on an outbound request is an
     * icon that is sometimes a broken square on a home screen.
     *
     * The lettering uses the face this repository ships, which covers Latin, Persian and Arabic —
     * so a Tehran venue gets its own name on the tile rather than a transliteration. Where that
     * file or FreeType is missing the tile is drawn plain, because a blank tile in the venue's
     * colours is a great deal better than an error where an icon should be.
     */
    public function icon(Site $site, array $brand, int $size): string
    {
        $tokens = $brand['tokens'] ?? [];

        $image = imagecreatetruecolor($size, $size);

        [$r, $g, $b] = $this->rgb($tokens['accent'] ?? '#4a4fdc');
        [$tr, $tg, $tb] = $this->rgb($tokens['on_accent'] ?? '#ffffff');

        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        $letters = $this->initials($site->name);
        $ink = imagecolorallocate($image, $tr, $tg, $tb);

        if ('' !== $letters) {
            $this->letter($image, $size, $letters, $ink);
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * How to tell a browser the tile has changed.
     *
     * Every icon this class draws is a pure function of the site's name and its two brand colours,
     * so those three are the whole of its identity — an organiser who recolours their site gets a
     * new tile on their next visit, and nobody else re-downloads anything.
     */
    public function etag(Site $site, array $brand, int $size): string
    {
        $tokens = $brand['tokens'] ?? [];

        return '"'.md5(implode('|', [
            $site->name,
            $tokens['accent'] ?? '',
            $tokens['on_accent'] ?? '',
            $size,
        ])).'"';
    }

    /**
     * Up to two letters from the venue's own name.
     *
     * Not restricted to ASCII, unlike the wallet's tile: this one is drawn with a real face, so
     * "تئاتر شمال" gets ت rather than a blank.
     */
    public function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $letters .= mb_substr($word, 0, 1);
        }

        return mb_strtoupper($letters);
    }

    /* --------------------------------------------------------------------------- internals */

    /** A short name is what fits under an icon: one word where there is one, cut where there is not. */
    private function shortName(string $name): string
    {
        if (mb_strlen($name) <= 12) {
            return $name;
        }

        $first = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY)[0] ?? $name;

        return mb_strlen($first) <= 12 ? $first : mb_substr($name, 0, 12);
    }

    private function letter(\GdImage $image, int $size, string $letters, int $ink): void
    {
        $font = resource_path('fonts/Vazirmatn-Bold.ttf');

        if (! function_exists('imagettfbbox') || ! is_readable($font)) {
            return;
        }

        // Just under half the tile, which leaves the lettering inside the circle a launcher crops
        // a maskable icon to even when two wide letters are set.
        $points = $size * 0.34;
        $box = imagettfbbox($points, 0, $font, $letters);

        if (! is_array($box)) {
            return;
        }

        $width = $box[2] - $box[0];
        $height = $box[1] - $box[7];

        imagettftext(
            $image,
            $points,
            0,
            (int) round(($size - $width) / 2 - $box[0]),
            (int) round(($size + $height) / 2 - $box[1]),
            $ink,
            $font,
            $letters,
        );
    }

    /** @return array{int, int, int} */
    private function rgb(string $hex): array
    {
        if (! preg_match('/^#([0-9a-fA-F]{6})$/', trim($hex), $found)) {
            return [74, 79, 220];
        }

        return [
            (int) hexdec(substr($found[1], 0, 2)),
            (int) hexdec(substr($found[1], 2, 2)),
            (int) hexdec(substr($found[1], 4, 2)),
        ];
    }
}
