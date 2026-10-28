<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use ZapSMTP\Exception\AuthenticationException;
use ZapSMTP\Exception\ConflictException;
use ZapSMTP\Exception\ConnectionException;
use ZapSMTP\Exception\NotFoundException;
use ZapSMTP\Exception\PermissionException;
use ZapSMTP\Exception\RateLimitException;
use ZapSMTP\Exception\ServerException;
use ZapSMTP\Exception\ValidationException;
use ZapSMTP\Exception\ZapSMTPException;
use ZapSMTP\Tests\Support\MockHttpClient;
use ZapSMTP\Tests\Support\NetworkError;

final class ErrorsTest extends TestCase
{
    /** @return iterable<string, array{int, string, class-string<ZapSMTPException>}> */
    public static function table(): iterable
    {
        yield '400' => [400, 'validation_error', ValidationException::class];
        yield '401' => [401, 'invalid_api_key', AuthenticationException::class];
        yield '403' => [403, 'restricted_api_key', PermissionException::class];
        yield '404' => [404, 'not_found', NotFoundException::class];
        yield '409' => [409, 'conflict', ConflictException::class];
        yield '413' => [413, 'payload_too_large', ValidationException::class];
        yield '422' => [422, 'invalid_parameter', ValidationException::class];
        yield '429' => [429, 'monthly_quota_exceeded', RateLimitException::class];
        yield '500' => [500, 'internal_error', ServerException::class];
        yield '410' => [410, 'link_expired', ZapSMTPException::class];
    }

    /** @param class-string<ZapSMTPException> $class */
    #[DataProvider('table')]
    public function testMapsStatusesToClasses(int $status, string $code, string $class): void
    {
        $zap = $this->client(new MockHttpClient(MockHttpClient::apiError($status, $code)), ['max_retries' => 0]);
        try {
            $zap->emails->get('x');
            self::fail('no exception');
        } catch (ZapSMTPException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($status, $e->getStatusCode());
            self::assertSame($status, $e->getCode());
            self::assertSame($code, $e->getErrorCode());
            self::assertSame("$code happened", $e->getMessage());
            self::assertSame('invalid_request_error', $e->getErrorType());
            self::assertSame('req_1', $e->getRequestId());
            self::assertSame(['application/json'], $e->getHeaders()['content-type']);
            self::assertSame('req_1', $e->getHeader('X-Request-Id'));
            self::assertIsArray($e->getBody());
            self::assertSame($status, $e->getResponse()?->statusCode);
            self::assertSame($e->getResponse(), $zap->lastResponse());
        }
    }

    public function testReadsTheFlatErrorShapeAndABodyThatIsNotJson(): void
    {
        $flat = $this->client(new MockHttpClient(MockHttpClient::json(422, ['statusCode' => 422, 'name' => 'missing_required_field', 'message' => 'subject is required'])));
        try {
            $flat->emails->get('x');
            self::fail('no exception');
        } catch (ValidationException $e) {
            self::assertSame('missing_required_field', $e->getErrorCode());
            self::assertSame('subject is required', $e->getMessage());
            self::assertSame('', $e->getErrorType());
        }
        $text = $this->client(new MockHttpClient(new Response(418, [], 'bad gateway')));
        try {
            $text->emails->get('x');
            self::fail('no exception');
        } catch (ZapSMTPException $e) {
            self::assertSame(418, $e->getStatusCode());
            self::assertSame('', $e->getErrorCode());
            self::assertSame('HTTP 418', $e->getMessage());
            self::assertNull($e->getRequestId());
            self::assertSame('bad gateway', $e->getBody());
        }
        $odd = $this->client(new MockHttpClient(MockHttpClient::json(400, ['error' => ['code' => 7, 'message' => null]])));
        try {
            $odd->emails->get('x');
            self::fail('no exception');
        } catch (ValidationException $e) {
            self::assertSame('', $e->getErrorCode());
            self::assertSame('HTTP 400', $e->getMessage());
        }
    }

    public function testGivesRetryAfterOnRateLimits(): void
    {
        $zap = $this->client(new MockHttpClient(MockHttpClient::apiError(429, 'monthly_quota_exceeded', ['Retry-After' => '7'])));
        try {
            $zap->emails->get('x');
            self::fail('no exception');
        } catch (RateLimitException $e) {
            self::assertSame(7.0, $e->getRetryAfter());
        }
        $bare = new RateLimitException('x');
        self::assertNull($bare->getRetryAfter());
        self::assertSame([], $bare->getHeaders());
        self::assertNull($bare->getRequestId());
        self::assertNull($bare->getBody());
    }

    public function testWrapsNetworkFailures(): void
    {
        $cause = new NetworkError('connection refused');
        $zap = $this->client(new MockHttpClient($cause), ['max_retries' => 0]);
        try {
            $zap->emails->get('x');
            self::fail('no exception');
        } catch (ConnectionException $e) {
            self::assertSame(0, $e->getStatusCode());
            self::assertSame('connection refused', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
            self::assertNull($e->getResponse());
        }
        $silent = $this->client(new MockHttpClient(new NetworkError('')), ['max_retries' => 0]);
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage(NetworkError::class);
        $silent->emails->get('x');
    }
}
