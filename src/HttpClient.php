<?php

declare(strict_types=1);

namespace Meil;

use Meil\Exception\AuthenticationException;
use Meil\Exception\ConflictException;
use Meil\Exception\ConnectionException;
use Meil\Exception\MeilException;
use Meil\Exception\NotFoundException;
use Meil\Exception\PermissionException;
use Meil\Exception\RateLimitException;
use Meil\Exception\ServerException;
use Meil\Exception\ValidationException;
use Meil\Transport\CurlTransport;
use Meil\Transport\TransportInterface;

/**
 * Sends requests, retries what is safe to retry, and turns error answers into
 * exceptions. Used by the resources; you normally do not call it yourself.
 *
 * @internal
 */
final class HttpClient
{
    private const DEFAULT_BASE_URL = 'https://api.meil.ro';
    private const DEFAULT_TIMEOUT = 30.0;
    private const DEFAULT_MAX_RETRIES = 2;
    /** A Retry-After longer than this is not waited out; the exception is thrown instead. */
    private const MAX_RETRY_AFTER = 60;
    /** 502, 503, 504 and Cloudflare's 520 to 524: the request may not have reached the API. */
    private const RETRY_STATUSES = [502, 503, 504, 520, 521, 522, 523, 524];

    private readonly string $baseUrl;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly TransportInterface $transport;
    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param array{base_url?: string, timeout?: int|float, max_retries?: int, transport?: TransportInterface, sleep?: callable(float): void} $options
     */
    public function __construct(private readonly string $apiKey, array $options = [])
    {
        $baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        if (!preg_match('#^https://#i', $baseUrl)) {
            throw new \InvalidArgumentException('base_url must start with https://; the API refuses plain http.');
        }
        $this->baseUrl    = $baseUrl;
        $this->timeout    = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES));
        $this->transport  = $options['transport'] ?? new CurlTransport();
        $this->sleep      = \Closure::fromCallable($options['sleep'] ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        });
    }

    /**
     * Sends a request with retries and returns the decoded 2xx answer.
     *
     * @param array<string, string|int|null> $query
     * @param array<mixed>|null $body
     * @param array{timeout?: int|float, max_retries?: int} $options
     *
     * @return array{status: int, data: array<mixed>}
     *
     * @throws MeilException
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null,
        array $options = [],
    ): array {
        $query = array_filter($query, static fn ($value): bool => $value !== null);
        $url   = $this->baseUrl . $path . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
            'User-Agent'    => 'meil-php/' . Meil::VERSION . ' PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = self::encode($body);
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        // GET, PATCH and DELETE can be repeated; a POST only with an Idempotency-Key,
        // which makes the server answer a repeat with the first response.
        $repeatable = $method !== 'POST' || $idempotencyKey !== null;
        $maxRetries = max(0, (int) ($options['max_retries'] ?? $this->maxRetries));
        $timeout    = (float) ($options['timeout'] ?? $this->timeout);

        for ($attempt = 0; ; $attempt++) {
            $canRetry = $repeatable && $attempt < $maxRetries;
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $timeout);
            } catch (ConnectionException $e) {
                if ($canRetry) {
                    ($this->sleep)(self::backoff($attempt));
                    continue;
                }
                throw $e;
            }

            $data = self::decode($response->body);
            if ($response->status >= 200 && $response->status < 300) {
                if ($data === null) {
                    throw new MeilException(
                        "meil.ro answered HTTP {$response->status} with a body that is not JSON.",
                        'invalid_response',
                        $response->status,
                        null,
                        $response->headers,
                    );
                }
                return ['status' => $response->status, 'data' => $data];
            }

            $error = self::toException($response->status, $data, $response->headers);
            if ($canRetry) {
                $wait = self::retryDelay($error, $attempt);
                if ($wait !== null) {
                    ($this->sleep)($wait);
                    continue;
                }
            }
            throw $error;
        }
    }

    /** Seconds to wait before retrying after this error, or null when it must not be retried. */
    private static function retryDelay(MeilException $error, int $attempt): ?float
    {
        $status = $error->getStatusCode() ?? 0;
        if ($error instanceof RateLimitException) {
            // Only the per-second limits clear on their own. Quotas
            // (daily_quota_exceeded, monthly_quota_exceeded, previous_broadcast_pending)
            // carry no Retry-After and are thrown at once.
            $after = $error->getRetryAfter();
            $transient = $error->getErrorName() === 'rate_limit_exceeded'
                || ($error->getErrorName() === 'invalid_response' && $after !== null);
            if (!$transient || $after === null || $after > self::MAX_RETRY_AFTER) {
                return null;
            }
            return (float) $after;
        }
        if ($status === 409 && $error->getErrorName() === 'concurrent_idempotent_requests') {
            // The first request with this key is still running: give it a moment.
            return max(1.0, self::backoff($attempt));
        }
        if (in_array($status, self::RETRY_STATUSES, true)) {
            $after = self::parseRetryAfter($error->getHeaders()['retry-after'] ?? null);
            if ($after !== null && $after > self::MAX_RETRY_AFTER) {
                return null;
            }
            return $after !== null ? (float) $after : self::backoff($attempt);
        }
        return null;
    }

    /** Exponential backoff with jitter: about 0.5 s, 1 s, 2 s, ... capped at 8 s. */
    private static function backoff(int $attempt): float
    {
        $base = min(8.0, 0.5 * 2 ** $attempt);
        return round($base * (0.75 + mt_rand() / mt_getrandmax() * 0.5), 3);
    }

    /**
     * @param array<mixed>|null $data
     * @param array<string, string> $headers
     */
    private static function toException(int $status, ?array $data, array $headers): MeilException
    {
        $error   = is_array($data['error'] ?? null) ? $data['error'] : [];
        $name    = is_string($error['name'] ?? null) ? $error['name'] : 'invalid_response';
        $message = is_string($error['message'] ?? null)
            ? $error['message']
            : "meil.ro answered HTTP {$status}" . ($data === null ? ' with a body that is not JSON.' : '.');
        $id = is_string($data['id'] ?? null) ? $data['id'] : null;

        // A bad key is an authentication problem, even when it comes back as a 429
        // (too many failed attempts from one IP).
        if ($status === 401 || $name === 'invalid_api_key' || $name === 'missing_api_key') {
            return new AuthenticationException($message, $name, $status, $id, $headers);
        }
        return match (true) {
            $status === 403 => new PermissionException($message, $name, $status, $id, $headers),
            $status === 404 => new NotFoundException($message, $name, $status, $id, $headers),
            $status === 409 => new ConflictException($message, $name, $status, $id, $headers),
            $status === 422 => new ValidationException($message, $name, $status, $id, $headers),
            $status === 429 => new RateLimitException(
                $message, $name, $status, $id, $headers, self::parseRetryAfter($headers['retry-after'] ?? null),
            ),
            $status >= 500 => new ServerException($message, $name, $status, $id, $headers),
            default => new MeilException($message, $name, $status, $id, $headers),
        };
    }

    /** Retry-After as seconds: either a number or an HTTP date. */
    private static function parseRetryAfter(?string $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $time = strtotime($value);
        return $time === false ? null : max(0, $time - time());
    }

    /** @return array<mixed>|null */
    private static function decode(string $text): ?array
    {
        if (trim($text) === '') {
            return null;
        }
        $data = json_decode($text, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<mixed> $body */
    private static function encode(array $body): string
    {
        try {
            // An empty body is still a JSON object, never "[]".
            return $body === []
                ? '{}'
                : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The request could not be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /** One path segment: an id or an email address (`+` becomes `%2B`). */
    public static function segment(string $value): string
    {
        return rawurlencode($value);
    }

    /** A random UUID v4. */
    public static function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
