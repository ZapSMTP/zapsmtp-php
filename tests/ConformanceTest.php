<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use ZapSMTP\ApiObject;
use ZapSMTP\Client;
use ZapSMTP\Page;

/**
 * Every API operation, called through the SDK against the conformance mock
 * (sdks/conformance/server.mjs), which checks the exact request it gets.
 * The server's URL comes from ZAP_CONFORMANCE_URL (sdks/scripts/docker-test.sh sets it).
 */
final class ConformanceTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function cases(): array
    {
        $file = file_get_contents(__DIR__ . '/../../conformance/cases.json');
        self::assertIsString($file);
        /** @var list<array<string, mixed>> */
        return json_decode($file, true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function caseList(): iterable
    {
        foreach (self::cases() as $c) {
            yield (string) (\is_string($c['id'] ?? null) ? $c['id'] : '?') => [$c];
        }
    }

    public function testCoversEveryOperationOnce(): void
    {
        $ids = array_map(static fn (array $c): string => \is_string($c['id'] ?? null) ? $c['id'] : '', self::cases());
        self::assertCount(\count($ids), array_unique($ids));
        self::assertGreaterThanOrEqual(114, \count($ids));
    }

    /** @param array<string, mixed> $c */
    #[DataProvider('caseList')]
    public function testCase(array $c): void
    {
        $url = getenv('ZAP_CONFORMANCE_URL');
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('ZAP_CONFORMANCE_URL is not set: run through sdks/scripts/docker-test.sh');
        }
        $zap = new Client('zap_test_conformance', ['base_url' => $url, 'max_retries' => 0]);
        /** @var array{resource: list<string>, method: string, pathArgs: list<string>, params: array<string, mixed>|null, items?: list<mixed>, options?: array<string, mixed>} $call */
        $call = $c['call'];
        /** @var array{status: int, body: array<string, mixed>|null} $response */
        $response = $c['response'];
        $target = $zap;
        foreach ($call['resource'] as $r) {
            $target = $target->{$r};
            self::assertIsObject($target);
        }
        $callable = [$target, $call['method']];
        self::assertIsCallable($callable, "no method {$call['method']}");
        $args = $call['pathArgs'];
        if (isset($call['items'])) {
            $args[] = $call['items'];
            $args[] = $call['options'] ?? [];
        } elseif ($call['params'] !== null) {
            $args[] = $call['params'];
        }
        $res = $callable(...$args);
        if ($response['body'] === null) {
            self::assertNull($res);
        } elseif ($res instanceof Page) {
            self::assertSame($response['body']['data'], array_map(static fn (ApiObject $o) => $o->toArray(), $res->data));
            self::assertSame($response['body'], $res->toArray());
        } else {
            self::assertInstanceOf(ApiObject::class, $res);
            self::assertSame($response['body'], $res->toArray());
        }
        self::assertSame($response['status'], $zap->lastResponse()?->statusCode);
        self::assertSame('req_conformance', $zap->lastResponse()->requestId);
    }
}
