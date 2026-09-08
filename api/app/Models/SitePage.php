<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A page: a slug, a title, and an ordered list of typed blocks.
 *
 * Draft and published are two columns on one row. A visitor sees `published_blocks`; the editor
 * writes `draft_blocks`. Publishing copies one to the other, so a half-finished edit cannot reach
 * the public site in the middle of an on-sale.
 */
class SitePage extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'site_id', 'slug', 'title', 'kind',
        'draft_blocks', 'published_blocks', 'seo_title', 'seo_description',
        'position', 'published_at',
    ];

    protected $casts = [
        'draft_blocks' => 'array',
        'published_blocks' => 'array',
        'published_at' => 'datetime',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function isPublished(): bool
    {
        return null !== $this->published_at && null !== $this->published_blocks;
    }

    /** What a visitor sees. Empty rather than the draft: an unpublished page has no content. */
    public function liveBlocks(): array
    {
        return $this->published_blocks ?? [];
    }

    public function hasUnpublishedChanges(): bool
    {
        return json_encode($this->draft_blocks) !== json_encode($this->published_blocks);
    }

    public function path(): string
    {
        return '' === $this->slug ? '/' : '/'.$this->slug;
    }
}
