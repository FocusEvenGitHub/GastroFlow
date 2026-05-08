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
        // Additional business rules (e.g., check category existence) can go here
        return $this->menuRepo->addItem($data);
    }

    public function updateAvailability(int $id, bool $available): MenuItem
    {
        return $this->menuRepo->updateAvailability($id, $available);
    }
}