<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What one buyer said, against the question as it was worded at the time.
 *
 * The label is copied rather than joined for the same reason a ticket carries the name of its
 * type: an organiser rewording a question next season must not change what a past answer appears
 * to be an answer to.
 */
class QuestionAnswer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'event_question_id', 'external_order_row_id',
        'allocation_id', 'label', 'value',
    ];

    public function question()
    {
        return $this->belongsTo(EventQuestion::class, 'event_question_id');
    }

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }

    public function allocation()
    {
        return $this->belongsTo(Allocation::class);
    }
}
