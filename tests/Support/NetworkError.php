<?php

declare(strict_types=1);

namespace ZapSMTP\Tests\Support;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** A PSR-18 network failure. */
final class NetworkError extends \RuntimeException implements NetworkExceptionInterface
{
    public function getRequest(): RequestInterface
    {
        return new \GuzzleHttp\Psr7\Request('GET', 'https://api.test');
    }
}
