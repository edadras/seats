<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One person has seen one notification. No tenant column: both sides carry one already. */
class NotificationRead extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'notification_reads';

    protected $fillable = ['notification_id', 'user_id', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];
}
