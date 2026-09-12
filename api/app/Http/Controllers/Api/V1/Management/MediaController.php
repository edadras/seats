<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Media\MediaLibrary;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * The account's pictures and films.
 *
 * **Uploading is not an authority of its own.** There is no `media.manage` permission, and that is
 * deliberate: putting a file on a server is not a thing anybody wants to be able to do — using one
 * is. So this asks for any of the permissions that own a field a picture goes in. Inventing a
 * permission would also have been a quiet regression, because every existing custom role with
 * `events.manage` would have lost the ability to set an event's artwork on the day it shipped.
 *
 * **The library is per account and readable by anybody who can put a picture somewhere.** A poster
 * is not a secret; it is about to be on the front page.
 */
class MediaController extends Controller
{
    /**
     * The permissions that come with a field a picture goes in.
     *
     * @var list<string>
     */
    private const MAY_PLACE_A_PICTURE = [
        'events.manage', 'sites.manage', 'maps.manage', 'venues.manage', 'account.manage',
    ];

    public function __construct(
        private readonly MediaLibrary $library,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeAny($request, self::MAY_PLACE_A_PICTURE);

        $kind = $request->query('kind');
        $kind = in_array($kind, ['image', 'video'], true) ? $kind : null;

        return response()->json([
            'data' => $this->library->recent($kind)->map(fn (MediaFile $file) => $this->present($file)),
            'limits' => [
                'image_megabytes' => MediaLibrary::limit('image'),
                'video_megabytes' => MediaLibrary::limit('video'),
                'accepts' => MediaLibrary::accepts(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAny($request, self::MAY_PLACE_A_PICTURE);

        /*
         * Validated as "a file", and nothing more.
         *
         * The type and the size are decided by {@see MediaLibrary}, from the bytes, so that the
         * rule is in one place and is the same rule whether a file arrives from this screen or
         * from an import. A `mimes:` rule here would be a second, weaker copy of it — it reads the
         * extension.
         */
        $request->validate(['file' => ['required', 'file']]);

        $file = $this->library->keep($request->file('file'), $request->user()?->id);

        $this->audit->record('media.uploaded', $file, [
            'name' => $file->original_name,
            'kind' => $file->kind,
            'bytes' => $file->bytes,
        ]);

        return response()->json(['media' => $this->present($file)], 201);
    }

    public function destroy(Request $request, MediaFile $media)
    {
        $this->authorizeAny($request, self::MAY_PLACE_A_PICTURE);

        $this->audit->record('media.removed', $media, ['name' => $media->original_name]);

        $this->library->forget($media);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function present(MediaFile $file): array
    {
        return [
            'id' => $file->id,
            'url' => $file->url(),
            'kind' => $file->kind,
            'mime' => $file->mime,
            'bytes' => $file->bytes,
            'width' => $file->width,
            'height' => $file->height,
            'name' => $file->original_name,
            'created_at' => $file->created_at?->toIso8601String(),
        ];
    }
}
