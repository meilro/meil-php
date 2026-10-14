<?php

declare(strict_types=1);

namespace Meil\Transport;

use Meil\Exception\ConnectionException;

/**
 * Sends one HTTP request. The default is CurlTransport; tests use a fake one,
 * and you can pass your own (for example over a PSR-18 client) as the
 * "transport" option.
 */
interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws ConnectionException When no answer came back: getErrorName() is "timeout" or "connection_error".
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
