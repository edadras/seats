<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handing over one of the files an organiser uploaded.
 *
 * On every host this application answers on, and open to anybody: these are the pictures on a
 * ticket shop's front page. An identifier is not a secret here and is not treated as one — what
 * keeps one account's library out of another's is that nothing lists it, not that nothing serves
 * it.
 *
 * Served by the application rather than from `public/`, which costs a PHP process per picture and
 * buys three things: the same code path whether the disk is a volume or a bucket, no `storage:link`
 * step to forget when a server is built, and a cache header this end rather than a web server's
 * default. The header is the part that actually decides the cost — the path contains the file's own
 * identifier and the bytes at it never change, so it is a year, immutable, and the second visitor
 * to a page does not ask again.
 */
class MediaFileController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function show(Request $request, string $id)
    {
        /*
         * Unscoped, because a public request has no account bound to it.
         *
         * The row carries its own tenant and nothing about this endpoint reads across one: an
         * identifier resolves to exactly one file or to nothing at all.
         */
        $file = $this->tenants->runUnscoped(fn () => MediaFile::query()->find($id));

        if (! $file) {
            throw new NotFoundHttpException('No such file.');
        }

        $disk = Storage::disk(config('media.disk'));

        if (! $disk->exists($file->path)) {
            throw new NotFoundHttpException('No such file.');
        }

        /*
         * A bucket serves its own bytes.
         *
         * Where the disk can sign a URL — S3 and anything speaking its protocol — the visitor is
         * sent there rather than having the file read through PHP and written out again. A short
         * signature rather than a permanent public object, so making a bucket public is not part of
         * installing this.
         */
        if ('local' !== config('filesystems.disks.'.config('media.disk').'.driver')) {
            return redirect()->away($disk->temporaryUrl($file->path, now()->addHours(6)));
        }

        return $disk->response($file->path, $file->downloadName(), [
            'Content-Type' => $file->mime,
            // A year, immutable: the path names the file's identity and its bytes never change.
            'Cache-Control' => 'public, max-age=31536000, immutable',
            /*
             * Belt and braces on a file this server did not write.
             *
             * Everything served here has been through {@see \App\Domain\Media\MediaLibrary}, which
             * refuses anything that is not a picture or a film — but this header is what makes a
             * mistake there a broken image rather than a script running on the venue's own domain.
             */
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }
}
