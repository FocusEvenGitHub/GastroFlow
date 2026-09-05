<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    // order_number is a customer-facing pickup ticket ("Senha"), unique per business_date
    // (spec 019) — not a physical restaurant table, and not the same as this model's own id.
    protected $fillable = ['order_number', 'business_date', 'customer_name', 'status'];

    protected $casts = [
        'status' => 'string',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_DONE = 'done';
    const STATUS_CANCELLED = 'cancelled'; // terminal — no transition leads out of it (spec 020)

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}