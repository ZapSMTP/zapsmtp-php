<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use ZapSMTP\Client;
use ZapSMTP\Tests\Support\MockHttpClient;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var list<float> waits between retries, in seconds */
    protected array $sleeps = [];

    /** @param array<string, mixed> $options */
    protected function client(MockHttpClient $http, array $options = []): Client
    {
        /** @phpstan-ignore argument.type (options are checked at runtime) */
        return new Client('zap_test_key', [
            'base_url' => 'https://api.test/v1/',
            'http_client' => $http,
            'sleeper' => function (float $s): void {
                $this->sleeps[] = $s;
            },
            ...$options,
        ]);
    }
}
