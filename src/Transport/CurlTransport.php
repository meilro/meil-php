<?php

declare(strict_types=1);

namespace Meil\Transport;

use Meil\Exception\ConnectionException;

/** The default transport: ext-curl, nothing else. */
final class CurlTransport implements TransportInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        if ($method === '') {
            throw new \InvalidArgumentException('The HTTP method is empty.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ConnectionException('Could not start a cURL request.', 'connection_error');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = [];
        $timeoutMs = max(1, (int) ceil($timeout * 1000));

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 10000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $received = [];   // a new response (after a 100 Continue): start over
                }
                return strlen($line);
            },
        ]);
        // A POST or PATCH without a body still says so (Content-Length: 0).
        if ($body !== null || $method === 'POST' || $method === 'PATCH') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body ?? '');
        }

        $text = curl_exec($handle);
        if (!is_string($text)) {
            $errno = curl_errno($handle);
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            throw new ConnectionException(
                $timedOut
                    ? sprintf('No answer from meil.ro within %s seconds.', $timeout)
                    : 'Could not reach meil.ro: ' . curl_error($handle),
                $timedOut ? 'timeout' : 'connection_error',
            );
        }

        return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $received, $text);
    }
}
