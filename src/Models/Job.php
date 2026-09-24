<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $table = 'jobs';

    public const UPDATED_AT = null;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_RESERVED  = 'reserved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'queue',
        'payload',
        'status',
        'attempts',
        'max_attempts',
        'reserved_at',
        'reserved_until',
        'available_at',
        'last_error',
        'failed_at',
        'completed_at',
    ];

    protected $casts = [
        'attempts'       => 'integer',
        'max_attempts'   => 'integer',
        'reserved_at'    => 'datetime',
        'reserved_until' => 'datetime',
        'available_at'   => 'datetime',
        'failed_at'      => 'datetime',
        'completed_at'   => 'datetime',
    ];
}
