<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\KitchenService;

class KitchenController
{
    private KitchenService $kitchenService;

    public function __construct(KitchenService $kitchenService)
    {
        $this->kitchenService = $kitchenService;
    }

    public function ingredientsSummary(Request $request, Response $response): Response
    {
        $data = $this->kitchenService->getIngredientsSummary();
        $response->getBody()->write(json_encode(['ingredients' => $data]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}