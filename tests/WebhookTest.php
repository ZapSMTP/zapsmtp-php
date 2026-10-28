<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use ZapSMTP\Exception\WebhookVerificationException;
use ZapSMTP\Tests\Support\MockHttpClient;
use ZapSMTP\Webhook;
use ZapSMTP\WebhookEvent;

final class WebhookTest extends TestCase
{
    /** @return list<array{name: string, secret: string, payload: string, header: string, now: int, tolerance: int, valid: bool, batch?: bool}> */
    private static function vectors(): array
    {
        $file = file_get_contents(__DIR__ . '/../../spec/webhook-vectors.json');
        self::assertIsString($file);
        /** @var array{vectors: list<array{name: string, secret: string, payload: string, header: string, now: int, tolerance: int, valid: bool, batch?: bool}>} $json */
        $json = json_decode($file, true, 512, \JSON_THROW_ON_ERROR);

        return $json['vectors'];
    }

    /** @return iterable<string, array{array{name: string, secret: string, payload: string, header: string, now: int, tolerance: int, valid: bool, batch?: bool}}> */
    public static function vectorCases(): iterable
    {
        foreach (self::vectors() as $v) {
            yield $v['name'] => [$v];
        }
    }

    /** @param array{name: string, secret: string, payload: string, header: string, now: int, tolerance: int, valid: bool, batch?: bool} $v */
    #[DataProvider('vectorCases')]
    public function testVector(array $v): void
    {
        $headers = ['Zap-Signature' => $v['header']];
        $one = static fn () => Webhook::verify($v['payload'], $headers, $v['secret'], $v['tolerance'], $v['now']);
        $all = static fn () => Webhook::verifyEvents($v['payload'], $headers, $v['secret'], $v['tolerance'], $v['now']);
        $want = json_decode($v['payload'], true);
        if (!$v['valid']) {
            foreach ([$one, $all] as $run) {
                try {
                    $run();
                    self::fail('verified an invalid vector');
                } catch (WebhookVerificationException) {
                    $this->addToAssertionCount(1);
                }
            }
        } elseif ($v['batch'] ?? false) {
            self::assertSame($want, array_map(static fn (WebhookEvent $e) => $e->toArray(), $all()));
            $this->expectException(WebhookVerificationException::class);
            $this->expectExceptionMessage('The body is a batch of events: use verifyEvents');
            $one();
        } else {
            self::assertSame($want, $one()->toArray());
            self::assertSame([$want], array_map(static fn (WebhookEvent $e) => $e->toArray(), $all()));
        }
    }

    public function testTakesHeaderArraysAHeaderValueAPsrRequestAndTheClientMethod(): void
    {
        $v = self::vectors()[0];
        $event = Webhook::verify($v['payload'], ['zap-signature' => [$v['header']]], $v['secret'], now: $v['now']);
        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertSame('evt_1', $event->id);
        self::assertSame(WebhookEvent::EMAIL_DELIVERED, $event->type);
        self::assertSame('2026-01-01T00:00:00Z', $event->created_at);
        self::assertSame('019a', $event->data['email_id'] ?? null);
        $data = $event['data'];
        self::assertIsArray($data);
        self::assertSame('Привет ✓', $data['subject']);
        self::assertSame('email.delivered', Webhook::verify($v['payload'], $v['header'], $v['secret'], now: $v['now'])->type);
        $request = new ServerRequest('POST', '/hooks', ['Zap-Signature' => $v['header']], $v['payload']);
        self::assertSame('evt_1', Webhook::verify((string) $request->getBody(), $request, $v['secret'], now: $v['now'])->id);
        $zap = $this->client(new MockHttpClient());
        self::assertSame('evt_1', $zap->webhooks->verify($v['payload'], ['Other' => 'x', 'ZAP-SIGNATURE' => $v['header']], $v['secret'], 300, $v['now'])->id);
    }

    public function testChecksAgainstTheClockByDefault(): void
    {
        $secret = 'whsec_x';
        $payload = '{"id":"evt_2","type":"email.sent","created_at":"2026-01-01T00:00:00Z","data":{}}';
        $sig = static fn (int $t): string => "t=$t,v1=" . hash_hmac('sha256', "$t.$payload", $secret);
        self::assertSame('evt_2', Webhook::verify($payload, $sig(time() - 10), $secret)->id);
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('too old');
        Webhook::verify($payload, $sig(time() - 301), $secret);
    }

    public function testRefusesAMissingHeaderOrSecretAndABodyThatIsNotAJsonObject(): void
    {
        $cases = [
            'Missing Zap-Signature' => static fn () => Webhook::verify('{}', [], 's'),
            'Missing Zap-Signature ' => static fn () => Webhook::verify('{}', ['Zap-Signature' => 1], 's'),
            'Missing Zap-Signature  ' => static fn () => Webhook::verify('{}', new ServerRequest('POST', '/'), 's'),
            'Missing webhook secret' => static fn () => Webhook::verify('{}', 't=1,v1=00', ''),
            'Malformed' => static fn () => Webhook::verify('{}', 'garbage', 's'),
            'Malformed ' => static fn () => Webhook::verify('{}', 't=' . str_repeat('9', 13) . ',v1=00', 's'),
        ];
        $t = 1700000000;
        foreach (['not json' => 'not JSON', '"text"' => 'not an event', '[{"id":"evt_1"}]' => 'use verifyEvents', '[[]]' => 'not an event ', '[1]' => 'not an event  '] as $body => $message) {
            $sig = "t=$t,v1=" . hash_hmac('sha256', "$t.$body", 'whsec_x');
            $cases[$message] = static fn () => Webhook::verify($body, $sig, 'whsec_x', 300, $t);
        }
        foreach ($cases as $message => $run) {
            try {
                $run();
                self::fail("no exception for $message");
            } catch (WebhookVerificationException $e) {
                self::assertStringContainsString(trim($message), $e->getMessage());
            }
        }
    }

    public function testVerifiesBatchedDeliveries(): void
    {
        $secret = 'whsec_batch';
        $t = 1767225600;
        $sign = static fn (string $body): string => "t=$t,v1=" . hash_hmac('sha256', "$t.$body", $secret);
        $batch = '[{"id":"evt_1","type":"email.sent","data":{}},{"type":"email.received","data":{"email_id":"in1"}}]';
        $events = Webhook::verifyEvents($batch, $sign($batch), $secret, 300, $t);
        self::assertCount(2, $events);
        self::assertSame('evt_1', $events[0]->id);
        self::assertSame('in1', $events[1]->data['email_id'] ?? null);
        $single = '{"id":"evt_2","type":"email.sent","data":{}}';
        $zap = $this->client(new MockHttpClient());
        self::assertSame('evt_2', $zap->webhooks->verifyEvents($single, $sign($single), $secret, 300, $t)[0]->id);
        self::assertSame([], Webhook::verifyEvents('[]', $sign('[]'), $secret, 300, $t));
        self::assertSame([], Webhook::verifyEvents('{}', $sign('{}'), $secret, 300, $t)[0]->toArray());
        self::assertSame([], Webhook::verify('{}', $sign('{}'), $secret, 300, $t)->toArray());
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('not an event');
        Webhook::verifyEvents('[1]', $sign('[1]'), $secret, 300, $t);
    }
}
