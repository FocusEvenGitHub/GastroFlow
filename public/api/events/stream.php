<?php

declare(strict_types=1);

/**
 * SSE (Server-Sent Events) endpoint for real-time kitchen updates.
 *
 * O cliente (cozinha) conecta-se a este endpoint via EventSource.
 * Ele monitora um arquivo de eventos compartilhado e notifica
 * a cozinha quando um pedido é criado, finalizado ou reaberto.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use App\Settings;
use App\Database as DB;

// Carregar .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../..');
$dotenv->load();

// Inicializar Eloquent
$settings = new Settings();
DB::boot($settings);

// Headers SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // nginx compatibility

// Desabilitar output buffering
if (ob_get_level()) {
    ob_end_clean();
}
ob_implicit_flush(true);

$eventFile = sys_get_temp_dir() . '/gastroflow-events.json';
$lastEvent = '';
$pingCount = 0;

// Send initial connection event
echo "event: connected\ndata: {}\n\n";
flush();

while (true) {
    // Check if client disconnected
    if (connection_aborted()) {
        break;
    }

    // Check for new events
    clearstatcache(true, $eventFile);
    if (file_exists($eventFile)) {
        $content = file_get_contents($eventFile);
        if ($content !== false && $content !== $lastEvent) {
            $lastEvent = $content;
            $data = json_decode($content, true);
            if ($data && isset($data['type'])) {
                echo "event: {$data['type']}\n";
                echo "data: {$content}\n\n";
                flush();
            }
        }
    }

    // Send keepalive ping every 30 seconds
    $pingCount++;
    if ($pingCount >= 15) { // 15 iterations × 2s = 30s
        $pingCount = 0;
        echo ": keepalive\n\n";
        flush();
    }

    sleep(2);
}
