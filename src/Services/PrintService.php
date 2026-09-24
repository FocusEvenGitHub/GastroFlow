<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use App\Money;
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Psr\Log\LoggerInterface;
use App\Settings;

class PrintService
{
    /**
     * Shared so printOrder() and printTestPage() answer an unconfigured printer with the
     * same message — they used to disagree, one throwing and one silently returning
     * (spec 038). Operator-facing, so Portuguese, per CLAUDE.md's language split.
     */
    public const ERROR_NO_IP = 'IP da impressora não configurado.';

    /**
     * Quantas falhas permanentes seguidas bloqueiam a impressão (spec 039).
     *
     * Conta JOBS que falharam de vez, não tentativas: a spec 008 dá max_attempts=3 com
     * backoff 2s/4s/8s, então um único job leva ~14s até falhar permanentemente. Contar
     * tentativas bloquearia a impressora por causa de UM pedido, o que não é evidência de
     * impressora fora.
     */
    public const MAX_CONSECUTIVE_FAILURES = 3;

    /**
     * O contador é persistido, não derivado da tabela jobs.
     *
     * Derivar seria mais simples e está errado: bin/jobs-prune (spec 033) apaga os jobs
     * concluídos e preserva os falhos, então depois da retenção uma sequência real de
     * "falha, falha, sucesso, falha" vira "falha, falha, falha" na tabela — o sucesso que
     * quebrava a sequência foi podado, e o estado derivado afirmaria três falhas
     * consecutivas que nunca existiram.
     */
    private const KEY_FAILURES = 'printer_consecutive_failures';
    private const KEY_BLOCKED_AT = 'printer_blocked_at';
    private const KEY_LAST_FAILED_ORDER = 'printer_last_failed_order';
    private const KEY_LAST_ERROR = 'printer_last_error';

    /** @var callable|null */
    private $connectorFactory;

    /**
     * @param callable|null $connectorFactory Optional factory
     *        fn(string $ip, int $port): PrintConnector used to create the
     *        network connector. Injected for testability (a real printer must
     *        never be required by the automated tests).
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Settings $settings,
        private readonly PricingService $pricingService,
        ?callable $connectorFactory = null,
    ) {
        $this->connectorFactory = $connectorFactory;
    }

    /**
     * Create the printer connector for the given host/port.
     */
    private function makeConnector(string $ip, int $port): \Mike42\Escpos\PrintConnectors\PrintConnector
    {
        if ($this->connectorFactory !== null) {
            return ($this->connectorFactory)($ip, $port);
        }
        return new NetworkPrintConnector($ip, $port, 5);
    }

    /**
     * Get printer connection settings.
     */
    /**
     * Registra uma falha PERMANENTE de impressão e retorna o total consecutivo.
     * Ao atingir MAX_CONSECUTIVE_FAILURES, a impressão fica bloqueada (spec 039).
     */
    public function recordPrintFailure(?int $orderId = null, ?string $error = null): int
    {
        $failures = $this->consecutiveFailures() + 1;

        Setting::setValue(self::KEY_FAILURES, (string) $failures);
        Setting::setValue(self::KEY_LAST_FAILED_ORDER, $orderId === null ? null : (string) $orderId);
        Setting::setValue(self::KEY_LAST_ERROR, $error);

        if ($failures >= self::MAX_CONSECUTIVE_FAILURES && !$this->isPrintingBlocked()) {
            Setting::setValue(self::KEY_BLOCKED_AT, date('Y-m-d H:i:s'));
            $this->logger->error(sprintf(
                'Impressão bloqueada após %d falhas consecutivas. Operador precisa reativar.',
                $failures
            ));
        }

        return $failures;
    }

    /** Uma impressão bem-sucedida quebra a sequência e destrava (spec 039). */
    public function recordPrintSuccess(): void
    {
        if ($this->consecutiveFailures() === 0 && !$this->isPrintingBlocked()) {
            return;
        }

        $this->clearFailureState();
    }

    /** Reativação manual pelo operador. Não mexe em nenhum job (spec 033 os preserva). */
    public function resetPrinterFailures(): void
    {
        $this->clearFailureState();
        $this->logger->info('Impressão reativada manualmente pelo operador.');
    }

    public function isPrintingBlocked(): bool
    {
        return !empty(Setting::getValue(self::KEY_BLOCKED_AT));
    }

    /**
     * Estado que a cozinha e o caixa consultam por polling.
     *
     * @return array{blocked: bool, consecutive_failures: int, max_failures: int, blocked_at: ?string, last_failed_order_id: ?int, last_error: ?string}
     */
    public function getPrinterStatus(): array
    {
        $lastOrder = Setting::getValue(self::KEY_LAST_FAILED_ORDER);

        return [
            'blocked'              => $this->isPrintingBlocked(),
            'consecutive_failures' => $this->consecutiveFailures(),
            'max_failures'         => self::MAX_CONSECUTIVE_FAILURES,
            'blocked_at'           => Setting::getValue(self::KEY_BLOCKED_AT) ?: null,
            'last_failed_order_id' => ($lastOrder === null || $lastOrder === '') ? null : (int) $lastOrder,
            'last_error'           => Setting::getValue(self::KEY_LAST_ERROR) ?: null,
        ];
    }

    private function consecutiveFailures(): int
    {
        return (int) (Setting::getValue(self::KEY_FAILURES) ?: 0);
    }

