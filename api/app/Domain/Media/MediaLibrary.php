<?php

namespace App\Domain\Media;

use App\Exceptions\ApiException;
use App\Models\MediaFile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Taking a file in, and deciding what is safe to keep.
 *
 * Three rules, and each of them is a thing that goes wrong on a real afternoon.
 *
 * **What is accepted is a list, not a guess.** The browser's `Content-Type` is whatever the
 * browser felt like saying and the file's extension is whatever it was called; neither is evidence.
 * The bytes are read and the type comes from them. Anything not on the list below is refused with a
 * sentence rather than stored and served back — which is the whole of the defence, because the
 * thing being defended against is a file that is served from the venue's own domain and is not a
 * picture.
 *
 * SVG is refused specifically, and it is the one people ask about. An SVG is a document that can
 * carry script, served same-origin from the site a buyer is logged into. There is no way to allow
 * it that is worth what it costs.
 *
 * **A picture is re-encoded, not stored as it arrived.** A photograph off a phone is 48 megapixels
 * and carries the coordinates of the house it was taken in. Re-encoding drops the metadata and caps
 * the longest side, so a hero image is a hero image rather than nine seconds of loading at a bus
 * stop. An animated GIF is the exception — re-encoding one leaves a single frame, so it is kept as
 * it came and capped by size instead.
 *
 * **The same file twice is the same file.** Keyed on the SHA-256 of the stored bytes, per account.
 * A venue that drags one poster onto four events stores it once, and an upload that was retried
 * because a phone lost signal does not leave two of everything.
 */
