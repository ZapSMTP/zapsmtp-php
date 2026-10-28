<?php

declare(strict_types=1);

namespace ZapSMTP\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a list (the last answer repeats) and
 * records every request.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> request bodies, read when sent */
    public array $bodies = [];

    /** @var list<ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface> */
    private array $answers;

    /** @param ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface ...$answers */
    public function __construct(ResponseInterface|\Throwable|\Closure ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->bodies[] = (string) $request->getBody();
        $a = \count($this->answers) > 1 ? array_shift($this->answers) : ($this->answers[0] ?? new Response(200));
        if ($a instanceof \Throwable) {
            throw $a;
        }

        return $a instanceof \Closure ? $a($request) : $a;
    }

    /** @param array<string, string> $headers */
    public static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json', 'X-Request-Id' => 'req_1', ...$headers], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $headers */
    public static function apiError(int $status, string $code, array $headers = []): Response
    {
        return self::json($status, [
            'statusCode' => $status, 'name' => $code, 'message' => "$code happened",
            'error' => ['code' => $code, 'message' => "$code happened", 'type' => 'invalid_request_error'],
        ], $headers);
    }

    public function header(int $call, string $name): string
    {
        return $this->requests[$call]->getHeaderLine($name);
    }

    public function url(int $call): string
    {
        return (string) $this->requests[$call]->getUri();
    }

    /** @return array<string, string> */
    public function query(int $call): array
    {
        parse_str($this->requests[$call]->getUri()->getQuery(), $q);

        /** @var array<string, string> */
        return $q;
    }
}
