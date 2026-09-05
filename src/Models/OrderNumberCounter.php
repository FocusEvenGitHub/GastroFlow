<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per business_date, tracking the last auto-assigned order_number.
 * Never mutated by a manual order_number override (spec 019) — only by
 * OrderRepository::allocateNextNumber().
 */
class OrderNumberCounter extends Model
{
    protected $table = 'order_number_counters';
    protected $primaryKey = 'business_date';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['business_date', 'last_number'];
}
