<?php

declare(strict_types=1);

namespace Meil\Tests;

use Meil\Exception\ConnectionException;
use Meil\Transport\CurlTransport;

/** The real cURL transport, against PHP's built-in server on localhost. */
final class CurlTransportTest extends \PHPUnit\Framework\TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $cmd  = [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/echo-server.php'];
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::$server = $proc === false ? null : $proc;
        self::$base   = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100_000);
        }
        self::markTestSkipped('Could not start the PHP built-in server.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function testSendsHeadersAndBodyAndReadsTheAnswer(): void
    {
        $response = (new CurlTransport())->send('POST', self::$base . '/emails?x=1', [
            'Authorization'   => 'Bearer k',
            'Idempotency-Key' => 'order-1',
            'Content-Type'    => 'application/json',
        ], '{"a":1}', 5);

        self::assertSame(201, $response->status);
        self::assertSame('7', $response->headers['retry-after']);
        self::assertSame('application/json', $response->headers['content-type']);
        $echo = json_decode($response->body, true);
        self::assertSame(['POST', '/emails?x=1', 'Bearer k', 'order-1', '{"a":1}'], [$echo['method'], $echo['uri'], $echo['authorization'], $echo['idempotency'], $echo['body']]);
    }

    public function testAPostWithoutABodySendsContentLengthZero(): void
    {
        $response = (new CurlTransport())->send('POST', self::$base . '/broadcasts/b1/send', [], null, 5);
        $echo = json_decode($response->body, true);
        self::assertSame('POST', $echo['method']);
        self::assertSame('0', (string) $echo['content_length']);
    }

    public function testDeleteAndGetWithoutBody(): void
    {
        foreach (['GET', 'DELETE'] as $method) {
            $echo = json_decode((new CurlTransport())->send($method, self::$base . '/x', [], null, 5)->body, true);
            self::assertSame($method, $echo['method']);
            self::assertSame('', $echo['body']);
        }
    }

    public function testReturnsNonJsonErrorPages(): void
    {
        $response = (new CurlTransport())->send('GET', self::$base . '/html', [], null, 5);
        self::assertSame(502, $response->status);
        self::assertSame('<html>Bad gateway</html>', $response->body);
    }

    public function testTimesOut(): void
    {
        try {
            (new CurlTransport())->send('GET', self::$base . '/slow', [], null, 0.5);
            self::fail('expected a timeout');
        } catch (ConnectionException $e) {
            self::assertSame('timeout', $e->getErrorName());
        }
    }

    public function testReportsConnectionErrors(): void
    {
        try {
            (new CurlTransport())->send('GET', 'http://127.0.0.1:' . self::freePort() . '/', [], null, 2);
            self::fail('expected a connection error');
        } catch (ConnectionException $e) {
            self::assertSame('connection_error', $e->getErrorName());
            self::assertNull($e->getStatusCode());
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
