<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = ['table_number', 'customer_name', 'status'];

    protected $casts = [
        'status' => 'string',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_DONE = 'done';

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}