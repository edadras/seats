<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One organiser's wallet credentials.
 *
 * Every secret is encrypted at rest: these are keys that mint passes in the organiser's name, and
 * a database backup should not be a way to do that.
 */
class WalletSetting extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'apple_enabled', 'apple_pass_type_id', 'apple_team_id',
        'apple_certificate', 'apple_key', 'apple_key_password', 'apple_wwdr',
        'google_enabled', 'google_issuer_id', 'google_service_account',
        'background_colour', 'text_colour', 'logo_text',
    ];

    protected $casts = [
        'apple_enabled' => 'boolean',
        'google_enabled' => 'boolean',
        'apple_certificate' => 'encrypted',
        'apple_key' => 'encrypted',
        'apple_key_password' => 'encrypted',
        'apple_wwdr' => 'encrypted',
        'google_service_account' => 'encrypted',
    ];

    /** Never serialised: a screen that showed a private key would be a screen that leaked one. */
    protected $hidden = [
        'apple_certificate', 'apple_key', 'apple_key_password', 'apple_wwdr',
        'google_service_account',
    ];

    /**
     * Is there enough here to sign an Apple pass?
     *
     * Enabled is a switch somebody flicked; this is whether flicking it can actually work. A
     * button offered on the strength of the switch alone is a button that hands back an error.
     */
    public function appleReady(): bool
    {
        return $this->apple_enabled
            && $this->apple_pass_type_id
            && $this->apple_team_id
            && $this->apple_certificate
            && $this->apple_key
            && $this->apple_wwdr;
    }

    public function googleReady(): bool
    {
        return $this->google_enabled
            && $this->google_issuer_id
            && $this->google_service_account;
    }
}
