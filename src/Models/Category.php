<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table = 'categories';
    public $timestamps = false;

    protected $fillable = ['name', 'type'];

    public function menuItems()
    {
        return $this->hasMany(MenuItem::class, 'category_id');
    }
}