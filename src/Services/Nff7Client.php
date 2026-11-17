<?php

declare(strict_types=1);

namespace App\Services;

use App\Settings;
use Psr\Log\LoggerInterface;

/**
 * The only place that talks to NF-F7, the external fiscal service (spec 084).
 *
 * Today it knows one call, the connectivity test. Nothing in the order, kitchen or printing
 * paths uses this class: a fiscal failure can't lose an order because no order touches it.
 */
class Nff7Client
{
    private const MESSAGES = [
        'INCOMPLETE_CONFIG' => 'Configuração incompleta. Preencha NF_F7_URL e NF_F7_API_KEY no .env.',
        'UNREACHABLE' => 'Não foi possível falar com o serviço fiscal. Confira se ele está no ar e o NF_F7_URL no .env.',
        'KEY_REJECTED' => 'O serviço fiscal recusou a chave. Confira NF_F7_API_KEY no .env.',
        'UNEXPECTED_RESPONSE' => 'O serviço fiscal respondeu de um jeito inesperado. Veja o log dele.',
    ];

    /** @var callable(string, list<string>): array{status: int, body: string} */
    private $transport;

    /**
     * @param (callable(string, list<string>): array{status: int, body: string})|null $transport
     *        Test seam, like PrintService's connector factory. Throws on a transport failure.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? self::curlGet(...);
    }

    /**
     * Never throws and never returns the key, the raw transport error or NF-F7's body: the
     * message is fixed text per reason.
     *
     * @return array{enabled: bool, connected?: bool, client_name?: string, issuer_id?: int, version?: string, reason?: string, message?: string}
     */
    public function status(): array
    {
        $url = $this->settings->getNff7Url();
        $key = $this->settings->getNff7ApiKey();

        if ($url === null && $key === null) {
            return ['enabled' => false];
        }
        if ($url === null || $key === null) {
            return $this->failure('INCOMPLETE_CONFIG');
        }

        try {
            $reply = ($this->transport)($url . '/api/v1/ping', [
                'Authorization: Bearer ' . $key,
                'Accept: application/json',
            ]);
        } catch (\Throwable) {
            return $this->failure('UNREACHABLE');
        }

        if ($reply['status'] === 401) {
            return $this->failure('KEY_REJECTED', 401);
        }

        $data = $reply['status'] === 200 ? json_decode($reply['body'], true) : null;
        $name = $data['client']['name'] ?? null;
        $issuer = $data['client']['issuer_id'] ?? null;
        $version = $data['version'] ?? null;
        if (!is_string($name) || !is_int($issuer) || !is_string($version)) {
            return $this->failure('UNEXPECTED_RESPONSE', $reply['status']);
        }

        return [
            'enabled' => true,
            'connected' => true,
            'client_name' => $name,
            'issuer_id' => $issuer,
            'version' => $version,
        ];
    }

    /** @return array{enabled: true, connected: false, reason: string, message: string} */
    private function failure(string $reason, ?int $httpStatus = null): array
    {
        $this->logger->warning('fiscal.ping_failed', ['reason' => $reason, 'http_status' => $httpStatus]);

        return ['enabled' => true, 'connected' => false, 'reason' => $reason, 'message' => self::MESSAGES[$reason]];
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private static function curlGet(string $url, array $headers): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            // A redirect would carry the key to another host.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($handle);
        if (!is_string($body)) {
            throw new \RuntimeException('transport failure');
        }

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body];
    }
}
