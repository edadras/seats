<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** What a custom theme looked like before somebody changed it. */
class SiteThemeVersion extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'site_theme_id', 'version', 'tokens', 'css', 'note', 'created_by'];

    protected $casts = ['tokens' => 'array', 'created_at' => 'datetime'];

    public function theme()
    {
        return $this->belongsTo(SiteTheme::class, 'site_theme_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScope('tenant');
    }
}
