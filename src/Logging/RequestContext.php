<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * Holds the correlation id (and, once authenticated, the user id) for the lifetime of one HTTP
 * request. Autowired class instances are shared within a single PHP-DI container build (spec
 * 042), and the container itself is rebuilt fresh per request (App::get()), so this object is
 * effectively request-scoped without needing an explicit singleton binding.
 */
class RequestContext
{
    private ?string $requestId = null;
    private ?int $userId = null;

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }
}
