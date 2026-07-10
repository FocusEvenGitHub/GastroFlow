<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $table = 'jobs';

    const UPDATED_AT = null;

    protected $fillable = [
        'queue',
        'payload',
        'attempts',
        'max_attempts',
        'reserved_at',
        'available_at',
    ];

    protected $casts = [
        'attempts'     => 'integer',
        'max_attempts' => 'integer',
        'reserved_at'  => 'datetime',
        'available_at' => 'datetime',
    ];
}
