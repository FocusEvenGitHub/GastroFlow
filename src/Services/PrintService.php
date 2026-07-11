<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Psr\Log\LoggerInterface;

class PrintService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get printer connection settings.
     */
    private function getPrinterConfig(): array
    {
        return [
            'ip'   => Setting::getValue('printer_ip'),
            'port' => (int) (Setting::getValue('printer_port') ?: 9100),
            'name' => Setting::getValue('restaurant_name', 'GastroFlow'),
        ];
    }

    /**
     * Print an order receipt on the network thermal printer.
     * Errors are logged but never thrown — printing is best-effort.
     */
    public function printOrder(Order $order): void
    {
        $config = $this->getPrinterConfig();

        if (empty($config['ip'])) {
            $this->logger->warning('Impressão cancelada: IP da impressora não configurado.');
            return;
        }

        try {
            $connector = new NetworkPrintConnector($config['ip'], $config['port'], 5);
            $printer = new Printer($connector);

            $this->buildReceipt($printer, $order, $config['name']);

            $printer->close();
            $this->logger->info('Pedido #' . $order->id . ' impresso em ' . $config['ip'] . ':' . $config['port']);
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao imprimir pedido #' . $order->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Print a test page to verify printer connectivity.
     */
    public function printTestPage(): void
    {
        $config = $this->getPrinterConfig();

        if (empty($config['ip'])) {
            throw new \RuntimeException('IP da impressora não configurado.');
        }

        $connector = new NetworkPrintConnector($config['ip'], $config['port'], 5);
        $printer = new Printer($connector);

        $printer->initialize();
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT);
        $printer->text($config['name'] . "\n");
        $printer->selectPrintMode();
        $printer->feed();

        // Logo
        $logoPath = __DIR__ . '/../../public/assets/img/logo.png';
        if (file_exists($logoPath)) {
            try {
                $logo = EscposImage::load($logoPath);
                $printer->bitImage($logo);
                $printer->feed();
            } catch (\Throwable $e) {
                $this->logger->warning('Logo não pôde ser carregada na página de teste: ' . $e->getMessage());
            }
        }

        $printer->setEmphasis(true);
        $printer->text(">>> TESTE DE IMPRESSÃO <<<\n");
        $printer->setEmphasis(false);
        $printer->feed();
        $printer->text("Impressora configurada em: " . $config['ip'] . ":" . $config['port'] . "\n");
        $printer->text("Data: " . date('d/m/Y H:i') . "\n");
        $printer->feed();
        $printer->text(str_repeat('-', 32) . "\n");

        // Teste de caracteres
        $printer->text("Caracteres especiais: á é í ó ú ç ã õ ñ\n");
        $printer->text("Negrito, ");
        $printer->setEmphasis(true);
        $printer->text("negrito ativado\n");
        $printer->setEmphasis(false);
        $printer->feed();

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("Se você está lendo isso,\na impressora está funcionando!\n");
        $printer->feed(2);
        $printer->text("---  FIM DO TESTE  ---\n");
        $printer->feed(2);
        $printer->cut();
        $printer->close();

        $this->logger->info('Página de teste impressa em ' . $config['ip'] . ':' . $config['port']);
    }

    /**
     * Build the full receipt content.
     */
    private function buildReceipt(Printer $printer, Order $order, string $restaurantName): void
    {
        $printer->initialize();

        // --- Header ---
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT);
        $printer->text($restaurantName . "\n");
        $printer->selectPrintMode();
        $printer->feed();

        // Logo (if exists)
        $logoPath = __DIR__ . '/../../public/assets/img/logo.png';
        if (file_exists($logoPath)) {
            try {
                $logo = EscposImage::load($logoPath);
                $printer->bitImage($logo);
                $printer->feed();
            } catch (\Throwable $e) {
                $this->logger->warning('Logo não pôde ser carregada: ' . $e->getMessage());
            }
        }

        // Order info
        $printer->setEmphasis(true);
        $printer->text("Pedido #" . $order->id . "\n");
        $printer->setEmphasis(false);
        $printer->text("Senha: " . $order->table_number . "\n");
        if (!empty($order->customer_name)) {
            $printer->text("Cliente: " . $order->customer_name . "\n");
        }
        $printer->text($order->created_at->format('d/m/Y H:i') . "\n");
        $printer->feed();

        // --- Separator ---
        $printer->text(str_repeat('-', 32) . "\n");
        $printer->feed();

        // --- Items ---
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $items = $order->items;
        $total = 0.0;

        foreach ($items as $orderItem) {
            $menuItem = $orderItem->menuItem;
            if (!$menuItem) continue;

            $name = $menuItem->name;
            $qty = (int) $orderItem->quantity;
            $price = (float) $orderItem->unit_price;
            $diningOption = $orderItem->dining_option ?? 'local';
            $notes = $orderItem->notes ?? '';
            $packagingCost = (float) $orderItem->packaging_cost;

            // Calculate item total with packaging
            $itemTotal = ($price * $qty) + $packagingCost;
            $total += $itemTotal;
            $packagingLabel = match ($diningOption) {
                'viagem_simples' => ' [Simples]',
                'viagem_vip'     => ' [VIP]',
                default          => '',
            };

            // Line: "2x Prato do Dia           40,00"
            $line = $qty . "x " . $name;
            // Truncate name to fit (max ~22 chars before the price)
            $maxNameLen = 22;
            $lineName = mb_strlen($name) > $maxNameLen
                ? mb_substr($name, 0, $maxNameLen - 1) . '…'
                : $name;
            $priceStr = 'R$ ' . number_format($itemTotal, 2, ',', '');

            // Build line with padding
            $itemLine = $qty . 'x ' . $lineName;
            $padding = 32 - mb_strlen($itemLine) - mb_strlen($priceStr);
            if ($padding < 1) $padding = 1;
            $itemLine .= str_repeat(' ', $padding) . $priceStr;

            $printer->text($itemLine . "\n");

            // Dining option label
            if ($packagingLabel) {
                $printer->text("   " . $packagingLabel . "\n");
            }

            // Notes
            if (!empty($notes)) {
                $printer->text("   Obs: " . $notes . "\n");
            }

            $printer->feed();
        }

        // --- Separator ---
        $printer->text(str_repeat('-', 32) . "\n");

        // --- Total ---
        $printer->setJustification(Printer::JUSTIFY_RIGHT);
        $printer->setEmphasis(true);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
        $printer->text("TOTAL: R$ " . number_format($total, 2, ',', '') . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(false);

        // --- Footer ---
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->feed(2);
        $printer->text("Obrigado pela preferência!\n");
        $printer->feed(2);

        // --- Cut ---
        $printer->cut();
    }
}
