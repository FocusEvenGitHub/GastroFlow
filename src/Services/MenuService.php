<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MenuRepository;
use App\Models\MenuItem;

class MenuService
{
    private MenuRepository $menuRepo;

    public function __construct(MenuRepository $menuRepo)
    {
        $this->menuRepo = $menuRepo;
    }

    public function getFullMenu(): array
    {
        return $this->menuRepo->getFullMenu();
    }

    public function addItem(array $data): MenuItem
    {
        return $this->menuRepo->addItem($data);
    }

    public function updateAvailability(int $id, bool $available): MenuItem
    {
        return $this->menuRepo->updateAvailability($id, $available);
    }

    public function updateItem(int $id, array $data): MenuItem
    {
        return $this->menuRepo->updateItem($id, $data);
    }

    public function getDishComponents(int $dishId): array
    {
        return $this->menuRepo->getDishComponents($dishId);
    }

    public function updateDishComponents(int $dishId, array $components): void
    {
        $this->menuRepo->setDishComponents($dishId, $components);
    }

    public function deleteItem(int $id): void
    {
        $this->menuRepo->deleteItem($id);
    }
}
