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

    public function components()
    {
        return $this->belongsToMany(
            MenuItem::class,
            'dish_components',
            'dish_id',
            'component_id'
        )->withPivot('quantity');
    }

    public function usedInDishes()
    {
        return $this->belongsToMany(
            MenuItem::class,
            'dish_components',
            'component_id',
            'dish_id'
        );
    }
}
