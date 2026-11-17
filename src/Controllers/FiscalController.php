<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Nff7Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FiscalController
{
    public function __construct(
        private readonly Nff7Client $client,
    ) {
    }

    /**
     * GET /api/admin/fiscal/status
     *
     * Always 200 (spec 084): "not connected" is the answer the screen asked for, not an error
     * of this API.
     */
    public function status(Request $request, Response $response): Response
    {
        $payload = ['success' => true] + $this->client->status();
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
