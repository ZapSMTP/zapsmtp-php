# ZapSMTP PHP SDK

The official PHP library for the [ZapSMTP](https://zapsmtp.com) email API: send, receive and track
email, manage templates, domains, webhooks and everything else the API does.

- Every API operation, with PHPDoc array shapes for every parameter and typed response models
- Safe automatic retries: sends carry an `Idempotency-Key`, so a retry never sends twice
- `foreach` over every page of any list
- Webhook signature verification, batched deliveries included
- PHP 8.1+, PSR-18 (Guzzle 7 by default, or any PSR-18 client)

Using Laravel? Install [`zapsmtp/zapsmtp-laravel`](../laravel): a mail driver, webhooks as events and more.

## Install

```bash
composer require zapsmtp/zapsmtp-php
```

## Send an email

```php
$zap = new \ZapSMTP\Client('zap_...'); // or set ZAP_API_KEY and call new \ZapSMTP\Client()

$sent = $zap->emails->send([
    'from' => 'Acme <hello@acme.com>',
    'to' => 'jane@example.org',
    'subject' => 'Your receipt',
    'html' => '<p>Thanks for your order.</p>',
    'tags' => [['name' => 'category', 'value' => 'receipt']],
    'metadata' => ['order_id' => 'A-1042'],
]);

echo $sent->id;
```

Parameters are arrays with the API's own names (snake_case), as in the
[API reference](https://zapsmtp.com/docs/api/). Each method's PHPDoc lists them, so IDEs and PHPStan
check them.

With a template:

```php
$zap->emails->send([
    'from' => 'Acme <hello@acme.com>',
    'to' => 'jane@example.org',
    'template' => ['id' => 'receipt', 'variables' => ['name' => 'Jane', 'total' => 42]],
]);
```

Attachments:

```php
use ZapSMTP\Attachment;

$zap->emails->send([
    'from' => 'Acme <hello@acme.com>',
    'to' => 'jane@example.org',
    'subject' => 'Your invoice',
    'html' => '<p>Attached. <img src="cid:logo"></p>',
    'attachments' => [
        Attachment::fromFile('/path/to/invoice.pdf'),
        Attachment::fromString('logo.png', $pngBytes, ['content_id' => 'logo']), // inline image
    ],
]);
```

Up to 100 emails at once:

```php
$result = $zap->emails->sendBatch($emails, ['validation' => 'permissive']);
foreach ($result->errors ?? [] as $error) {
    echo "email {$error->index}: {$error->message}\n";
}
```

## Responses

Methods return model objects (`ZapSMTP\Model\Email`, `Domain`, ...) with typed properties. They are
read-only arrays too, holding the JSON exactly as received, fields newer than this SDK included:

```php
$email = $zap->emails->get($id);
$email->subject;          // ?string
$email->events[0]->type;  // nested models
$email['subject'];        // the raw JSON value
$email->toArray();        // the whole object
```

A field the API left out (or sent with another type) is `null` on the property and never an error.
Operations that answer `204 No Content` return nothing.

## Lists

A list method returns a `ZapSMTP\Page`; `foreach` over it walks every item of every page.

```php
$page = $zap->emails->list(['limit' => 50, 'status' => 'bounced']);
$page->data;           // this page's emails
$page->hasNextPage();  // no request is made
$next = $page->getNextPage();

foreach ($zap->emails->list(['status' => 'bounced']) as $email) {
    echo $email->id, ' ', implode(', ', $email->to ?? []), "\n";
}
```

## Errors

Every failure throws a `ZapSMTP\Exception\ZapSMTPException` subclass with `getStatusCode()`,
`getErrorCode()`, `getErrorType()`, `getMessage()`, `getRequestId()`, `getHeaders()` and `getBody()`.

```php
use ZapSMTP\Exception\RateLimitException;
use ZapSMTP\Exception\ValidationException;

try {
    $zap->emails->send($email);
} catch (ValidationException $e) {
    echo $e->getErrorCode(), ': ', $e->getMessage(); // e.g. invalid_parameter
} catch (RateLimitException $e) {
    echo 'retry in ', $e->getRetryAfter(), ' s';
}
```

| Class | When |
|---|---|
| `AuthenticationException` | 401: missing or invalid key |
| `PermissionException` | 403: the key may not do this, or the domain is not verified |
| `NotFoundException` | 404 |
| `ConflictException` | 409 |
| `ValidationException` | 400, 413, 422: the request is wrong (also an id that is empty, `.` or `..`, refused before sending) |
| `RateLimitException` | 429: `getRetryAfter()` says how long to wait |
| `ServerException` | 5xx |
| `ConnectionException` | no answer: network failure or timeout |
| `WebhookVerificationException` | a webhook's signature is missing, wrong or too old |

## Retries and timeouts

A request that failed on the way (429, 408, 5xx, a lost connection) is tried again twice by default,
waiting as the API's `Retry-After` says or backing off exponentially (0.5 s, 1 s, 2 s, ... up to 8 s,
with jitter). Only requests that are safe to repeat are retried: reads, `PUT`, deletes, and sends,
which always carry an `Idempotency-Key`. Pass your own key to make your own retries safe too; an empty
or blank key (as the option or as an `Idempotency-Key` header of any case) is refused with a
`ValidationException` before sending, because the API does not deduplicate on it.

The last argument of every method takes per-request options:

```php
$zap = new \ZapSMTP\Client('zap_...', ['max_retries' => 4, 'timeout' => 20]);

$zap->emails->send($email, ['idempotency_key' => "order-{$order->id}"]);
$zap->emails->get($id, ['timeout' => 5, 'max_retries' => 0, 'headers' => ['X-Trace' => $traceId]]);
```

## Status, headers and request id

```php
$sent = $zap->emails->send($email);
$sent->getLastResponse()->requestId;                      // quote it to support
$sent->getLastResponse()->getHeader('ratelimit-remaining');
$zap->lastResponse()?->statusCode;                        // the last answer, errors included
```

## Webhooks

Check the signature on the raw body, before parsing it:

```php
use ZapSMTP\Exception\WebhookVerificationException;
use ZapSMTP\Webhook;

$payload = file_get_contents('php://input');
try {
    $event = Webhook::verify($payload, getallheaders(), getenv('ZAP_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}
if ($event->type === \ZapSMTP\WebhookEvent::EMAIL_BOUNCED) {
    $address = $event->data['to'];
}
http_response_code(200);
```

The headers can be an array (any case), a PSR-7 request, or the `Zap-Signature` value. A webhook with
batched delivery sends a JSON array of events: use `Webhook::verifyEvents()`, which returns a list
(one event for a single delivery). Signatures older than five minutes are refused (pass `$tolerance`
to change that). `$zap->webhooks->verify()` and `verifyEvents()` do the same.

## Uploading images

```php
$asset = $zap->templateAssets->uploadFile('/path/to/logo.png', ['name' => 'Logo']);
```

The file can be a path, an open stream resource or a PSR-7 stream.

## Configuration

| Option | Environment | Default |
|---|---|---|
| API key (first argument) | `ZAP_API_KEY` | required |
| `base_url` | `ZAP_BASE_URL` | `https://api.zapsmtp.com/v1` |
| `timeout` | | 60 s per attempt (Guzzle clients; other PSR-18 clients use their own) |
| `max_retries` | | 2 |
| `default_headers` | | |
| `http_client` | | a Guzzle client; any PSR-18 `ClientInterface` |
| `sleeper` | | `usleep`; a `callable(float $seconds)` for tests or event loops |

## Development

```bash
composer install
vendor/bin/phpunit          # unit, webhook-vector and conformance tests
vendor/bin/phpstan analyse  # level max
```

The services and models in `src/Generated` are generated from the API reference by
`node sdks/codegen/generate.mjs`; do not edit them. The conformance tests need the mock server:
`sdks/scripts/docker-test.sh php:8.4-cli php '...'` starts it and sets `ZAP_CONFORMANCE_URL`.
