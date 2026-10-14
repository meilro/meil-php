<?php

declare(strict_types=1);

namespace Meil\Resource;

use Meil\HttpClient;

/**
 * Timestamps are UTC strings formatted "YYYY-MM-DD HH:MM:SS".
 *
 * @phpstan-type AddContactParams array{audience_id: string, email: string, first_name?: string, last_name?: string, properties?: array<string, mixed>}
 * @phpstan-type Contact array{object: 'contact', id: string, email: string, subscribed: bool, properties?: array<string, mixed>, kept_properties?: list<string>}
 * @phpstan-type UpdateContactParams array{first_name?: string, last_name?: string, properties?: array<string, mixed>}
 * @phpstan-type ContactDetail array{
 *     object: 'contact',
 *     email: string,
 *     first_name: string,
 *     last_name: string,
 *     properties: array<string, mixed>,
 *     created_at: string,
 *     audiences: list<array{id: string, audience_id: string, subscribed: bool}>,
 * }
 * @phpstan-type ContactDeleted array{object: 'contact', email: string, audience_id?: string, deleted: true}
 * @phpstan-type RequestOptions array{timeout?: int|float, max_retries?: int}
 */
final class Contacts
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * POST /contacts: adds someone to an audience. An existing contact only
     * gains the properties it lacks; clashing values are kept and named in
     * "kept_properties". Never undoes an unsubscribe.
     *
     * @param AddContactParams $params
     * @param RequestOptions $options
     *
     * @return Contact
     */
    public function add(array $params, array $options = []): array
    {
        /** @var Contact */
        return $this->http->request('POST', '/contacts', [], self::objects($params), null, $options)['data'];
    }

    /**
     * GET /contacts/{email}, with the audiences it is in.
     *
     * @param RequestOptions $options
     *
     * @return ContactDetail
     */
    public function get(string $email, array $options = []): array
    {
        /** @var ContactDetail */
        return $this->http->request('GET', '/contacts/' . HttpClient::segment($email), [], null, null, $options)['data'];
    }

    /**
     * PATCH /contacts/{email}. Only what you send changes; a property set to
     * null is removed.
     *
     * @param UpdateContactParams $params
     * @param RequestOptions $options
     *
     * @return ContactDetail
     */
    public function update(string $email, array $params, array $options = []): array
    {
        $path = '/contacts/' . HttpClient::segment($email);
        /** @var ContactDetail */
        return $this->http->request('PATCH', $path, [], self::objects($params), null, $options)['data'];
    }

    /**
     * DELETE /contacts/{email}: from every audience, with its name and properties.
     *
     * @param RequestOptions $options
     *
     * @return ContactDeleted
     */
    public function delete(string $email, array $options = []): array
    {
        /** @var ContactDeleted */
        return $this->http->request('DELETE', '/contacts/' . HttpClient::segment($email), [], null, null, $options)['data'];
    }

    /**
     * DELETE /audiences/{audienceId}/contacts/{email}: takes a contact off one audience.
     *
     * @param RequestOptions $options
     *
     * @return ContactDeleted
     */
    public function remove(string $audienceId, string $email, array $options = []): array
    {
        $path = '/audiences/' . HttpClient::segment($audienceId) . '/contacts/' . HttpClient::segment($email);
        /** @var ContactDeleted */
        return $this->http->request('DELETE', $path, [], null, null, $options)['data'];
    }

    /**
     * "properties" is a JSON object; an empty PHP array would encode as [].
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function objects(array $params): array
    {
        if (isset($params['properties']) && $params['properties'] === []) {
            $params['properties'] = new \stdClass();
        }
        return $params;
    }
}
