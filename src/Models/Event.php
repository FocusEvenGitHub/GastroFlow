<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A realtime event row (spec 041). `id` doubles as the SSE event ID / reconnection cursor —
 * the same role `Job::$id` plays for FIFO ordering in the print queue (migration 007).
 */
class Event extends Model
{
    protected $table = 'events';

    public const UPDATED_AT = null;

    protected $fillable = ['type', 'order_id', 'payload'];

    protected $casts = [
        'order_id'   => 'integer',
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];
}
