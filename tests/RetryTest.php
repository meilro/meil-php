<?php

declare(strict_types=1);

namespace Meil\Tests;

use Meil\Exception\ConflictException;
use Meil\Exception\ConnectionException;
use Meil\Exception\MeilException;
use Meil\Exception\RateLimitException;
use Meil\Exception\ServerException;
use Meil\Exception\ValidationException;
use Meil\Transport\Response;
use PHPUnit\Framework\Attributes\DataProvider;

final class RetryTest extends TestCase
{
    private const SEND = ['from' => 'a@x.ro', 'to' => 'b@y.ro', 'subject' => 'S', 'html' => 'H'];

    public function testWaitsOutRetryAfterOnRateLimit(): void
    {
        $t = new FakeTransport(
            FakeTransport::error(429, 'rate_limit_exceeded', [], ['Retry-After' => '1']),
            FakeTransport::error(429, 'rate_limit_exceeded', [], ['Retry-After' => '10']),
            FakeTransport::json(['id' => 'e1']),
        );
        self::assertSame(['id' => 'e1'], $this->client($t)->emails->send(self::SEND));
        self::assertCount(3, $t->requests);
        self::assertSame([1.0, 10.0], $this->sleeps);
    }

    /** @return array<string, array{string}> */
    public static function quotas(): array
    {
        return ['daily' => ['daily_quota_exceeded'], 'monthly' => ['monthly_quota_exceeded'], 'pending' => ['previous_broadcast_pending']];
    }

