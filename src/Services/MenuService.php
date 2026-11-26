<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MenuRepository;
use App\Models\MenuItem;

class MenuService
{
    private MenuRepository $menuRepo;
    private AuditLogger $auditLogger;
    private EventPublisher $events;

    public function __construct(MenuRepository $menuRepo, AuditLogger $auditLogger, EventPublisher $events)
    {
        $this->menuRepo = $menuRepo;
        $this->auditLogger = $auditLogger;
        $this->events = $events;
    }

    public function getFullMenu(): array
    {
        return $this->menuRepo->getFullMenu();
    }

    public function addItem(array $data): MenuItem
    {
        $item = $this->menuRepo->addItem($data);
        // A cozinha aberta recarrega: o item pode passar a aparecer nos cartões (spec 087).
        $this->events->publish('menu.updated', ['menu_item_id' => $item->id]);
        return $item;
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
        $recognized = ['name', 'description', 'price', 'available', 'show_in_kitchen', 'category_name'];
        $this->auditLogger->record(
            'menu_item.updated',
            'menu_item',
            $id,
            array_intersect_key($data, array_flip($recognized))
        );
        $this->events->publish('menu.updated', ['menu_item_id' => $id]);

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
        $this->events->publish('menu.updated', ['menu_item_id' => $id]);
    }

    public function reorderItems(string $categoryName, array $itemIds): void
    {
        $this->menuRepo->reorderItems($categoryName, $itemIds);
    }
}
