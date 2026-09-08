<?php

namespace App\Support\Qr;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A ticket's QR, as an SVG data URI.
 *
 * SVG rather than a raster: it is small enough to inline in an email and on a confirmation page,
 * and it stays sharp on the phone screen it will actually be scanned from — which matters, because
 * a blurry code at a door in the dark is a queue.
 */
class QrRenderer
{
    public function dataUri(string $payload, int $size = 240): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($payload, $size));
    }

    public function svg(string $payload, int $size = 240): string
    {
        $writer = new Writer(new ImageRenderer(
            // Margin 1 module, not the default 4: the surrounding card already provides the quiet
            // zone a scanner needs, and four modules of white inside a small image wastes half of it.
            new RendererStyle($size, 1),
            new SvgImageBackEnd()
        ));

        return $writer->writeString($payload);
    }
}
