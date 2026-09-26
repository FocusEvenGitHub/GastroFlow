<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\RequestContext;
use App\Logging\RequestIdProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class RequestIdProcessorTest extends TestCase
{
    private function makeRecord(): LogRecord
    {
        return new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Info,
            'a message',
        );
    }

    public function testAddsRequestIdToExtra(): void
    {
        $context = new RequestContext();
        $context->setRequestId('req-1');
        $processor = new RequestIdProcessor($context);

        $result = ($processor)($this->makeRecord());

        $this->assertSame('req-1', $result->extra['request_id']);
    }

    public function testAddsUserIdToExtraWhenAuthenticated(): void
    {
        $context = new RequestContext();
        $context->setRequestId('req-1');
        $context->setUserId(9);
        $processor = new RequestIdProcessor($context);

        $result = ($processor)($this->makeRecord());

        $this->assertSame(9, $result->extra['user_id']);
    }

    public function testOmitsUserIdKeyEntirelyWhenUnauthenticated(): void
    {
        $context = new RequestContext();
        $context->setRequestId('req-1');
        $processor = new RequestIdProcessor($context);

        $result = ($processor)($this->makeRecord());

        $this->assertArrayNotHasKey('user_id', $result->extra);
    }

    public function testOmitsRequestIdKeyWhenNeverSet(): void
    {
        $context = new RequestContext();
        $processor = new RequestIdProcessor($context);

        $result = ($processor)($this->makeRecord());

        $this->assertArrayNotHasKey('request_id', $result->extra);
    }
}
