<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\ReportService;

class ReportController
{
    public function __construct(
        private readonly ReportService $reportService
    ) {}

    public function sales(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $dateFrom = $params['date_from'] ?? date('Y-m-d');
        $dateTo   = $params['date_to'] ?? date('Y-m-d');

        $data = $this->reportService->getSalesByDay($dateFrom, $dateTo);
        return $this->json($response, ['success' => true, 'data' => $data]);
    }

    public function topItems(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $dateFrom = $params['date_from'] ?? date('Y-m-d');
        $dateTo   = $params['date_to'] ?? date('Y-m-d');
        $limit    = (int) ($params['limit'] ?? 10);

        $data = $this->reportService->getTopItems($dateFrom, $dateTo, $limit);
        return $this->json($response, ['success' => true, 'data' => $data]);
    }

    public function diningOptions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $dateFrom = $params['date_from'] ?? date('Y-m-d');
        $dateTo   = $params['date_to'] ?? date('Y-m-d');

        $data = $this->reportService->getDiningOptionDistribution($dateFrom, $dateTo);
        return $this->json($response, ['success' => true, 'data' => $data]);
    }

    public function summary(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $date   = $params['date'] ?? date('Y-m-d');

        $data = $this->reportService->getDailySummary($date);
        return $this->json($response, ['success' => true, 'data' => $data]);
    }

    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
