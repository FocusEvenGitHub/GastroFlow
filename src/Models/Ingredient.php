<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected $table = 'ingredients';
    public $timestamps = false;

    protected $fillable = ['name', 'unit', 'category'];
}