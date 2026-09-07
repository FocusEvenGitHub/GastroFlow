<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ingredient;
use App\Repositories\IngredientRepository;
use Illuminate\Support\Collection;

class IngredientService
{
    private IngredientRepository $ingredientRepo;

    public function __construct(IngredientRepository $ingredientRepo)
    {
        $this->ingredientRepo = $ingredientRepo;
    }

    public function getAll(): Collection
    {
        return $this->ingredientRepo->getAll();
    }

    public function findOrFail(int $id): Ingredient
    {
        return $this->ingredientRepo->findOrFail($id);
    }

    public function create(array $data): Ingredient
    {
        return $this->ingredientRepo->create($data);
    }

    public function update(int $id, array $data): Ingredient
    {
        return $this->ingredientRepo->update($id, $data);
    }

    public function delete(int $id): void
    {
        $this->ingredientRepo->delete($id);
    }
}
