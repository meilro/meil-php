<?php

declare(strict_types=1);

namespace Meil\Resource;

use Meil\HttpClient;

/**
 * Timestamps are UTC strings formatted "YYYY-MM-DD HH:MM:SS".
 *
 * @phpstan-type Addresses string|list<string>
 * @phpstan-type SendEmailParams array{
 *     from: string,
 *     to: Addresses,
 *     cc?: Addresses,
 *     bcc?: Addresses,
 *     reply_to?: Addresses,
 *     subject?: string,
 *     preview_text?: string,
 *     html?: string,
 *     text?: string,
 *     template_id?: string,
 *     variables?: array<string, string|int|float>,
 *     heading?: string,
 *     body?: string,
 *     code?: string,
 *     button?: array{label?: string, url?: string},
 *     headers?: array<string, string>,
 *     attachments?: list<array{filename: string, content: string}>,
 * }
 * @phpstan-type SentEmail array{id: string, unsubscribe_url?: string, suppressed?: list<string>}
 * @phpstan-type EmailEvent 'queued'|'sending'|'sent'|'failed'|'canceled'|'delayed'|'delivered'|'soft_bounced'|'bounced'|'rejected'|'complained'
 * @phpstan-type EmailSummary array{
 *     object: 'email',
 *     id: string,
 *     to: list<string>,
 *     cc: list<string>,
 *     bcc: list<string>,
 *     reply_to: string|null,
 *     from: string,
 *     subject: string,
 *     template_id: string|null,
 *     created_at: string,
 *     last_event: EmailEvent,
 * }
 * @phpstan-type Email array{
 *     object: 'email',
 *     id: string,
 *     to: list<string>,
 *     cc: list<string>,
 *     bcc: list<string>,
 *     reply_to: string|null,
 *     from: string,
 *     subject: string,
 *     html: string,
 *     text: string,
 *     attachments: list<array{filename: string, bytes: int, type: string}>,
 *     template_id: string|null,
 *     created_at: string,
 *     last_event: EmailEvent,
 * }
 * @phpstan-type EmailList array{object: 'list', has_more: bool, data: list<EmailSummary>}
 * @phpstan-type ListParams array{limit?: int, after?: string, before?: string}
 * @phpstan-type RequestOptions array{timeout?: int|float, max_retries?: int}
 * @phpstan-type SendOptions array{timeout?: int|float, max_retries?: int, idempotency_key?: string}
 */
final class Emails
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * POST /emails. Always sent with an Idempotency-Key (a random one unless
     * you pass "idempotency_key"), so a retry never sends twice.
     *
     * Attachment "content" is the file's raw bytes (for example from
     * file_get_contents()); the SDK base64-encodes it.
     *
     * @param SendEmailParams $params
     * @param SendOptions $options
     *
     * @return SentEmail
     */
    public function send(array $params, array $options = []): array
    {
        if (isset($params['attachments'])) {
            $params['attachments'] = array_map(static function (array $attachment): array {
                if (!is_string($attachment['content'] ?? null)) {
                    throw new \InvalidArgumentException('Each attachment needs a filename and its content as a string of bytes.');
                }
                $attachment['content'] = base64_encode($attachment['content']);
                return $attachment;
            }, $params['attachments']);
        }
        if (isset($params['variables']) && $params['variables'] === []) {
            $params['variables'] = new \stdClass();
        }
        $key = $options['idempotency_key'] ?? HttpClient::uuid();

        /** @var SentEmail */
        return $this->http->request('POST', '/emails', [], $params, $key, $options)['data'];
    }

    /**
     * GET /emails: one page, newest first.
     *
     * @param ListParams $params
     * @param RequestOptions $options
     *
     * @return EmailList
     */
    public function list(array $params = [], array $options = []): array
    {
        /** @var EmailList */
        return $this->http->request('GET', '/emails', $params, null, null, $options)['data'];
    }

    /**
     * Every email from $params on, fetching pages as needed:
     * foreach ($meil->emails->iterate() as $email) { ... }
     *
     * @param ListParams $params
     * @param RequestOptions $options
     *
     * @return \Generator<int, EmailSummary, mixed, void>
     */
    public function iterate(array $params = [], array $options = []): \Generator
    {
        /** @var \Generator<int, EmailSummary, mixed, void> */
        return Paginator::iterate(fn (array $p): array => $this->list($p, $options), $params);
    }

    /**
     * GET /emails/{id}.
     *
     * @param RequestOptions $options
     *
     * @return Email
     */
    public function get(string $id, array $options = []): array
    {
        /** @var Email */
        return $this->http->request('GET', '/emails/' . HttpClient::segment($id), [], null, null, $options)['data'];
    }
}
