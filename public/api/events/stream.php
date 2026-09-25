<?php

declare(strict_types=1);

/**
 * SSE (Server-Sent Events) endpoint for real-time kitchen updates.
 *
 * O cliente (cozinha) conecta-se a este endpoint via EventSource. Ele consulta a tabela
 * `events` (spec 041) e notifica a cozinha quando um pedido é criado, finalizado ou reaberto.
 *
 * `id` é o próprio id autoincremento da tabela — é isso que faz a reconexão nativa do
 * EventSource funcionar: o navegador guarda o último `id:` recebido e o reenvia como o
 * header `Last-Event-ID` ao reconectar, sem qualquer código extra no cliente.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use App\Settings;
use App\Database as DB;
use App\Models\Event;

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

// Last-Event-ID: o navegador reenvia isso sozinho ao reconectar (padrão EventSource), sem
// nenhum código no cliente. Sem o header (conexão nova), começa de "agora" — não replay do
// histórico inteiro, só o que aconteceu depois de reconectar.
$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
if ($lastEventId !== null && ctype_digit((string) $lastEventId)) {
    $lastId = (int) $lastEventId;
} else {
    $lastId = (int) (Event::max('id') ?? 0);
}

$pingCount = 0;

// Send initial connection event
echo "event: connected\ndata: {}\n\n";
flush();

while (true) {
    // Check if client disconnected
    if (connection_aborted()) {
        break;
    }

    $newEvents = Event::where('id', '>', $lastId)
        ->orderBy('id')
        ->limit(50)
        ->get();

    foreach ($newEvents as $event) {
        $lastId = $event->id;
        $data = [
            'type'       => $event->type,
            'order_id'   => $event->order_id,
            'timestamp'  => $event->created_at?->timestamp,
        ];
        echo "id: {$event->id}\n";
        echo "event: {$event->type}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    }
    if ($newEvents->isNotEmpty()) {
        flush();
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
