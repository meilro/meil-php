<?php

declare(strict_types=1);

namespace Meil\Resource;

use Meil\HttpClient;

/**
 * Timestamps are UTC strings formatted "YYYY-MM-DD HH:MM:SS".
 *
 * @phpstan-type CreateBroadcastParams array{
 *     audience_id: string,
 *     from: string,
 *     reply_to?: string,
 *     subject?: string,
 *     preview_text?: string,
 *     html?: string,
 *     text?: string,
 *     template_id?: string,
 *     variables?: array<string, string|int|float>,
 *     send?: bool,
 *     scheduled_at?: \DateTimeInterface|string,
 * }
 * @phpstan-type UpdateBroadcastParams array{
 *     audience_id?: string,
 *     from?: string,
 *     reply_to?: string|null,
 *     subject?: string,
 *     preview_text?: string|null,
 *     html?: string,
 *     text?: string,
 *     variables?: array<string, string|int|float>,
 * }
 * @phpstan-type BroadcastStatus 'draft'|'canary'|'queued'|'sending'|'paused'|'sent'|'canceled'
 * @phpstan-type BroadcastDraft array{object: 'broadcast', id: string, status: 'draft', scheduled_at: string|null}
 * @phpstan-type BroadcastQueued array{object: 'broadcast', id: string, status: 'queued'|'canary', recipients: int, total_recipients?: int, unqueued?: int}
 * @phpstan-type BroadcastUnderReview array{object: 'broadcast', id: string, status: 'under_review', under_review: true, message: string}
 * @phpstan-type Broadcast array{
 *     object: 'broadcast',
 *     id: string,
 *     audience_id: string|null,
 *     from: string,
 *     reply_to: string|null,
 *     subject: string,
 *     preview_text: string|null,
 *     status: BroadcastStatus,
 *     template_id: string|null,
 *     canary_note: string|null,
 *     under_review: bool,
 *     scheduled_at: string|null,
 *     created_at: string,
 *     sent_at: string|null,
 *     recipients: array{total: int, sent: int, failed: int, pending: int, unqueued: int},
 * }
 * @phpstan-type BroadcastList array{object: 'list', data: list<array{id: string, audience_id: string|null, subject: string, status: BroadcastStatus, scheduled_at: string|null, created_at: string, sent_at: string|null}>}
 * @phpstan-type BroadcastDeleted array{object: 'broadcast', id: string, deleted: true}
 * @phpstan-type RequestOptions array{timeout?: int|float, max_retries?: int}
 * @phpstan-type SendOptions array{timeout?: int|float, max_retries?: int, idempotency_key?: string}
 */
