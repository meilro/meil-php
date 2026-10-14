<?php

declare(strict_types=1);

namespace Meil\Exception;

/**
 * Every failed call throws a MeilException, or one of its subclasses chosen by
 * the HTTP status. getErrorName() is the API's stable error name (such as
 * "rate_limit_exceeded"); getMessage() is English text for people.
 */
class MeilException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers Response headers, names in lower case.
     */
    public function __construct(
        string $message,
        private readonly string $errorName,
        private readonly ?int $statusCode = null,
        private readonly ?string $id = null,
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    /** The API's error name, e.g. "invalid_api_key". Stable: branch on this. */
    public function getErrorName(): string
    {
        return $this->errorName;
    }

    /** HTTP status, or null when no answer came back (network error, timeout). */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /** The object the error is about, when the API names one (send_rejected). */
    public function getId(): ?string
    {
        return $this->id;
    }

    /** @return array<string, string> Response headers, names in lower case. */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
