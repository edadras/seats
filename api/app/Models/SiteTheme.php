<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A theme an organiser wrote: tokens, a stylesheet, and the first-party theme it starts from.
 *
 * Never code. See `App\Domain\Sites\ThemeCss` for what happens to the stylesheet on the way in.
 */
class SiteTheme extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'key', 'name', 'base_key', 'tokens', 'css', 'updated_by'];

    protected $casts = ['tokens' => 'array'];

    public function versions()
    {
        return $this->hasMany(SiteThemeVersion::class)->orderByDesc('version');
    }

    public function sites()
    {
        return $this->hasMany(Site::class);
    }

    /** Keep what this theme looked like before it is changed. */
    public function snapshot(?string $note, ?string $userId): SiteThemeVersion
    {
        return SiteThemeVersion::create([
            'site_theme_id' => $this->id,
            'version' => (int) SiteThemeVersion::where('site_theme_id', $this->id)->max('version') + 1,
            'tokens' => $this->tokens ?? [],
            'css' => $this->css,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }
}
