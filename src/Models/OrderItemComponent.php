<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One add-on chosen for a build-your-own dish order item (spec 030).
 * item_name/unit_price are order-time snapshots, like OrderItem's (spec 023).
 */
class OrderItemComponent extends Model
{
    protected $table = 'order_item_components';
    public $timestamps = false;

    protected $fillable = ['order_item_id', 'menu_item_id', 'item_name', 'quantity', 'unit_price'];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_price' => 'float',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
