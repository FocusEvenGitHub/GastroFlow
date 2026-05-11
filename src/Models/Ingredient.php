<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected $table = 'ingredients';
    public $timestamps = false;

    protected $fillable = ['name', 'unit', 'category_id'];

    public function category()
    {
        return $this->belongsTo(IngredientCategory::class, 'category_id');
    }
}