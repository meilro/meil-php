<?php

declare(strict_types=1);

namespace Meil\Resource;

use Meil\HttpClient;

/**
 * Timestamps are UTC strings formatted "YYYY-MM-DD HH:MM:SS".
 *
 * @phpstan-type CreateAudienceParams array{name: string, domain: string, description?: string, visibility?: 'public'|'private'}
 * @phpstan-type Audience array{object: 'audience', id: string, name: string, description: string, visibility: 'public'|'private', domain: string|null, created_at: string}
 * @phpstan-type AudienceDetail array{object: 'audience', id: string, name: string, description: string, visibility: 'public'|'private', domain: string|null, created_at: string, contacts_count: int}
 * @phpstan-type AudienceList array{object: 'list', data: list<array{id: string, name: string, description: string, visibility: 'public'|'private', domain: string|null, created_at: string}>}
 * @phpstan-type AudienceDeleted array{object: 'audience', id: string, deleted: true}
 * @phpstan-type ContactListItem array{
 *     id: string,
 *     email: string,
 *     first_name: string,
 *     last_name: string,
 *     properties: array<string, mixed>,
 *     unsubscribed: bool,
 *     unsubscribed_at: string|null,
 *     created_at: string,
 * }
 * @phpstan-type ContactList array{object: 'list', has_more: bool, data: list<ContactListItem>}
 * @phpstan-type ListParams array{limit?: int, after?: string, before?: string}
 * @phpstan-type RequestOptions array{timeout?: int|float, max_retries?: int}
 */
final class Audiences
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * POST /audiences. "domain" must be one of your sending domains.
     *
     * @param CreateAudienceParams $params
     * @param RequestOptions $options
     *
     * @return Audience
     */
    public function create(array $params, array $options = []): array
    {
        /** @var Audience */
        return $this->http->request('POST', '/audiences', [], $params, null, $options)['data'];
    }

    /**
     * GET /audiences: all of them at once.
     *
     * @param RequestOptions $options
     *
     * @return AudienceList
     */
    public function list(array $options = []): array
    {
        /** @var AudienceList */
        return $this->http->request('GET', '/audiences', [], null, null, $options)['data'];
    }

    /**
     * GET /audiences/{id}, with "contacts_count".
     *
     * @param RequestOptions $options
     *
     * @return AudienceDetail
     */
    public function get(string $id, array $options = []): array
    {
        /** @var AudienceDetail */
        return $this->http->request('GET', '/audiences/' . HttpClient::segment($id), [], null, null, $options)['data'];
    }

    /**
     * DELETE /audiences/{id}. Contacts in other audiences stay there.
     *
     * @param RequestOptions $options
     *
     * @return AudienceDeleted
     */
    public function delete(string $id, array $options = []): array
    {
        /** @var AudienceDeleted */
        return $this->http->request('DELETE', '/audiences/' . HttpClient::segment($id), [], null, null, $options)['data'];
    }

    /**
     * GET /audiences/{id}/contacts: one page, newest first. "limit" is 1 to 1000.
     *
     * @param ListParams $params
     * @param RequestOptions $options
     *
     * @return ContactList
     */
    public function listContacts(string $id, array $params = [], array $options = []): array
    {
        $path = '/audiences/' . HttpClient::segment($id) . '/contacts';
        /** @var ContactList */
        return $this->http->request('GET', $path, $params, null, null, $options)['data'];
    }

    /**
     * Every contact of an audience, fetching pages as needed:
     * foreach ($meil->audiences->iterateContacts($id) as $contact) { ... }
     *
     * @param ListParams $params
     * @param RequestOptions $options
     *
     * @return \Generator<int, ContactListItem, mixed, void>
     */
    public function iterateContacts(string $id, array $params = [], array $options = []): \Generator
    {
        /** @var \Generator<int, ContactListItem, mixed, void> */
        return Paginator::iterate(fn (array $p): array => $this->listContacts($id, $p, $options), $params);
    }
}
