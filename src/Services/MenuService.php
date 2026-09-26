<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MenuRepository;
use App\Models\MenuItem;

class MenuService
{
    private MenuRepository $menuRepo;
    private AuditLogger $auditLogger;

    public function __construct(MenuRepository $menuRepo, AuditLogger $auditLogger)
    {
        $this->menuRepo = $menuRepo;
        $this->auditLogger = $auditLogger;
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
        $item = $this->menuRepo->updateItem($id, $data);

        // Only the fields MenuRepository::updateItem() actually recognizes (spec 043) — not
        // whatever else the caller's payload might contain.
        $recognized = ['name', 'description', 'price', 'available', 'category_name'];
        $this->auditLogger->record(
            'menu_item.updated',
            'menu_item',
            $id,
            array_intersect_key($data, array_flip($recognized))
        );

        return $item;
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

    public function reorderItems(string $categoryName, array $itemIds): void
    {
        $this->menuRepo->reorderItems($categoryName, $itemIds);
    }
}
