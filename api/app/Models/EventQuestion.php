<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing an organiser needs to ask at checkout.
 *
 * Asked of the booking or of every seat, and that difference is the point: "any access
 * requirements" is one answer, "what is this guest's name" is one per chair.
 */
class EventQuestion extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'label', 'help', 'kind', 'options',
        'required', 'scope', 'position', 'status',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
        'position' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function isPerTicket(): bool
    {
        return 'ticket' === $this->scope;
    }

    /** The choices, cleaned: blank lines are not answers anybody can give. */
    public function choices(): array
    {
        return array_values(array_filter(array_map('trim', (array) ($this->options ?? []))));
    }
}
