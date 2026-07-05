<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\MenuItem;

class MenuRepository
{
    public function getFullMenu(): array
    {
        // Load categories with items, ordered
        $categories = Category::with(['menuItems' => function ($query) {
            $query->orderBy('name');
        }])->orderBy('type')->orderBy('name')->get();

        $menu = [];
        foreach ($categories as $cat) {
            $items = $cat->menuItems->map(function ($item) {
                return [
                    'id'          => $item->id,
                    'name'        => $item->name,
                    'description' => $item->description,
                    'price'       => $item->price,
                    'available'   => $item->available,
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
        // Find category by name
        $category = Category::where('name', $data['category_name'])->firstOrFail();
        return MenuItem::create([
            'category_id' => $category->id,
            'name'        => $data['name'],
            'description' => $data['description'] ?? '',
            'price'       => $data['price'],
            'available'   => $data['available'] ?? true,
        ]);
    }

    public function updateAvailability(int $id, bool $available): MenuItem
    {
        $item = MenuItem::findOrFail($id);
        $item->available = $available;
        $item->save();
        return $item;
    }

    public function getFullMenuPublic(): array
    {
        $categories = Category::with(['menuItems' => function ($query) {
            $query->where('available', true)->orderBy('name');
        }])->orderBy('type')->orderBy('name')->get();

        $menu = [];
        foreach ($categories as $cat) {
            $items = $cat->menuItems->map(function ($item) {
                return [
                    'id'          => $item->id,
                    'name'        => $item->name,
                    'description' => $item->description,
                    'price'       => $item->price,
                    'available'   => $item->available,
                ];
            })->all();

            if (!empty($items)) {
                $menu[] = [
                    'category_name' => $cat->name,
                    'type'          => $cat->type,
                    'items'         => $items,
                ];
            }
        }
        return $menu;
    }
}