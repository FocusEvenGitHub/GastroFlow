<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $fillable = ['order_id', 'menu_item_id', 'quantity', 'notes', 'dining_option', 'unit_price', 'packaging_cost'];

    protected $casts = [
        'unit_price'     => 'float',
        'packaging_cost' => 'float',
    ];

    public $timestamps = false;

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}