<?php

declare(strict_types=1);

namespace Meil\Tests;

use Meil\Exception\AuthenticationException;
use Meil\Exception\ConflictException;
use Meil\Exception\MeilException;
use Meil\Exception\NotFoundException;
use Meil\Exception\PermissionException;
use Meil\Exception\RateLimitException;
use Meil\Exception\ServerException;
use Meil\Exception\ValidationException;
use Meil\Transport\Response;
use PHPUnit\Framework\Attributes\DataProvider;

final class ErrorTest extends TestCase
{
    private function failure(Response $reply): MeilException
    {
        try {
            $this->client(new FakeTransport($reply), ['max_retries' => 0])->emails->get('e1');
        } catch (MeilException $e) {
            return $e;
        }
        self::fail('expected an exception');
    }

    /** @return array<string, array{int, string, class-string<MeilException>}> */
    public static function statuses(): array
    {
        return [
            '401' => [401, 'invalid_api_key', AuthenticationException::class],
            '403' => [403, 'insufficient_permission', PermissionException::class],
            '404' => [404, 'not_found', NotFoundException::class],
            '409' => [409, 'invalid_idempotent_request', ConflictException::class],
            '422' => [422, 'validation_error', ValidationException::class],
            '429' => [429, 'daily_quota_exceeded', RateLimitException::class],
            '500' => [500, 'internal_error', ServerException::class],
            '503' => [503, 'mail_disabled', ServerException::class],
        ];
    }

    /** @param class-string<MeilException> $class */
    #[DataProvider('statuses')]
    public function testMapsStatusesToExceptions(int $status, string $name, string $class): void
    {
        $e = $this->failure(FakeTransport::error($status, $name));
        self::assertInstanceOf($class, $e);
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertSame($name, $e->getErrorName());
        self::assertSame($status, $e->getStatusCode());
        self::assertSame($status, $e->getCode());
        self::assertSame("Message for {$name}.", $e->getMessage());
        self::assertNull($e->getId());
    }

    public function testUsesTheBaseClassForOtherStatuses(): void
    {
        $e = $this->failure(FakeTransport::error(400, 'invalid_json'));
        self::assertSame(MeilException::class, $e::class);
        self::assertSame('invalid_json', $e->getErrorName());
    }

    public function testTreatsA429InvalidApiKeyAsAuthentication(): void
    {
        $e = $this->failure(FakeTransport::error(429, 'invalid_api_key'));
        self::assertInstanceOf(AuthenticationException::class, $e);
        self::assertSame(429, $e->getStatusCode());
    }

    public function testCarriesTheIdOfARejectedSend(): void
    {
        $e = $this->failure(FakeTransport::error(422, 'send_rejected', ['id' => '7e3ff437']));
        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('send_rejected', $e->getErrorName());
        self::assertSame('7e3ff437', $e->getId());
    }

    public function testReadsRetryAfter(): void
    {
        $e = $this->failure(FakeTransport::error(429, 'rate_limit_exceeded', [], ['Retry-After' => '10']));
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertSame(10, $e->getRetryAfter());
        self::assertSame('10', $e->getHeaders()['retry-after']);
    }

    public function testSurvivesACloudflareHtmlPage(): void
    {
        $e = $this->failure(new Response(502, ['content-type' => 'text/html'], '<!DOCTYPE html><title>502 Bad gateway</title>'));
        self::assertInstanceOf(ServerException::class, $e);
        self::assertSame(502, $e->getStatusCode());
        self::assertSame('invalid_response', $e->getErrorName());
        self::assertStringContainsString('HTTP 502', $e->getMessage());
    }

    public function testRefusesA200ThatIsNotJson(): void
    {
        self::assertSame('invalid_response', $this->failure(new Response(200, [], '<html>'))->getErrorName());
    }

    public function testUnderReviewFromCreateIsAResult(): void
    {
        $held = ['message' => 'The broadcast is held for review.', 'name' => 'under_review'];
        $t = new FakeTransport(FakeTransport::json(['id' => 'b9', 'error' => $held], 202));
        $result = $this->client($t)->broadcasts->create(['audience_id' => 'a1', 'from' => 'n@example.org', 'subject' => 'S', 'html' => 'H', 'send' => true]);
        self::assertSame(
            ['object' => 'broadcast', 'id' => 'b9', 'status' => 'under_review', 'under_review' => true, 'message' => $held['message']],
            $result,
        );
    }

    public function testUnderReviewFromSendTakesTheIdFromThePath(): void
    {
        $t = new FakeTransport(FakeTransport::json(['error' => ['message' => 'Held.', 'name' => 'under_review']], 202));
        $result = $this->client($t)->broadcasts->send('b5');
        self::assertSame('under_review', $result['status']);
        self::assertSame('b5', $result['id']);
    }

    public function testAQueuedBroadcastComesBackAsIs(): void
    {
        $queued = ['object' => 'broadcast', 'id' => 'b5', 'status' => 'queued', 'recipients' => 842];
        $t = new FakeTransport(FakeTransport::json($queued));
        self::assertSame($queued, $this->client($t)->broadcasts->send('b5'));
    }
}
