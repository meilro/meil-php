<?php

declare(strict_types=1);

namespace Meil\Resource;

use Meil\HttpClient;

/**
 * Timestamps are UTC strings formatted "YYYY-MM-DD HH:MM:SS".
 *
 * @phpstan-type CreateTemplateParams array{name: string, html: string, subject?: string, preview_text?: string, text?: string, domain?: string}
 * @phpstan-type UpdateTemplateParams array{name?: string, subject?: string, preview_text?: string, html?: string, text?: string, domain?: string}
 * @phpstan-type ContentIssue array{code: string, severity: 'high'|'medium'|'low', message: string, fix: string}
 * @phpstan-type Template array{
 *     object: 'template',
 *     id: string,
 *     name: string,
 *     subject: string,
 *     domain: string|null,
 *     preview_text: string|null,
 *     html: string,
 *     text: string,
 *     variables: list<string>,
 *     created_at: string,
 *     updated_at: string,
 * }
 * @phpstan-type TemplateSaved array{
 *     object: 'template',
 *     id: string,
 *     name: string,
 *     subject: string,
 *     domain: string|null,
 *     preview_text: string|null,
 *     html: string,
 *     text: string,
 *     variables: list<string>,
 *     created_at: string,
 *     updated_at: string,
 *     content_check: array{score: int, issues: list<ContentIssue>},
 * }
 * @phpstan-type TemplateList array{object: 'list', data: list<array{id: string, name: string, subject: string, domain: string|null, created_at: string, updated_at: string}>}
 * @phpstan-type TemplateDeleted array{object: 'template', id: string, deleted: true}
 * @phpstan-type RequestOptions array{timeout?: int|float, max_retries?: int}
 */
final class Templates
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * POST /templates. The answer includes its content check score.
     *
     * @param CreateTemplateParams $params
     * @param RequestOptions $options
     *
     * @return TemplateSaved
     */
    public function create(array $params, array $options = []): array
    {
        /** @var TemplateSaved */
        return $this->http->request('POST', '/templates', [], $params, null, $options)['data'];
    }

    /**
     * GET /templates: all of them, without their content.
     *
     * @param RequestOptions $options
     *
     * @return TemplateList
     */
    public function list(array $options = []): array
    {
        /** @var TemplateList */
        return $this->http->request('GET', '/templates', [], null, null, $options)['data'];
    }

    /**
     * GET /templates/{id}.
     *
     * @param RequestOptions $options
     *
     * @return Template
     */
    public function get(string $id, array $options = []): array
    {
        /** @var Template */
        return $this->http->request('GET', '/templates/' . HttpClient::segment($id), [], null, null, $options)['data'];
    }

    /**
     * PATCH /templates/{id}: only the fields sent change.
     *
     * @param UpdateTemplateParams $params
     * @param RequestOptions $options
     *
     * @return TemplateSaved
     */
    public function update(string $id, array $params, array $options = []): array
    {
        /** @var TemplateSaved */
        return $this->http->request('PATCH', '/templates/' . HttpClient::segment($id), [], $params, null, $options)['data'];
    }

    /**
     * DELETE /templates/{id}.
     *
     * @param RequestOptions $options
     *
     * @return TemplateDeleted
     */
    public function delete(string $id, array $options = []): array
    {
        /** @var TemplateDeleted */
        return $this->http->request('DELETE', '/templates/' . HttpClient::segment($id), [], null, null, $options)['data'];
    }
}
