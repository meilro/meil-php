<?php

declare(strict_types=1);

namespace Meil\Resource;

use Meil\HttpClient;

/**
 * @phpstan-type CheckContentParams array{
 *     from?: string,
 *     subject?: string,
 *     preview_text?: string,
 *     html?: string,
 *     text?: string,
 *     template_id?: string,
 *     audience_id?: string,
 *     variables?: array<string, string|int|float>,
 * }
 * @phpstan-type ContentCheck array{
 *     object: 'content_check',
 *     score: int,
 *     issues: list<array{code: string, severity: 'high'|'medium'|'low', message: string, fix: string}>,
 * }
 * @phpstan-type RequestOptions array{timeout?: int|float, max_retries?: int}
 */
final class Content
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * POST /content/check: scores an email out of 100. Sends nothing.
     *
     * @param CheckContentParams $params
     * @param RequestOptions $options
     *
     * @return ContentCheck
     */
    public function check(array $params, array $options = []): array
    {
        if (isset($params['variables']) && $params['variables'] === []) {
            $params['variables'] = new \stdClass();
        }
        /** @var ContentCheck */
        return $this->http->request('POST', '/content/check', [], $params, null, $options)['data'];
    }
}
