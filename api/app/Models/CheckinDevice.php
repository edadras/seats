<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * A scanner. It authenticates with its own Sanctum token rather than a staff member's account, and
 * that token is only valid for the events explicitly granted to the device.
 */
class CheckinDevice extends Model implements Authenticatable
{
    // Authenticatable because Sanctum resolves it as `$request->user()`, and framework middleware
    // (the throttler, for one) expects the contract rather than a bare model.
    use AuthenticatableTrait, BelongsToTenant, HasApiTokens, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'checkin_operator_id', 'name', 'pairing_code_hash',
        'pairing_expires_at', 'paired_at', 'status', 'last_seen_at',
    ];

    protected $hidden = ['pairing_code_hash'];

    protected $casts = [
        'pairing_expires_at' => 'datetime',
        'paired_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function operator()
    {
        return $this->belongsTo(CheckinOperator::class, 'checkin_operator_id');
    }

    public function events()
    {
        return $this->belongsToMany(Event::class, 'checkin_device_events')
            ->using(CheckinDeviceEvent::class)
            ->withTimestamps();
    }

    /**
     * Grant this device the right to scan an event.
     *
     * Written through the pivot model rather than `events()->attach()`: attach performs a raw
     * insert that skips model hooks, so the row would get neither a UUID nor a tenant_id.
     */
    public function grantAccessTo(Event $event): CheckinDeviceEvent
    {
        return CheckinDeviceEvent::firstOrCreate([
            'checkin_device_id' => $this->id,
            'event_id' => $event->id,
        ]);
    }

    public function revokeAccessTo(Event $event): void
    {
        CheckinDeviceEvent::where('checkin_device_id', $this->id)
            ->where('event_id', $event->id)
            ->delete();
    }

    public function mayScan(string $eventId): bool
    {
        return $this->events()->whereKey($eventId)->exists();
    }
}
