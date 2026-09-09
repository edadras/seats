<?php

namespace App\Support\Qr;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A ticket's QR, in whichever form is about to read it.
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

    /**
     * The same code as a PNG, for a PDF.
     *
     * SVG is the right answer on a screen and the wrong one inside a PDF: the generator would have
     * to parse it, and a QR that a parser gets slightly wrong is a person at a door whose ticket
     * will not scan. A PNG is pixels, and every reader agrees about pixels.
     *
     * Drawn from the matrix rather than rasterised from the vector, so a module is a whole number
     * of pixels and no edge is ever half a shade of grey.
     */
    public function pngDataUri(string $payload, int $scale = 8, int $quiet = 2): string
    {
        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M())->getMatrix();
        $modules = $matrix->getWidth();
        $side = ($modules + $quiet * 2) * $scale;

        $image = imagecreatetruecolor($side, $side);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefilledrectangle($image, 0, 0, $side, $side, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y)) {
                    imagefilledrectangle(
                        $image,
                        ($x + $quiet) * $scale,
                        ($y + $quiet) * $scale,
                        ($x + $quiet + 1) * $scale - 1,
                        ($y + $quiet + 1) * $scale - 1,
                        $black
                    );
                }
            }
        }

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
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
