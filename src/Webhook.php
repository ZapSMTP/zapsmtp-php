<?php

declare(strict_types=1);

namespace ZapSMTP;

use Psr\Http\Message\MessageInterface;
use ZapSMTP\Exception\WebhookVerificationException;

/**
 * Webhook signature verification.
 *
 * Every request ZapSMTP sends carries Zap-Signature: t=<unix seconds>,v1=<hex>,
 * where v1 is HMAC-SHA256 over "<t>.<raw body>" keyed with the whole secret
 * (its whsec_ prefix included). Two v1 entries are sent for 24 hours after a
 * secret rotation; either one matching is enough.
 */
final class Webhook
{
    /** Oldest signature accepted by default, in seconds. */
    public const DEFAULT_TOLERANCE = 300;

    public const SIGNATURE_HEADER = 'Zap-Signature';

    /**
     * Checks a webhook request's signature and returns its event. Pass the
     * body exactly as received, before any JSON parsing. For an endpoint with
     * batched delivery (a JSON array of events) use verifyEvents().
     *
     *     $event = \ZapSMTP\Webhook::verify(file_get_contents('php://input'), getallheaders(), $secret);
     *
     * @param string $payload the raw request body
     * @param array<array-key, mixed>|string|MessageInterface $headersOrSignature the request headers (any case,
     *        values as strings or lists), a PSR-7 request, or the Zap-Signature value itself
     * @param string $secret the endpoint's signing secret (whsec_...)
     * @param int $tolerance oldest signature accepted, in seconds
     * @param int|null $now the time to check against, in unix seconds (default now)
     *
     * @throws WebhookVerificationException
     */
    public static function verify(
        string $payload,
        array|string|MessageInterface $headersOrSignature,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): WebhookEvent {
        [$body, $isBatch] = self::check($payload, $headersOrSignature, $secret, $tolerance, $now);
        if ($isBatch) {
            throw new WebhookVerificationException('The body is a batch of events: use verifyEvents');
        }

        return new WebhookEvent($body);
    }

    /**
     * Checks a webhook request's signature and returns its events: the one
     * event of a single delivery, or every event of a batched delivery.
     *
     * @param array<array-key, mixed>|string|MessageInterface $headersOrSignature see verify()
     *
     * @return list<WebhookEvent>
     *
     * @throws WebhookVerificationException
     */
    public static function verifyEvents(
        string $payload,
        array|string|MessageInterface $headersOrSignature,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): array {
        [$body, $isBatch] = self::check($payload, $headersOrSignature, $secret, $tolerance, $now);

        if (!$isBatch) {
            return [new WebhookEvent($body)];
        }
        $events = [];
        foreach ($body as $event) {
            $events[] = new WebhookEvent($event);
        }

        return $events;
    }

    /**
     * Checks the signature and returns the decoded body: one event, or a
     * batch (a list of events).
     *
     * @param array<array-key, mixed>|string|MessageInterface $headersOrSignature
     *
     * @return array{0: array<array-key, mixed>, 1: false}|array{0: list<array<array-key, mixed>>, 1: true}
     *
     * @throws WebhookVerificationException
     */
    private static function check(
        string $payload,
        array|string|MessageInterface $headersOrSignature,
        string $secret,
        int $tolerance,
        ?int $now,
    ): array {
        $sig = self::signature($headersOrSignature);
        if ($sig === null || $sig === '') {
            throw new WebhookVerificationException('Missing Zap-Signature header');
        }
        if ($secret === '') {
            throw new WebhookVerificationException('Missing webhook secret');
        }
        $t = '';
        $v1 = [];
        foreach (explode(',', $sig) as $part) {
            $i = strpos($part, '=');
            if ($i === false) {
                continue;
            }
            $k = trim(substr($part, 0, $i));
            $v = trim(substr($part, $i + 1));
            if ($k === 't') {
                $t = $v;
            } elseif ($k === 'v1') {
                $v1[] = $v;
            }
        }
        if (preg_match('/^\d{1,12}$/', $t) !== 1 || $v1 === []) {
            throw new WebhookVerificationException('Malformed Zap-Signature header');
        }
        $now ??= time();
        if (abs($now - (int) $t) > $tolerance) {
            throw new WebhookVerificationException('Webhook signature is too old (or from the future)');
        }
        $want = hash_hmac('sha256', $t . '.' . $payload, $secret);
        $ok = false;
        foreach ($v1 as $hex) {
            // every entry is compared, in constant time
            if (preg_match('/^[0-9a-f]{64}$/i', $hex) === 1 && hash_equals($want, strtolower($hex))) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new WebhookVerificationException('Webhook signature does not match');
        }
        try {
            // decoded twice: objects tell {} from [], arrays give the data
            $shape = json_decode($payload, false, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
            $data = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new WebhookVerificationException('Webhook body is not JSON', previous: $e);
        }
        if ($shape instanceof \stdClass && \is_array($data)) {
            return [$data, false];
        }
        if (!\is_array($shape) || !\is_array($data) || array_filter($shape, static fn ($e) => !$e instanceof \stdClass) !== []) {
            throw new WebhookVerificationException('Webhook body is not an event');
        }

        /** @var list<array<array-key, mixed>> $data */
        return [$data, true];
    }

    /** @param array<array-key, mixed>|string|MessageInterface $headers */
    private static function signature(array|string|MessageInterface $headers): ?string
    {
        if (\is_string($headers)) {
            return $headers;
        }
        if ($headers instanceof MessageInterface) {
            return $headers->getHeaderLine(self::SIGNATURE_HEADER);
        }
        foreach ($headers as $k => $v) {
            if (\is_string($k) && strcasecmp($k, self::SIGNATURE_HEADER) === 0) {
                if (\is_array($v)) {
                    return implode(',', array_filter($v, 'is_string'));
                }

                return \is_string($v) ? $v : null;
            }
        }

        return null;
    }
}
