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
        'draft_blocks', 'published_blocks', 'seo_title', 'seo_description', 'translations',
        'position', 'published_at',
    ];

    protected $casts = [
        'draft_blocks' => 'array',
        'published_blocks' => 'array',
        'translations' => 'array',
        'published_at' => 'datetime',
    ];

    /**
     * A translation is an overlay on the page, never a second copy of it.
     *
     * Per locale: a title, the two SEO lines, and the text of individual blocks keyed by block id.
     * Not a second block tree — a copied tree drifts the moment somebody adds a section to one
     * language and not the other, and nothing in an editor can tell them so. An overlay cannot
     * drift, because the shape of the page exists in exactly one place.
     */
    public function titleFor(?string $locale = null): string
    {
        return $this->words('title', $locale) ?? (string) $this->title;
    }

    public function seoTitleFor(?string $locale = null): ?string
    {
        return $this->words('seo_title', $locale) ?? $this->seo_title;
    }

    public function seoDescriptionFor(?string $locale = null): ?string
    {
        return $this->words('seo_description', $locale) ?? $this->seo_description;
    }

    /**
     * The blocks a visitor sees, with this locale's words written over them.
     *
     * Field by field rather than block by block: a half-translated page is a page with some
     * English on it, which is what a reader would rather have than a page with holes in it.
     *
     * @return list<array<string, mixed>>
     */
    public function blocksFor(?string $locale = null): array
    {
        $locale = \App\Support\Locale\Locales::normalise($locale ?: app()->getLocale());
        $overlay = $this->translations[$locale]['blocks'] ?? null;
        $blocks = $this->liveBlocks();

        if (! is_array($overlay) || [] === $overlay) {
            return $blocks;
        }

        return array_map(function (array $block) use ($overlay) {
            $words = $overlay[$block['id'] ?? ''] ?? null;

            if (! is_array($words)) {
                return $block;
            }

            foreach ($words as $field => $value) {
                // Only over text that is already there. A translation cannot invent a field the
                // block does not have, which is what keeps this an overlay rather than an editor.
                if (array_key_exists($field, $block) && is_string($block[$field]) && is_string($value)
                    && '' !== trim($value)) {
                    $block[$field] = $value;
                }
            }

            return $block;
        }, $blocks);
    }

    /** Which languages this page has actually been written in, the original excluded. */
    public function writtenIn(): array
    {
        $written = [];

        foreach ((array) ($this->translations ?? []) as $locale => $fields) {
            if (is_array($fields) && ([] !== ($fields['blocks'] ?? []) || '' !== trim((string) ($fields['title'] ?? '')))) {
                $written[] = $locale;
            }
        }

        return $written;
    }

    private function words(string $field, ?string $locale): ?string
    {
        $locale = \App\Support\Locale\Locales::normalise($locale ?: app()->getLocale());
        $value = $this->translations[$locale][$field] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }

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
