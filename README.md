# meil-php

The official PHP client for the [meil.ro](https://meil.ro) API: transactional email and newsletters.

- PHP 8.1 or newer. It needs only `ext-curl` and `ext-json`, so it runs on shared hosting and inside WordPress.
- Answers are plain associative arrays. They are described with PHPDoc array shapes, so your editor and PHPStan know every key.
- Safe retries: sends always carry an `Idempotency-Key`, so a retry never sends twice.

```sh
composer require meil/meil-php
```

```php
$meil = new Meil\Meil('meil_...'); // or set MEIL_API_KEY and call new Meil\Meil()
```

Keys are the API's own field names (`reply_to`, `template_id`, `audience_id`), so the [API docs](https://meil.ro/docs) and this package read the same.

## Sending email

```php
$sent = $meil->emails->send([
    'from'    => 'Magazinul Tău <comenzi@magazin.ro>',
    'to'      => 'ana@example.com', // a string, "a@x.ro, b@y.ro", or an array; at most 50 recipients with cc and bcc
    'subject' => 'Comanda 2451 a fost expediată',
    'html'    => '<p>Salut Ana, comanda ta e pe drum.</p>',
]);
echo $sent['id'];
```

With a saved template and its placeholders:

```php
$meil->emails->send([
    'from'        => 'Magazinul Tău <comenzi@magazin.ro>',
    'to'          => 'ana@example.com',
    'template_id' => 'tpl_id',
    'variables'   => ['name' => 'Ana', 'order_id' => 2451],
]);
```

With attachments. Pass the file's **raw bytes** as `content`, and the SDK base64-encodes them. Do not encode them yourself. You can attach up to 20 files, 7 MB in total.

```php
$meil->emails->send([
    'from'        => 'Magazinul Tău <facturi@magazin.ro>',
    'to'          => 'ana@example.com',
    'subject'     => 'Factura pentru comanda 2451',
    'heading'     => 'Mulțumim pentru comandă', // no html: built in your domain's branding
    'body'        => 'Factura este atașată.',
    'button'      => ['label' => 'Vezi comanda', 'url' => 'https://magazin.ro/comenzi/2451'],
    'attachments' => [
        ['filename' => 'factura-2451.pdf', 'content' => file_get_contents('/path/factura-2451.pdf')],
    ],
]);
```

The answer is `['id' => ...]`. It can also have `unsubscribe_url`, and `suppressed` when some recipients on your suppression list were skipped.

## Idempotency keys

`emails->send()` and `broadcasts->create()` always send an `Idempotency-Key`. If you give none, the SDK makes a random UUID for the call, which makes its own retries safe. To make your own retries safe too (a cron job or queue worker that runs twice, say), pass a key that is unique to the send:

```php
$meil->emails->send($params, ['idempotency_key' => "order-{$order->id}-confirmation"]);
```

For 24 hours, the same key with the same body returns the first answer and sends nothing new. The same key with a different body throws a `ConflictException` with the error name `invalid_idempotent_request`.

## Errors

Failed calls throw. Every exception is a `Meil\Exception\MeilException` (a `RuntimeException`) with these methods:

- `getErrorName()`: the API's stable error name, such as `"rate_limit_exceeded"`;
- `getStatusCode()`: the HTTP status;
- `getMessage()`: English text for people;
- `getId()`: when there is one.

```php
use Meil\Exception\MeilException;
use Meil\Exception\RateLimitException;
use Meil\Exception\ValidationException;

try {
    $meil->emails->send($params);
} catch (ValidationException $e) {
    if ($e->getErrorName() === 'send_rejected') {
        error_log('Refused by the mail provider; logged as ' . $e->getId());
    } else {
        error_log('Invalid request: ' . $e->getMessage());
    }
} catch (RateLimitException $e) {
    error_log('Rate limit or quota: ' . $e->getErrorName()); // daily_quota_exceeded, monthly_quota_exceeded, ...
} catch (MeilException $e) {
    error_log($e->getStatusCode() . ' ' . $e->getErrorName() . ': ' . $e->getMessage());
}
```

| Exception (`Meil\Exception\…`) | When |
| --- | --- |
| `AuthenticationException` | 401, a missing or invalid key |
| `PermissionException` | 403: key scope, plan, suspended account, `recipient_suppressed` |
| `NotFoundException` | 404, also for objects of a domain your key is not locked to |
| `ConflictException` | 409: idempotency conflicts |
| `ValidationException` | 422, including `send_rejected` |
| `RateLimitException` | 429, with `getRetryAfter()` in seconds when the API sends one |
| `ServerException` | 5xx, including Cloudflare error pages (error name `invalid_response`) |
| `ConnectionException` | no answer: `connection_error` or `timeout`, status `null` |

### Retries

By default the SDK retries up to 2 times. Set `max_retries` to change that; 0 turns retries off.

It retries:

- 429 `rate_limit_exceeded`, after the `Retry-After` the API sends;
- 409 `concurrent_idempotent_requests`;
- network errors and timeouts;
- 502, 503, 504, and Cloudflare's 520 to 524, with exponential backoff.

Quotas (`daily_quota_exceeded`, `monthly_quota_exceeded`, `previous_broadcast_pending`) are never retried. GET, PATCH and DELETE calls are retried. A POST is retried only when it carries an `Idempotency-Key`, which means only `emails->send()` and `broadcasts->create()`.

The SDK waits between retries with `usleep()`, so a web request that hits a rate limit can take a few extra seconds. For a page that must answer quickly, set `'max_retries' => 0`.

```php
$meil = new Meil\Meil('meil_...', [
    'max_retries' => 2,
    'timeout'     => 30, // seconds per attempt
    'base_url'    => 'https://api.meil.ro',
]);
$meil->emails->get($id, ['timeout' => 5, 'max_retries' => 0]); // per call
```

## Broadcasts

```php
$result = $meil->broadcasts->create([
    'audience_id' => 'aud_id',
    'from'        => 'Magazinul Tău <noutati@magazin.ro>',
    'subject'     => 'Noutăți în martie',
    'html'        => '<h1>Salut {{first_name|acolo}}</h1> … <a href="{{unsubscribe_url}}">Dezabonare</a>',
    'send'        => true, // or 'scheduled_at' => new DateTimeImmutable('2026-11-01 09:00', new DateTimeZone('Europe/Bucharest')) on paid plans; or neither for a draft
], ['idempotency_key' => 'newsletter-2026-03']);

if ($result['status'] === 'under_review') {
    // HTTP 202: the broadcast exists and waits for a person at meil.ro to look at it.
    // Not an error. Check on it later with $meil->broadcasts->get($result['id']).
    echo 'Held for review: ' . $result['id'] . ' ' . $result['message'];
} elseif ($result['status'] === 'draft') {
    echo 'Draft saved: ' . $result['id'];
} else {
    echo "{$result['status']}: {$result['recipients']} recipients";
}
```

`$meil->broadcasts->send($id)` sends a draft. It can also return the `under_review` result.

Other broadcast methods: `get`, `list`, `update`, `delete`, `schedule($id, $date)` and `unschedule`.

## Contacts

```php
$contact = $meil->contacts->add([
    'audience_id' => 'aud_id',
    'email'       => 'ana+news@example.com',
    'first_name'  => 'Ana',
    'properties'  => ['city' => 'Cluj'],
]);
// An existing contact keeps its own values; clashes are listed in $contact['kept_properties'].

$meil->contacts->update('ana+news@example.com', ['properties' => ['city' => 'Iași', 'old_field' => null]]); // null removes
$meil->contacts->remove('aud_id', 'ana+news@example.com'); // off one audience
$meil->contacts->delete('ana+news@example.com');           // everywhere, with its data
```

The SDK URL-encodes the email addresses in paths for you.

## Paging

`emails->list()` and `audiences->listContacts()` return one page: `['object' => 'list', 'has_more' => bool, 'data' => [...]]`. To get everything, loop over the generator. Pages are fetched as you go:

```php
foreach ($meil->audiences->iterateContacts('aud_id', ['limit' => 1000]) as $contact) {
    echo $contact['email'], $contact['unsubscribed'] ? ' (unsubscribed)' : '', "\n";
}

foreach ($meil->emails->iterate() as $email) {
    if ($email['last_event'] === 'bounced') {
        echo implode(', ', $email['to']), "\n";
    }
}
```

The other lists (`audiences->list()`, `broadcasts->list()`, `templates->list()`) return everything at once.

## All methods

| Resource | Methods |
| --- | --- |
| `emails` | `send`, `list`, `iterate`, `get` |
| `audiences` | `create`, `list`, `get`, `delete`, `listContacts`, `iterateContacts` |
| `contacts` | `add`, `get`, `update`, `delete`, `remove` |
| `broadcasts` | `create`, `list`, `get`, `update`, `delete`, `send`, `schedule`, `unschedule` |
| `templates` | `create`, `list`, `get`, `update`, `delete` |
| `content` | `check` |

A key's scope decides what it may call. `email_access` keys get emails and the content check; `broadcast_access` keys get everything except audiences and contacts. The SDK does not check scopes: the API answers with a `PermissionException` (`insufficient_permission`).

## Timestamps

Timestamps in answers (`created_at`, `scheduled_at`, `sent_at`, ...) are **UTC** strings formatted `"YYYY-MM-DD HH:MM:SS"`, returned as the API sends them. To parse one: `new DateTimeImmutable($email['created_at'], new DateTimeZone('UTC'))`.

## WordPress and shared hosting

The package has no Composer dependencies. Without Composer, require the files with a small PSR-4 autoloader for the `Meil\` namespace, which maps to `src/`:

```php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Meil\\')) {
        require __DIR__ . '/meil-php/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
```

To send through something other than cURL (WordPress's HTTP API, a PSR-18 client), implement `Meil\Transport\TransportInterface` and pass it as the `transport` option.

## Development

```sh
composer install
composer test       # fake transport, no network
composer analyse    # PHPStan, level 8
MEIL_API_KEY=... MEIL_TEST_DOMAIN=yourdomain.ro composer test:live   # real API; mails only the SES simulator
```

## License

MIT
