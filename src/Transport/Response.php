<?php

declare(strict_types=1);

namespace Meil\Transport;

/** One HTTP answer, as a transport hands it back. */
final class Response
{
    /**
     * @param array<string, string> $headers Names in lower case.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
