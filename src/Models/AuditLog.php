<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A permanent record of a sensitive administrative action (spec 043). Append-only, never
 * pruned — unlike Event/Job, this table's entire purpose is to persist.
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'username', 'action', 'entity_type', 'entity_id', 'details'];

    protected $casts = [
        'user_id'    => 'integer',
        'entity_id'  => 'integer',
        'details'    => 'array',
        'created_at' => 'datetime',
    ];
}
