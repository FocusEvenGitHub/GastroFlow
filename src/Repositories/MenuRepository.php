<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Category;
use App\Models\MenuItem;
use App\Money;

class MenuRepository
{
    public function getFullMenu(): array
    {
        $categories = Category::with(['menuItems' => function ($query) {
            $query->orderBy('position')->orderBy('name');
        }, 'menuItems.components'])->orderBy('type')->orderBy('name')->get();

        $menu = [];
        foreach ($categories as $cat) {
            $catName = $cat->name;
            $items = $cat->menuItems->map(function ($item) use ($catName) {
                return [
                    'id'            => $item->id,
                    'name'          => $item->name,
                    'description'   => $item->description,
                    'price'         => $item->price,
                    'available'     => $item->available,
                    'food_category' => $item->food_category,
                    'category_name' => $catName,
                    'components'    => $item->relationLoaded('components')
                        ? $item->components->map(fn($c) => [
                            'id'       => $c->id,
                            'name'     => $c->name,
                            'quantity' => $c->pivot->quantity,
                        ])->values()->all()
                        : [],
                ];
            })->all();

            $menu[] = [
                'category_name' => $cat->name,
                'type'          => $cat->type,
                'items'         => $items,
            ];
        }
        return $menu;
    }

    public function addItem(array $data): MenuItem
    {
        $category = Category::where('name', $data['category_name'])->firstOrFail();
        return MenuItem::create([
            'category_id'  => $category->id,
            'name'         => $data['name'],
            'description'  => $data['description'] ?? '',
            'price'        => Money::fromReais($data['price'])->toReais(),
            'available'    => $data['available'] ?? true,
        ]);
    }

    public function updateAvailability(int $id, bool $available): MenuItem
    {
        $item = MenuItem::findOrFail($id);
        $item->available = $available;
        $item->save();
        return $item;
    }

    public function updateItem(int $id, array $data): MenuItem
    {
        $item = MenuItem::findOrFail($id);

        if (isset($data['name'])) {
            $item->name = $data['name'];
        }
        if (isset($data['description'])) {
            $item->description = $data['description'];
        }
        if (isset($data['price'])) {
            $item->price = Money::fromReais($data['price'])->toReais();
        }
        if (isset($data['available'])) {
            $item->available = (bool) $data['available'];
        }
        if (isset($data['category_name'])) {
            $category = Category::where('name', $data['category_name'])->firstOrFail();
            $item->category_id = $category->id;
        }

        $item->save();
        return $item;
    }

    public function getDishComponents(int $dishId): array
    {
        $dish = MenuItem::with('components')->findOrFail($dishId);
        return $dish->components->map(fn($c) => [
            'id'       => $c->id,
            'name'     => $c->name,
            'quantity' => $c->pivot->quantity,
        ])->all();
    }

    public function setDishComponents(int $dishId, array $components): void
    {
        $dish = MenuItem::findOrFail($dishId);
        $sync = [];
        foreach ($components as $comp) {
            $sync[$comp['id']] = ['quantity' => $comp['quantity'] ?? 1];
        }
        $dish->components()->sync($sync);
    }

    public function deleteItem(int $id): void
    {
        $item = MenuItem::findOrFail($id);
        $item->delete();
    }

    /**
     * Persist a new manual order for the items of one category.
     * $itemIds is the full ordered list of menu_item ids within that category.
     */
    public function reorderItems(string $categoryName, array $itemIds): void
    {
        $category = Category::where('name', $categoryName)->firstOrFail();

        foreach (array_values($itemIds) as $position => $itemId) {
            MenuItem::where('id', (int) $itemId)
                ->where('category_id', $category->id)
                ->update(['position' => $position]);
        }
    }
}
