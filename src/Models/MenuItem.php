<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $table = 'menu_items';
    public $timestamps = false;

    protected $fillable = ['category_id', 'name', 'description', 'price', 'available'];
    protected $casts = ['available' => 'boolean', 'price' => 'float'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}