class MediaLibrary
{
    /**
     * What may be kept, by the type the bytes actually are.
     *
     * @var array<string, array{kind: string, ext: string, reencode: bool}>
     */
    private const ACCEPTED = [
        'image/jpeg' => ['kind' => 'image', 'ext' => 'jpg', 'reencode' => true],
        'image/png' => ['kind' => 'image', 'ext' => 'png', 'reencode' => true],
        'image/webp' => ['kind' => 'image', 'ext' => 'webp', 'reencode' => true],
        // Kept as it arrived: re-encoding an animation through GD leaves one frame of it.
        'image/gif' => ['kind' => 'image', 'ext' => 'gif', 'reencode' => false],
        'video/mp4' => ['kind' => 'video', 'ext' => 'mp4', 'reencode' => false],
        'video/webm' => ['kind' => 'video', 'ext' => 'webm', 'reencode' => false],
        'video/quicktime' => ['kind' => 'video', 'ext' => 'mov', 'reencode' => false],
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return list<string> the types a field may offer, for the panel's file dialog */
    public static function accepts(string $kind = ''): array
    {
        $types = [];

        foreach (self::ACCEPTED as $mime => $about) {
            if ('' === $kind || $about['kind'] === $kind) {
                $types[] = $mime;
            }
        }

        return $types;
    }

    /** Megabytes, per kind — a poster and a trailer are not the same size of thing. */
    public static function limit(string $kind): int
    {
        return 'video' === $kind
            ? (int) config('media.max_video_megabytes')
            : (int) config('media.max_image_megabytes');
    }

    /**
     * Keep this file, and answer with the row that now stands for it.
     *
     * @throws ApiException
     */
    public function keep(UploadedFile $file, ?string $userId = null): MediaFile
    {
        if (! $file->isValid()) {
            throw ApiException::unprocessable('media_upload_failed', __('errors.media_upload_failed'));
        }

        $mime = $this->typeOfTheBytes($file);
        $about = self::ACCEPTED[$mime] ?? null;

        if (! $about) {
            throw ApiException::unprocessable('media_type_refused', __('errors.media_type_refused'));
        }

        $limit = self::limit($about['kind']) * 1024 * 1024;

        if ($file->getSize() > $limit) {
            /*
             * The limit travels as a replacement, not baked into the message.
             *
             * ApiException looks the sentence up again by code when it renders, in the reader's
             * language — so a number interpolated here would be thrown away and the buyer would
             * read ":limit MB".
             */
            throw ApiException::unprocessable(
                'media_too_large',
                'That file is larger than the limit.',
                [],
                ['limit' => self::limit($about['kind'])],
            );
        }

        [$bytes, $width, $height] = $about['reencode']
            ? $this->reencoded($file, $mime)
            : [file_get_contents($file->getRealPath()), ...$this->measured($file, $about['kind'])];

        if (false === $bytes || '' === $bytes) {
            throw ApiException::unprocessable('media_upload_failed', __('errors.media_upload_failed'));
        }

        $checksum = hash('sha256', $bytes);
        $tenantId = $this->tenants->idOrFail();

        if ($already = MediaFile::where('checksum', $checksum)->first()) {
            return $already;
        }

        /*
         * The path, built from nothing the uploader chose.
         *
         * Their filename is kept in a column and is never part of a path: a name from outside is
         * how a directory is escaped. The account's id is the first segment so one account's files
         * can be counted, moved or removed without reading a database.
         */
        $path = $tenantId.'/'.substr($checksum, 0, 2).'/'.Str::uuid()->toString().'.'.$about['ext'];

        Storage::disk(config('media.disk'))->put($path, $bytes);

        return MediaFile::create([
            'tenant_id' => $tenantId,
            'kind' => $about['kind'],
            'mime' => $mime,
            'path' => $path,
            'bytes' => strlen($bytes),
            'width' => $width,
            'height' => $height,
            'original_name' => mb_substr($file->getClientOriginalName() ?: 'file', 0, 255),
            'checksum' => $checksum,
            'created_by_user_id' => $userId,
        ]);
    }

    /** The library, newest first, optionally of one kind. */
    public function recent(?string $kind = null, int $limit = 60): \Illuminate\Support\Collection
    {
        return MediaFile::query()
            ->when($kind, fn ($query) => $query->where('kind', $kind))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Forget a file, and take the bytes with it.
     *
     * Nothing checks whether it is still in use, deliberately: a picture can be referred to from an
     * event, a page, a theme and a ticket, and a check that misses one is worse than none — it
     * would report "still in use" for something nobody can find, and a library nobody can prune
     * fills up with fifty attempts at one poster. What a page with a missing picture does is
     * already answered everywhere: it renders without it.
     */
    public function forget(MediaFile $file): void
    {
        Storage::disk(config('media.disk'))->delete($file->path);

        $file->delete();
    }

    /**
     * What the bytes actually are.
     *
     * `getMimeType()` reads the file rather than trusting the request, which is the point. It is
     * checked against the list above by exact match: a "starts with image/" test is how an SVG gets
     * in.
     */
    private function typeOfTheBytes(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();

        // Some systems report a QuickTime container as MP4 and the other way round. Both are on
        // the list, so the only thing that matters is that it is one of them.
        return $mime;
    }

    /**
     * A picture, decoded and written out again.
     *
     * @return array{string|false, ?int, ?int}
     */
    private function reencoded(UploadedFile $file, string $mime): array
    {
        $cap = (int) config('media.longest_side');
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if (! $source) {
            return [false, null, null];
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $longest = max($width, $height);

        if ($longest > $cap) {
            $scale = $cap / $longest;
            $width = max(1, (int) round($width * $scale));
            $height = max(1, (int) round($height * $scale));

            $resized = imagescale($source, $width, $height);

            if ($resized) {
                imagedestroy($source);
                $source = $resized;
            }
        }

        // Transparency survives the round trip, or a logo comes back on a black rectangle.
        imagealphablending($source, false);
        imagesavealpha($source, true);

        ob_start();

        match ($mime) {
            'image/png' => imagepng($source, null, 6),
            'image/webp' => imagewebp($source, null, (int) config('media.quality')),
            default => imagejpeg($source, null, (int) config('media.quality')),
        };

        $bytes = ob_get_clean();

        imagedestroy($source);

        return [$bytes, $width, $height];
    }

    /**
     * The size of something not being re-encoded.
     *
     * @return array{?int, ?int}
     */
    private function measured(UploadedFile $file, string $kind): array
    {
        if ('image' !== $kind) {
            return [null, null];
        }

        $size = @getimagesize($file->getRealPath());

        return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
    }
}
