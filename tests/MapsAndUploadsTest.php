<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use ZapSMTP\Tests\Support\MockHttpClient;

/** Maps keyed 0, 1, ... and non-seekable uploads (found by Codex review). */
final class MapsAndUploadsTest extends TestCase
{
    public function testANumericMetadataFilterKeepsItsKey(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(200, ['object' => 'list', 'data' => [], 'has_more' => false]));
        /** @phpstan-ignore argument.type (PHP turns the keys '0', '1' into ints) */
        $this->client($http)->emails->list(['metadata' => ['0' => 'order-42', '1' => 'b']]);
        self::assertSame('https://api.test/v1/emails?metadata%5B0%5D=order-42&metadata%5B1%5D=b', $http->url(0));
    }

    public function testANumericKeyedMapStaysAJsonObject(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(200, ['id' => 'e1']));
        $zap = $this->client($http);
        /** @phpstan-ignore argument.type (PHP turns the key '0' into an int) */
        $zap->emails->send(['to' => 'a@example.org', 'metadata' => ['0' => 'order-42'], 'headers' => ['0' => 'x'],
            'template' => ['id' => 't', 'variables' => ['0' => 'v']], 'tags' => [['name' => 'n', 'value' => 'v']]]);
        self::assertSame('{"to":"a@example.org","metadata":{"0":"order-42"},"headers":{"0":"x"},"template":{"id":"t","variables":{"0":"v"}},"tags":[{"name":"n","value":"v"}]}', $http->bodies[0]);
        /** @phpstan-ignore argument.type (PHP turns the keys '0', '1' into ints) */
        $zap->emails->sendBatch([['to' => 'a@example.org', 'metadata' => ['0' => 'order-42', '1' => 'b']]]);
        self::assertSame('[{"to":"a@example.org","metadata":{"0":"order-42","1":"b"}}]', $http->bodies[1]);
        /** @phpstan-ignore argument.type (PHP turns the key '0' into an int) */
        $zap->templates->update('t1', ['variables' => ['0' => ['type' => 'string']]]);
        self::assertStringStartsWith('{"variables":{"0":', $http->bodies[2]);
    }

    public function testARetriedUploadReplaysANonSeekableStream(): void
    {
        $http = new MockHttpClient(MockHttpClient::apiError(429, 'rate_limit_exceeded', ['Retry-After' => '0']), MockHttpClient::json(201, ['id' => 'img']));
        $this->client($http)->templateAssets->uploadFile(new NoSeekStream(Utils::streamFor('PNG-BYTES')), ['name' => 'a.png']);
        self::assertCount(2, $http->bodies);
        self::assertStringContainsString('PNG-BYTES', $http->bodies[1]);
        self::assertSame($http->bodies[0], $http->bodies[1]);
    }
}