final class Broadcasts
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * POST /broadcasts: a draft; with "send" => true it goes out at once,
     * with "scheduled_at" it waits (paid plans). When it is held for review
     * (HTTP 202) the result has "status" => "under_review"; that is not an
     * error. Always sent with an Idempotency-Key (a random one unless you pass
     * "idempotency_key").
     *
     * @param CreateBroadcastParams $params
     * @param SendOptions $options
     *
     * @return BroadcastDraft|BroadcastQueued|BroadcastUnderReview
     */
    public function create(array $params, array $options = []): array
    {
        if (isset($params['scheduled_at'])) {
            $params['scheduled_at'] = self::isoTime($params['scheduled_at']);
        }
        if (isset($params['variables']) && $params['variables'] === []) {
            $params['variables'] = new \stdClass();
        }
        $key      = $options['idempotency_key'] ?? HttpClient::uuid();
        $response = $this->http->request('POST', '/broadcasts', [], $params, $key, $options);
        $held     = self::underReview($response);
        if ($held !== null) {
            return $held;
        }

        /** @var BroadcastDraft|BroadcastQueued */
        return $response['data'];
    }

    /**
     * GET /broadcasts: all of them at once.
     *
     * @param RequestOptions $options
     *
     * @return BroadcastList
     */
    public function list(array $options = []): array
    {
        /** @var BroadcastList */
        return $this->http->request('GET', '/broadcasts', [], null, null, $options)['data'];
    }

    /**
     * GET /broadcasts/{id}, with recipient counts.
     *
     * @param RequestOptions $options
     *
     * @return Broadcast
     */
    public function get(string $id, array $options = []): array
    {
        /** @var Broadcast */
        return $this->http->request('GET', '/broadcasts/' . HttpClient::segment($id), [], null, null, $options)['data'];
    }

    /**
     * PATCH /broadcasts/{id}: a draft that has not started sending.
     *
     * @param UpdateBroadcastParams $params
     * @param RequestOptions $options
     *
     * @return Broadcast
     */
    public function update(string $id, array $params, array $options = []): array
    {
        /** @var Broadcast */
        return $this->http->request('PATCH', '/broadcasts/' . HttpClient::segment($id), [], $params, null, $options)['data'];
    }

    /**
     * DELETE /broadcasts/{id}: a draft, scheduled or not.
     *
     * @param RequestOptions $options
     *
     * @return BroadcastDeleted
     */
    public function delete(string $id, array $options = []): array
    {
        /** @var BroadcastDeleted */
        return $this->http->request('DELETE', '/broadcasts/' . HttpClient::segment($id), [], null, null, $options)['data'];
    }

    /**
     * POST /broadcasts/{id}/send: sends a draft. Held for review (HTTP 202),
     * the result has "status" => "under_review". Not retried automatically:
     * the endpoint takes no Idempotency-Key.
     *
     * @param RequestOptions $options
     *
     * @return BroadcastQueued|BroadcastUnderReview
     */
    public function send(string $id, array $options = []): array
    {
        $response = $this->http->request('POST', '/broadcasts/' . HttpClient::segment($id) . '/send', [], null, null, $options);
        $held     = self::underReview($response, $id);
        if ($held !== null) {
            return $held;
        }

        /** @var BroadcastQueued */
        return $response['data'];
    }

    /**
     * POST /broadcasts/{id}/schedule (paid plans).
     *
     * @param \DateTimeInterface|string $scheduledAt A date, or an ISO 8601 string with an offset or Z.
     * @param RequestOptions $options
     *
     * @return BroadcastDraft
     */
    public function schedule(string $id, \DateTimeInterface|string $scheduledAt, array $options = []): array
    {
        $path = '/broadcasts/' . HttpClient::segment($id) . '/schedule';
        /** @var BroadcastDraft */
        return $this->http->request('POST', $path, [], ['scheduled_at' => self::isoTime($scheduledAt)], null, $options)['data'];
    }

    /**
     * POST /broadcasts/{id}/unschedule: back to an unscheduled draft.
     *
     * @param RequestOptions $options
     *
     * @return BroadcastDraft
     */
    public function unschedule(string $id, array $options = []): array
    {
        $path = '/broadcasts/' . HttpClient::segment($id) . '/unschedule';
        /** @var BroadcastDraft */
        return $this->http->request('POST', $path, [], null, null, $options)['data'];
    }

    /**
     * The 202 "under_review" answer as a result, or null for any other answer.
     *
     * @param array{status: int, data: array<mixed>} $response
     *
     * @return BroadcastUnderReview|null
     */
    private static function underReview(array $response, ?string $fallbackId = null): ?array
    {
        $data  = $response['data'];
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        if ($response['status'] !== 202 || ($error['name'] ?? null) !== 'under_review') {
            return null;
        }
        return [
            'object'       => 'broadcast',
            'id'           => is_string($data['id'] ?? null) ? $data['id'] : (string) $fallbackId,
            'status'       => 'under_review',
            'under_review' => true,
            'message'      => is_string($error['message'] ?? null) ? $error['message'] : 'The broadcast is held for review.',
        ];
    }

    private static function isoTime(\DateTimeInterface|string $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d\TH:i:sP') : $value;
    }
}