    private function clearFailureState(): void
    {
        Setting::setValue(self::KEY_FAILURES, '0');
        Setting::setValue(self::KEY_BLOCKED_AT, null);
        Setting::setValue(self::KEY_LAST_FAILED_ORDER, null);
        Setting::setValue(self::KEY_LAST_ERROR, null);
    }

    /**
     * The configured printer as "ip:port", or null when no IP is set.
     *
     * Exposed so a controller can name the address in an operator-facing error without
     * reaching into the raw exception message, which may carry internals (spec 038).
     */
    public function getConfiguredAddress(): ?string
    {
        $config = $this->getPrinterConfig();

        if (empty($config['ip'])) {
            return null;
        }

        return $config['ip'] . ':' . $config['port'];
    }

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
     *
     * Logs the outcome and, on failure, rethrows the exception so the caller
     * (e.g. PrintOrderJob -> JobService) can decide whether to retry. Printing
     * is best-effort at the point where the order is created, but a failed print
     * must NOT be silently treated as success by the queue.
     */
    public function printOrder(Order $order, array $context = []): void
    {
        $config = $this->getPrinterConfig();

        $ctx = $this->labelContext($context, $config);

        // Returning here used to mark the job 'completed', so an order was recorded as
        // printed when nothing was printed and bin/jobs-status showed nothing wrong.
        // A ticket was asked for and not produced: that is a failure (spec 038). A
        // restaurant deliberately running without a printer sends print_ticket=false,
        // which never enqueues a job at all.
        if (empty($config['ip'])) {
            $this->logger->error('Print failed' . $ctx . ' order=' . $order->id . ' ' . self::ERROR_NO_IP);
            throw new \RuntimeException(self::ERROR_NO_IP);
        }

        try {
            $connector = $this->makeConnector($config['ip'], $config['port']);
            $printer = new Printer($connector);

            $this->buildReceipt($printer, $order, $config['name']);

            $printer->close();
            $this->logger->info('Print success' . $ctx . ' order=' . $order->id . ' printer=' . $config['ip'] . ':' . $config['port']);
        } catch (\Throwable $e) {
            $this->logger->error('Print failed' . $ctx . ' order=' . $order->id . ' printer=' . $config['ip'] . ':' . $config['port'] . ' error="' . $e->getMessage() . '"');
            throw $e;
        }
    }

    /**
     * Build a log suffix with useful job context, when available.
     */
    private function labelContext(array $context, array $config): string
    {
        $parts = [];
        if (!empty($context['job_id'])) {
            $parts[] = ' print_job=' . $context['job_id'];
        }
        if (isset($context['attempt'])) {
            $parts[] = ' attempt=' . $context['attempt'] . '/' . $context['max_attempts'];
        }
        return implode('', $parts);
    }

    /**
     * Print a test page to verify printer connectivity.
     */
    public function printTestPage(): void
    {
        $config = $this->getPrinterConfig();

        if (empty($config['ip'])) {
            throw new \RuntimeException(self::ERROR_NO_IP);
        }

        $connector = $this->makeConnector($config['ip'], $config['port']);
        $printer = new Printer($connector);

        $printer->initialize();
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT);
        $printer->text($config['name'] . "\n");
        $printer->selectPrintMode();
        $printer->feed();

        // Logo
        $logoPath = $this->settings->getLogoPath();
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
        $logoPath = $this->settings->getLogoPath();
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
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT);
        $printer->text("Senha: " . $order->order_number . "\n");
        $printer->selectPrintMode();
        $printer->setEmphasis(false);
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
        $lineTotals = [];

        foreach ($items as $orderItem) {
            // item_name is the order-time snapshot (spec 023) — printing
            // (including a reprint, long after the order) never depends on
            // the menu item's current name.
            $name = $orderItem->item_name;
            $qty = (int) $orderItem->quantity;
            $price = Money::fromReais($orderItem->unit_price);
            $diningOption = $orderItem->dining_option ?? 'local';
            $notes = $orderItem->notes ?? '';
            $packagingCost = Money::fromReais($orderItem->packaging_cost);

            // Item total with packaging, via PricingService (spec 026) —
            // exact integer-cent arithmetic (spec 021, avoids float drift
            // across many items).
            $itemTotal = $this->pricingService->lineTotal($price, $qty, $packagingCost);
            $lineTotals[] = $itemTotal;
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
            $priceStr = 'R$ ' . $itemTotal->format();

            // Build line with padding
            $itemLine = $qty . 'x ' . $lineName;
            $padding = 32 - mb_strlen($itemLine) - mb_strlen($priceStr);
            if ($padding < 1) {
                $padding = 1;
            }
            $itemLine .= str_repeat(' ', $padding) . $priceStr;

            $printer->text($itemLine . "\n");

            // Build-your-own dish add-ons (spec 030). An unsaved OrderItem
            // (never persisted) has no rows to lazy-load.
            $components = ($orderItem->exists || $orderItem->relationLoaded('components'))
                ? $orderItem->components
                : collect();
            foreach ($components as $component) {
                $printer->text("   + " . (int) $component->quantity . "x " . $component->item_name . "\n");
            }

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
        $total = $this->pricingService->orderTotal($lineTotals);
        $printer->setJustification(Printer::JUSTIFY_RIGHT);
        $printer->setEmphasis(true);
        $printer->selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
        $printer->text("TOTAL: R$ " . $total->format() . "\n");
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
