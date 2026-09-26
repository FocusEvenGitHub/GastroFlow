<?php

declare(strict_types=1);

namespace App\Services;

use App\Logging\RequestContext;
use App\Models\AuditLog;

/**
 * Records a permanent, business-level trail of sensitive administrative actions (spec 043) —
 * deliberately separate from app.log/Monolog (spec 042), per the roadmap's own instruction
 * that technical logs and audit history stay conceptually distinct.
 *
 * A plain concrete class, not an interface: unlike EventPublisher (spec 041), nothing here
 * needs more than one implementation.
 *
 * $context is a REQUIRED constructor parameter, not optional-with-default — PHP-DI never
 * autowires a parameter that has a default (verified empirically in spec 042), so making this
 * optional would silently leave every HTTP-path call site with a null actor. The one caller
 * with no real request (bin/create-admin) passes a fresh, never-populated RequestContext
 * explicitly instead, which correctly reports a null actor rather than hiding the difference
 * between "nobody was logged in" and "this ran outside HTTP entirely".
 */
class AuditLogger
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    /**
     * @param array<string, mixed> $details Never a password, token, or Authorization value.
     */
    public function record(string $action, ?string $entityType, ?int $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id'     => $this->context->getUserId(),
            'username'    => $this->context->getUsername(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'details'     => $details,
        ]);
    }
}
