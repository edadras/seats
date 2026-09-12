<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One file the platform is keeping: a poster, a photograph of the view, a trailer.
 */
class MediaFile extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'kind', 'mime', 'path', 'bytes', 'width', 'height',
        'original_name', 'checksum', 'created_by_user_id',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The address this file is served at.
     *
     * Absolute, and on the host the request came in on. Everything downstream — an event's
     * artwork, a slideshow row, the background of a ticket — stores a URL and validates it as one,
     * so a file kept here has to look exactly like a file kept anywhere else. That is what lets
     * the whole feature be added without changing a single column that already holds a picture.
     *
     * The name is on the end and is decoration: browsers, and the person reading a page's source,
     * both do better with `…/poster.jpg` than with a bare identifier. It is ignored on the way in.
     */
    public function url(): string
    {
        return url('/media/'.$this->id.'/'.$this->downloadName());
    }

    /** The file's name, made safe to put in a path. */
    public function downloadName(): string
    {
        $name = pathinfo($this->original_name, PATHINFO_FILENAME);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'file';
        $name = trim($name, '-.') ?: 'file';

        return mb_substr($name, 0, 60).'.'.$this->extension();
    }

    public function extension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION) ?: 'bin';
    }
}
