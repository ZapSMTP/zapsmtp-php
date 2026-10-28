<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use ZapSMTP\Exception\ConnectionException;
use ZapSMTP\Exception\ServerException;
use ZapSMTP\Internal\Retry;
use ZapSMTP\Tests\Support\MockHttpClient;
use ZapSMTP\Tests\Support\NetworkError;

final class RetriesTest extends TestCase
{
    public function testRetriesSafeRequestsOn5xxAndNetworkErrorsHonouringRetryAfter(): void
    {
        $http = new MockHttpClient(MockHttpClient::apiError(503, 'service_unavailable', ['Retry-After' => '2']), new NetworkError('reset'), MockHttpClient::json(200, ['id' => 'e1']));
        self::assertSame('e1', $this->client($http)->emails->get('e1')->id);
        self::assertCount(3, $http->requests);
        self::assertSame(2.0, $this->sleeps[0]);
        self::assertGreaterThanOrEqual(0.5, $this->sleeps[1]); // attempt 1: 0.5-1 s
        self::assertLessThanOrEqual(1.0, $this->sleeps[1]);
    }

    public function testRetriesASendWithTheSameIdempotencyKey(): void
    {
        $http = new MockHttpClient(MockHttpClient::apiError(502, 'bad_gateway'), MockHttpClient::json(200, ['id' => 'e1']));
        $this->client($http)->emails->send(['from' => 'a', 'to' => 'b', 'subject' => 's']);
        self::assertCount(2, $http->requests);
        self::assertSame($http->header(0, 'Idempotency-Key'), $http->header(1, 'Idempotency-Key'));
        self::assertSame($http->bodies[0], $http->bodies[1]);
    }

    public function testDoesNotRetryACreateOn5xxOrALostConnectionButDoesOn429(): void
    {
        $five = new MockHttpClient(MockHttpClient::apiError(500, 'internal_error'));
        try {
            $this->client($five)->domains->create(['name' => 'a.test']);
            self::fail('no exception');
        } catch (ServerException) {
            self::assertCount(1, $five->requests);
        }
        $lost = new MockHttpClient(new NetworkError('reset'));
        try {
            $this->client($lost)->domains->create(['name' => 'a.test']);
            self::fail('no exception');
        } catch (ConnectionException) {
            self::assertCount(1, $lost->requests);
        }
        $limited = new MockHttpClient(MockHttpClient::apiError(429, 'rate_limit_exceeded', ['Retry-After' => '1']), MockHttpClient::json(201, ['id' => 'd1']));
        self::assertSame('d1', $this->client($limited)->domains->create(['name' => 'a.test'])->id);
        self::assertSame([1.0], $this->sleeps);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $http = new MockHttpClient(MockHttpClient::apiError(503, 'service_unavailable', ['Retry-After' => '0']));
        try {
            $this->client($http, ['max_retries' => 3])->emails->get('x');
            self::fail('no exception');
        } catch (ServerException) {
            self::assertCount(4, $http->requests);
        }
        $once = new MockHttpClient(MockHttpClient::apiError(503, 'service_unavailable'));
        try {
            $this->client($once)->emails->get('x', ['max_retries' => 0]);
            self::fail('no exception');
        } catch (ServerException) {
            self::assertCount(1, $once->requests);
        }
    }

    public function testDecidesWhatIsWorthRetrying(): void
    {
        self::assertTrue(Retry::retryable(429, 'overloaded', false));
        self::assertFalse(Retry::retryable(429, 'daily_quota_exceeded', true));
        self::assertFalse(Retry::retryable(429, 'monthly_quota_exceeded', true));
        self::assertFalse(Retry::retryable(429, 'thread_reply_limit', true));
        self::assertTrue(Retry::retryable(409, 'idempotency_request_in_progress', false));
        self::assertFalse(Retry::retryable(409, 'idempotency_key_reused', true));
        self::assertTrue(Retry::retryable(503, 'service_unavailable', true));
        self::assertTrue(Retry::retryable(408, '', true));
        self::assertFalse(Retry::retryable(503, 'service_unavailable', false));
        self::assertFalse(Retry::retryable(422, 'invalid_parameter', true));
    }

    public function testBacksOffExponentiallyWithJitterCappedOrAsRetryAfterSays(): void
    {
        for ($a = 0; $a < 40; $a++) {
            $cap = min(0.5 * 2 ** $a, 8.0);
            $d = Retry::backoff($a, null);
            self::assertGreaterThanOrEqual($cap / 2, $d);
            self::assertLessThanOrEqual($cap, $d);
        }
        self::assertSame(3.0, Retry::backoff(0, 3.0));
        self::assertSame(60.0, Retry::backoff(0, 600.0));
    }

    public function testReadsRetryAfterAsAnHttpDate(): void
    {
        $now = 1767225600.0; // 2026-01-01T00:00:00Z
        self::assertSame(3.0, Retry::retryAfterSeconds('Thu, 01 Jan 2026 00:00:03 GMT', $now));
        self::assertSame(0.0, Retry::retryAfterSeconds('Wed, 01 Jan 2025 00:00:00 GMT', $now));
        self::assertNull(Retry::retryAfterSeconds('soon', $now));
        self::assertNull(Retry::retryAfterSeconds('', $now));
        self::assertNull(Retry::retryAfterSeconds(null));
        self::assertSame(0.0, Retry::retryAfterSeconds('-4'));
        self::assertSame(1.5, Retry::retryAfterSeconds(' 1.5 '));
        self::assertNull(Retry::retryAfterSeconds('1e999'));
        self::assertNull(Retry::retryAfterSeconds('Thu, 01 Jan 99999 00:00:00 GMT'));
        $absurd = new \ZapSMTP\Exception\RateLimitException('x', 429, response: new \ZapSMTP\ApiResponse(429, ['retry-after' => ['1e999']], ''));
        self::assertNull($absurd->getRetryAfter());
        $far = new \ZapSMTP\Exception\RateLimitException('x', 429, response: new \ZapSMTP\ApiResponse(429, ['retry-after' => [str_repeat('9', 400)]], ''));
        self::assertNull($far->getRetryAfter());

        $http = new MockHttpClient(MockHttpClient::apiError(503, 's', ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 3)]), MockHttpClient::json(200, []));
        $this->client($http)->emails->get('x');
        self::assertCount(2, $http->requests);
        self::assertGreaterThan(1.5, $this->sleeps[0]);
        self::assertLessThanOrEqual(3.0, $this->sleeps[0]);
    }

    public function testSleepsForRealByDefault(): void
    {
        $http = new MockHttpClient(MockHttpClient::apiError(503, 's', ['Retry-After' => '0.01']), MockHttpClient::json(200, []));
        $zap = new \ZapSMTP\Client('k', ['http_client' => $http]);
        $t = microtime(true);
        $zap->usage->get();
        self::assertGreaterThanOrEqual(0.009, microtime(true) - $t);
    }
}
