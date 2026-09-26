<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\RequestContext;
use PHPUnit\Framework\TestCase;

class RequestContextTest extends TestCase
{
    public function testStartsWithNoRequestIdOrUserId(): void
    {
        $context = new RequestContext();

        $this->assertNull($context->getRequestId());
        $this->assertNull($context->getUserId());
    }

    public function testSetRequestIdIsReadableBack(): void
    {
        $context = new RequestContext();
        $context->setRequestId('abc123');

        $this->assertSame('abc123', $context->getRequestId());
    }

    public function testSetUserIdIsReadableBack(): void
    {
        $context = new RequestContext();
        $context->setUserId(7);

        $this->assertSame(7, $context->getUserId());
    }
}
