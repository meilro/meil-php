<?php

declare(strict_types=1);

namespace Meil\Tests;

use Meil\Exception\ConnectionException;
use Meil\Transport\Response;
use Meil\Transport\TransportInterface;

/** Answers with queued replies in order and records every request. */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{method: string, url: string, path: string, headers: array<string, string>, body: mixed, raw: ?string, timeout: float}> */
    public array $requests = [];

    /** @var list<Response|ConnectionException> */
    private array $replies;

    public function __construct(Response|ConnectionException ...$replies)
    {
        $this->replies = array_values($replies);
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'path'    => substr($url, strlen('https://api.meil.ro')),
            'headers' => array_change_key_case($headers, CASE_LOWER),
            'body'    => $body === null ? null : json_decode($body, true),
            'raw'     => $body,
            'timeout' => $timeout,
        ];
        $reply = array_shift($this->replies);
        if ($reply === null) {
            throw new \LogicException("Unexpected request: {$method} {$url}");
        }
        if ($reply instanceof ConnectionException) {
            throw $reply;
        }
        return $reply;
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, array_change_key_case($headers, CASE_LOWER) + ['content-type' => 'application/json'], (string) json_encode($body));
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $name, array $extra = [], array $headers = []): Response
    {
        return self::json($extra + ['error' => ['message' => "Message for {$name}.", 'name' => $name]], $status, $headers);
    }
}