    #[DataProvider('quotas')]
    public function testNeverRetriesQuotas(string $name): void
    {
        $t = new FakeTransport(FakeTransport::error(429, $name));
        try {
            $this->client($t)->emails->send(self::SEND);
            self::fail('expected an exception');
        } catch (RateLimitException $e) {
            self::assertSame($name, $e->getErrorName());
            self::assertNull($e->getRetryAfter());
        }
        self::assertCount(1, $t->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testRetriesGatewayErrorsWithExponentialBackoff(): void
    {
        $t = new FakeTransport(
            FakeTransport::error(503, 'mail_disabled'),
            new Response(522, [], '<html>'),
            FakeTransport::json(['id' => 'b1']),
        );
        self::assertSame(['id' => 'b1'], $this->client($t)->broadcasts->get('b1'));
        self::assertCount(2, $this->sleeps);
        self::assertEqualsWithDelta(0.5, $this->sleeps[0], 0.125);
        self::assertEqualsWithDelta(1.0, $this->sleeps[1], 0.25);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $t = new FakeTransport(new Response(502, [], 'bad'), new Response(502, [], 'bad'), new Response(502, [], 'bad'));
        try {
            $this->client($t)->audiences->list();
            self::fail('expected an exception');
        } catch (ServerException $e) {
            self::assertSame(502, $e->getStatusCode());
            self::assertSame('invalid_response', $e->getErrorName());
        }
        self::assertCount(3, $t->requests);
    }

    public function testMaxRetriesZeroPerClientAndPerCall(): void
    {
        $a = new FakeTransport(new Response(503, [], ''));
        try {
            $this->client($a, ['max_retries' => 0])->audiences->list();
        } catch (ServerException) {
        }
        self::assertCount(1, $a->requests);

        $b = new FakeTransport(new Response(503, [], ''));
        try {
            $this->client($b)->audiences->list(['max_retries' => 0]);
        } catch (ServerException) {
        }
        self::assertCount(1, $b->requests);
    }

    public function testDoesNotRetry500(): void
    {
        $t = new FakeTransport(FakeTransport::error(500, 'internal_error'));
        $this->expectException(ServerException::class);
        try {
            $this->client($t)->templates->list();
        } finally {
            self::assertCount(1, $t->requests);
        }
    }

    public function testRetriesNetworkErrorsOnDelete(): void
    {
        $t = new FakeTransport(new ConnectionException('reset', 'connection_error'), FakeTransport::json(['deleted' => true]));
        self::assertSame(['deleted' => true], $this->client($t)->templates->delete('t1'));
        self::assertCount(2, $t->requests);
    }

    public function testThrowsConnectionExceptionOnceRetriesAreSpent(): void
    {
        $t = new FakeTransport(
            new ConnectionException('timeout', 'timeout'),
            new ConnectionException('timeout', 'timeout'),
            new ConnectionException('timeout', 'timeout'),
        );
        try {
            $this->client($t)->emails->get('e1');
            self::fail('expected an exception');
        } catch (ConnectionException $e) {
            self::assertSame('timeout', $e->getErrorName());
            self::assertNull($e->getStatusCode());
        }
        self::assertCount(3, $t->requests);
    }

    public function testNeverRetriesAPostWithoutIdempotencyKey(): void
    {
        $replies = [
            new ConnectionException('reset', 'connection_error'),
            new Response(503, [], ''),
            FakeTransport::error(429, 'rate_limit_exceeded', [], ['Retry-After' => '1']),
        ];
        foreach ($replies as $reply) {
            $t = new FakeTransport($reply);
            try {
                $this->client($t)->contacts->add(['audience_id' => 'a1', 'email' => 'c@y.ro']);
                self::fail('expected an exception');
            } catch (MeilException) {
            }
            self::assertCount(1, $t->requests);
            self::assertArrayNotHasKey('idempotency-key', $t->requests[0]['headers']);
        }
    }

    public function testRetriesASendWithTheSameIdempotencyKey(): void
    {
        $t = new FakeTransport(new ConnectionException('reset', 'connection_error'), FakeTransport::json(['id' => 'e1']));
        self::assertSame(['id' => 'e1'], $this->client($t)->emails->send(self::SEND));
        self::assertCount(2, $t->requests);
        self::assertNotEmpty($t->requests[0]['headers']['idempotency-key']);
        self::assertSame($t->requests[0]['headers']['idempotency-key'], $t->requests[1]['headers']['idempotency-key']);
        self::assertSame($t->requests[0]['raw'], $t->requests[1]['raw']);
    }

    public function testRetriesConcurrentIdempotentRequests(): void
    {
        $t = new FakeTransport(
            FakeTransport::error(409, 'concurrent_idempotent_requests'),
            FakeTransport::json(['id' => 'e1'], 200, ['Idempotent-Replayed' => 'true']),
        );
        self::assertSame(['id' => 'e1'], $this->client($t)->emails->send(self::SEND, ['idempotency_key' => 'order-1']));
        self::assertGreaterThanOrEqual(1.0, $this->sleeps[0]);
    }

    public function testDoesNotRetryInvalidIdempotentRequest(): void
    {
        $t = new FakeTransport(FakeTransport::error(409, 'invalid_idempotent_request'));
        try {
            $this->client($t)->emails->send(self::SEND, ['idempotency_key' => 'order-1']);
            self::fail('expected an exception');
        } catch (ConflictException $e) {
            self::assertSame('invalid_idempotent_request', $e->getErrorName());
        }
        self::assertCount(1, $t->requests);
    }

    public function testDoesNotRetrySendRejected(): void
    {
        $t = new FakeTransport(FakeTransport::error(422, 'send_rejected', ['id' => 'e7']));
        try {
            $this->client($t)->emails->send(self::SEND);
            self::fail('expected an exception');
        } catch (ValidationException $e) {
            self::assertSame('e7', $e->getId());
        }
        self::assertCount(1, $t->requests);
    }

    public function testDoesNotWaitOutALongRetryAfter(): void
    {
        $t = new FakeTransport(FakeTransport::error(429, 'rate_limit_exceeded', [], ['Retry-After' => '3600']));
        $this->expectException(RateLimitException::class);
        $this->client($t)->emails->get('e1');
    }
}
