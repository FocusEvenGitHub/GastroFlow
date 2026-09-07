<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Ingredient;
use Illuminate\Support\Collection;

class IngredientRepository
{
    public function getAll(): Collection
    {
        return Ingredient::orderBy('category')->orderBy('name')->get();
    }

    public function findOrFail(int $id): Ingredient
    {
        return Ingredient::findOrFail($id);
    }

    public function create(array $data): Ingredient
    {
        return Ingredient::create([
            'name' => $data['name'],
            'unit' => $data['unit'],
            'category' => $data['category'] ?? null,
        ]);
    }

    public function update(int $id, array $data): Ingredient
    {
        $ingredient = Ingredient::findOrFail($id);
        $ingredient->update($data);
        return $ingredient;
    }

    public function delete(int $id): void
    {
        $ingredient = Ingredient::findOrFail($id);
        $ingredient->delete();
    }
}
