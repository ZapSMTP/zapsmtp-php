<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use ZapSMTP\Exception\ConnectionException;
use ZapSMTP\Tests\Support\MockHttpClient;

/** Regressions found in the reference core (sdks/node/test/codex-regressions.test.ts). */
final class TransportRegressionsTest extends TestCase
{
    /** A body that fails when read, as a connection reset mid-answer does. */
    private static function brokenBody(\RuntimeException $failure): StreamInterface
    {
        return new class ($failure) extends \GuzzleHttp\Psr7\Stream {
            public function __construct(private readonly \RuntimeException $failure)
            {
                parent::__construct(Utils::tryFopen('php://memory', 'r+'));
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function getContents(): string
            {
                throw $this->failure;
            }
        };
    }

    public function testABrokenResponseBodyIsAConnectionErrorAndASafeRequestIsRetried(): void
    {
        $failure = new \RuntimeException('connection reset while reading body');
        $once = new MockHttpClient(new Response(200, ['X-Request-Id' => 'req_broken'], self::brokenBody($failure)));
        $zap = $this->client($once, ['max_retries' => 0]);
        try {
            $zap->emails->get('email-1');
            self::fail('no exception');
        } catch (ConnectionException $e) {
            self::assertSame($failure, $e->getPrevious());
            self::assertSame('connection reset while reading body', $e->getMessage());
            self::assertSame(0, $e->getStatusCode());
            self::assertSame(200, $e->getResponse()?->statusCode);
            self::assertSame('req_broken', $e->getRequestId());
            self::assertSame('req_broken', $zap->lastResponse()?->requestId);
        }
        $retried = new MockHttpClient(new Response(200, [], self::brokenBody($failure)), new Response(200, [], '{"id":"e1"}'));
        self::assertSame('e1', $this->client($retried)->emails->get('email-1')->id);
        self::assertCount(2, $retried->requests);
        $create = new MockHttpClient(new Response(201, [], self::brokenBody($failure)));
        $this->expectException(ConnectionException::class);
        try {
            $this->client($create)->domains->create(['name' => 'a.test']);
        } finally {
            self::assertCount(1, $create->requests);
        }
    }

    public function testATimeoutWhileReadingTheBodyIsAConnectionError(): void
    {
        // Guzzle's timeout covers the whole transfer; with stream bodies the
        // read itself fails, and that is a ConnectionException too
        $http = new MockHttpClient(new Response(200, [], self::brokenBody(new \RuntimeException('timed out after 20 ms'))));
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('timed out after 20 ms');
        $this->client($http, ['timeout' => 0.02, 'max_retries' => 0])->emails->get('email-1');
    }
}
