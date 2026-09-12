<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The ticket one night prints: a background, and fields placed on it.
 */
class TicketDesign extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /** The pages a venue actually prints a ticket on, in millimetres. */
    public const PAGES = [
        'A4' => [210.0, 297.0],
        'A5' => [148.0, 210.0],
        'A6' => [105.0, 148.0],
        'letter' => [215.9, 279.4],
    ];

    protected $fillable = [
        'tenant_id', 'event_id', 'background_url', 'page_size', 'orientation', 'fields',
    ];

    protected $casts = ['fields' => 'array'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The page, in millimetres, the way round the organiser chose.
     *
     * @return array{float, float}
     */
    public function pageMillimetres(): array
    {
        [$width, $height] = self::PAGES[$this->page_size] ?? self::PAGES['A5'];

        return 'landscape' === $this->orientation ? [$height, $width] : [$width, $height];
    }
}
