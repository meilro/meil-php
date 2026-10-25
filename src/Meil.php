<?php

declare(strict_types=1);

namespace Meil;

use Meil\Resource\Audiences;
use Meil\Resource\Broadcasts;
use Meil\Resource\Contacts;
use Meil\Resource\Content;
use Meil\Resource\Emails;
use Meil\Resource\Templates;
use Meil\Transport\TransportInterface;

/**
 * The meil.ro API client.
 *
 *     $meil = new Meil\Meil('meil_...');
 *     $meil->emails->send([
 *         'from' => 'Shop <hello@example.com>',
 *         'to' => 'ana@example.com',
 *         'subject' => 'Hi',
 *         'html' => '<p>Hi</p>',
 *     ]);
 */
final class Meil
{
    public const VERSION = '0.3.0';

    public readonly Emails $emails;
    public readonly Audiences $audiences;
    public readonly Contacts $contacts;
    public readonly Broadcasts $broadcasts;
    public readonly Templates $templates;
    public readonly Content $content;

    /**
     * @param string|null $apiKey Defaults to the MEIL_API_KEY environment variable.
     * @param array{
     *     base_url?: string,
     *     timeout?: int|float,
     *     max_retries?: int,
     *     transport?: TransportInterface,
     *     sleep?: callable(float): void,
     * } $options base_url defaults to https://api.meil.ro; timeout is in seconds
     *     per attempt (30); max_retries defaults to 2, 0 turns retries off;
     *     transport replaces cURL; sleep replaces usleep() between retries (for tests).
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $apiKey ??= self::env('MEIL_API_KEY');
        if ($apiKey === null || $apiKey === '') {
            throw new \InvalidArgumentException('Missing API key. Pass it to new Meil($key) or set MEIL_API_KEY.');
        }
        $http = new HttpClient($apiKey, $options);
        $this->emails     = new Emails($http);
        $this->audiences  = new Audiences($http);
        $this->contacts   = new Contacts($http);
        $this->broadcasts = new Broadcasts($http);
        $this->templates  = new Templates($http);
        $this->content    = new Content($http);
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
