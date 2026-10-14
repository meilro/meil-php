<?php

declare(strict_types=1);

namespace Meil\Exception;

/** 429: per-second rate limit, or a daily or monthly quota. */
class RateLimitException extends MeilException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        string $errorName,
        ?int $statusCode = null,
        ?string $id = null,
        array $headers = [],
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $errorName, $statusCode, $id, $headers);
    }

    /** Seconds to wait before retrying, from Retry-After; null for quotas. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
