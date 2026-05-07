<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    // Using default Eloquent timestamps (created_at, updated_at)
    protected $fillable = [
        'table_number',
        'status',
    ];

    // Optionally cast status
    protected $casts = [
        'status' => 'string',
    ];

    /**
     * Default status when creating an order.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_DONE = 'done';

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    // Scope for status
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}