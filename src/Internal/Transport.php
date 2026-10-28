<?php

declare(strict_types=1);

namespace ZapSMTP\Internal;

use GuzzleHttp\ClientInterface as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ZapSMTP\ApiResponse;
use ZapSMTP\Client;
use ZapSMTP\Exception\AuthenticationException;
use ZapSMTP\Exception\ConflictException;
use ZapSMTP\Exception\ConnectionException;
use ZapSMTP\Exception\NotFoundException;
use ZapSMTP\Exception\PermissionException;
use ZapSMTP\Exception\RateLimitException;
use ZapSMTP\Exception\ServerException;
use ZapSMTP\Exception\ValidationException;
use ZapSMTP\Exception\ZapSMTPException;

/**
 * Sends API calls: headers, JSON, retries, errors.
 *
 * @internal
 */
final class Transport
{
    private const REQUEST_OPTIONS = ['idempotency_key', 'headers', 'timeout', 'max_retries'];

    private ?ApiResponse $lastResponse = null;

    private readonly HttpFactory $factory;

    /** @var \Closure(float): void */
    private readonly \Closure $sleeper;

    /**
     * @param array<string, string> $defaultHeaders
     * @param (\Closure(float): void)|null $sleeper waits between attempts (default usleep)
     */
    public function __construct(
        private readonly string $apiKey,
        public readonly string $baseUrl,
        public readonly float $timeout,
        public readonly int $maxRetries,
        private readonly array $defaultHeaders,
        private readonly ClientInterface $http,
        ?\Closure $sleeper = null,
    ) {
        $this->factory = new HttpFactory();
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    public function lastResponse(): ?ApiResponse
    {
        return $this->lastResponse;
    }

    /**
     * Sends a call and returns the decoded body (null when empty, the text
     * when it is not JSON) with the answer.
     *
     * @param array<array-key, mixed> $options
     *
     * @return array{mixed, ApiResponse}
     *
     * @throws ZapSMTPException
     * @throws \InvalidArgumentException an invalid request option
     */
    public function send(Request $req, array $options): array
    {
        $options = self::checkOptions($options);
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => sprintf('zapsmtp-php/%s (php/%s)', Client::VERSION, \PHP_VERSION),
        ];
        foreach ([...$this->defaultHeaders, ...array_filter($req->headers, static fn ($v) => $v !== null)] as $k => $v) {
            $headers = self::setHeader($headers, (string) $k, (string) $v);
        }
        if ($req->idempotent) {
            $headers = self::setHeader($headers, 'Idempotency-Key', $options['idempotency_key'] ?? self::uuid4());
        }
        foreach ($options['headers'] ?? [] as $k => $v) {
            $headers = self::setHeader($headers, $k, $v);
        }
        // the server skips deduplication for an empty key, so a retried send
        // could go out twice: refused before sending
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Idempotency-Key') === 0 && trim($v) === '') {
                throw new ValidationException('idempotency_key must not be empty', errorCode: 'invalid_parameter');
            }
        }

        $request = $this->factory->createRequest($req->method, $this->url($req));
        if ($req->multipart !== null) {
            $headers = self::setHeader($headers, 'Content-Type', 'multipart/form-data; boundary=' . $req->boundary);
            $request = $request->withBody($req->multipart);
        } elseif ($req->body !== null) {
            $headers = self::setHeader($headers, 'Content-Type', 'application/json');
            $request = $request->withBody($this->factory->createStream(self::json(self::objects($req->body, $req->objects))));
        }
        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }

        $timeout = (float) ($options['timeout'] ?? $this->timeout);
        $maxRetries = $options['max_retries'] ?? $this->maxRetries;
        $safe = \in_array($req->method, ['GET', 'PUT', 'DELETE'], true) || $req->idempotent;

        for ($attempt = 0; ; $attempt++) {
            $res = null;
            try {
                $res = $this->attempt($request, $timeout);
                $text = self::read($res);
            } catch (ClientExceptionInterface|\RuntimeException $e) {
                // a timeout or a broken connection (sending, or reading the
                // answer): the request may have reached the server, so only a
                // request that is safe to repeat is sent again. When the
                // headers came, the status, headers and request id are kept.
                $partial = $res === null ? null : self::info($res, '');
                if ($partial !== null) {
                    $this->lastResponse = $partial;
                }
                if ($attempt < $maxRetries && $safe) {
                    ($this->sleeper)(Retry::backoff($attempt, null));
                    continue;
                }
                throw new ConnectionException($e->getMessage() !== '' ? $e->getMessage() : $e::class, response: $partial, previous: $e);
            }
            $info = self::info($res, $text);
            $this->lastResponse = $info;
            $parsed = null;
            if ($text !== '') {
                try {
                    $parsed = self::finite(json_decode($text, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING));
                } catch (\JsonException) {
                    $parsed = $text;
                }
            }
            $status = $res->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return [$parsed, $info];
            }
            $err = self::errorFor($status, $parsed, $info);
            if ($attempt < $maxRetries && Retry::retryable($status, $err->getErrorCode(), $safe)) {
                ($this->sleeper)(Retry::backoff($attempt, Retry::retryAfterSeconds($info->getHeader('retry-after'))));
                continue;
            }
            throw $err;
        }
    }

    /**
     * One attempt. Guzzle reads the whole body within the timeout, so it
     * also covers a server that sends its headers and then stalls.
     *
     * @throws ClientExceptionInterface
     */
    private function attempt(RequestInterface $request, float $timeout): ResponseInterface
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        // Guzzle takes a per-request timeout (for the whole transfer, body
        // included); a plain PSR-18 client uses its own settings.
        return $this->http instanceof GuzzleClient
            ? $this->http->send($request, ['timeout' => $timeout, 'http_errors' => false, 'allow_redirects' => false])
            : $this->http->sendRequest($request);
    }

    /**
     * The whole body of an answer.
     *
     * @throws \RuntimeException the body could not be read
     */
    private static function read(ResponseInterface $res): string
    {
        $stream = $res->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $stream->getContents();
    }

    private static function info(ResponseInterface $res, string $text): ApiResponse
    {
        $headers = [];
        foreach ($res->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = array_values($values);
        }

        return new ApiResponse($res->getStatusCode(), $headers, $text);
    }

    private function url(Request $req): string
    {
        $pairs = [];
        foreach ($req->query as $k => $v) {
            if (\is_array($v)) {
                foreach ($v as $mk => $mv) {
                    if (\is_scalar($mv)) {
                        $map = \in_array($k, $req->queryMaps, true) || !array_is_list($v);
                        $pairs[] = urlencode($map ? "{$k}[{$mk}]" : (string) $k) . '=' . urlencode(self::scalar($mv));
                    }
                }
            } elseif (\is_scalar($v)) {
                $pairs[] = urlencode((string) $k) . '=' . urlencode(self::scalar($v));
            }
        }

        return $this->baseUrl . $req->path . ($pairs === [] ? '' : '?' . implode('&', $pairs));
    }

    /** A number too large for a float (1e999) decodes as INF: it becomes null. */
    private static function finite(mixed $value): mixed
    {
        if (\is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (\is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::finite($v);
            }
        }

        return $value;
    }

    private static function scalar(bool|int|float|string $v): string
    {
        return \is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
    }

    /**
     * Sets a header, replacing one of the same name in any case.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function setHeader(array $headers, string $name, string $value): array
    {
        foreach (array_keys($headers) as $k) {
            if (strcasecmp($k, $name) === 0) {
                unset($headers[$k]);
            }
        }
        $headers[$name] = $value;

        return $headers;
    }

    /**
     * Makes the arrays at object paths JSON objects: PHP has one array for
     * [] and {}, so an empty map, or one keyed 0, 1, ..., would be sent as a
     * JSON list.
     *
     * @param list<string> $paths
     */
    private static function objects(mixed $value, array $paths): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $children = [];
        $here = false;
        foreach ($paths as $p) {
            if ($p === '') {
                $here = true;
                continue;
            }
            [$head, $rest] = array_pad(explode('.', $p, 2), 2, '');
            $children[$head][] = $rest;
        }
        foreach ($value as $k => $v) {
            $sub = [...($children['*'] ?? []), ...($children[(string) $k] ?? [])];
            if ($sub !== []) {
                $value[$k] = self::objects($v, $sub);
            }
        }

        // a map whose names are 0, 1, ... is a PHP list: still a JSON object
        return $here && array_is_list($value) ? (object) $value : $value;
    }

    /**
     * @throws ZapSMTPException
     */
    private static function json(mixed $body): string
    {
        try {
            return json_encode($body,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new ZapSMTPException('The request body cannot be encoded as JSON: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Validates per-request options.
     *
     * @param array<array-key, mixed> $options
     *
     * @return array{idempotency_key?: string, headers?: array<string, string>, timeout?: int|float, max_retries?: int}
     */
    public static function checkOptions(array $options): array
    {
        foreach ($options as $k => $v) {
            if (!\in_array($k, self::REQUEST_OPTIONS, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown request option "%s"; the options are %s', $k, implode(', ', self::REQUEST_OPTIONS)));
            }
        }
        if (isset($options['idempotency_key']) && !\is_string($options['idempotency_key'])) {
            throw new \InvalidArgumentException('idempotency_key must be a string');
        }
        if (isset($options['timeout']) && !self::positive($options['timeout'])) {
            throw new \InvalidArgumentException('timeout must be a number of seconds above 0');
        }
        if (isset($options['max_retries']) && (!\is_int($options['max_retries']) || $options['max_retries'] < 0)) {
            throw new \InvalidArgumentException('max_retries must be an integer of at least 0');
        }
        $out = array_intersect_key($options, ['idempotency_key' => 1, 'timeout' => 1, 'max_retries' => 1]);
        if (isset($options['headers'])) {
            $out['headers'] = self::checkHeaders($options['headers'], 'headers');
        }

        /** @var array{idempotency_key?: string, headers?: array<string, string>, timeout?: int|float, max_retries?: int} */
        return array_filter($out, static fn ($v) => $v !== null);
    }

    /** @phpstan-assert-if-true int|float $v */
    public static function positive(mixed $v): bool
    {
        return (\is_int($v) || \is_float($v)) && $v > 0;
    }

    /**
     * @return array<string, string>
     */
    public static function checkHeaders(mixed $headers, string $what): array
    {
        if (!\is_array($headers)) {
            throw new \InvalidArgumentException("$what must be an array of header names to values");
        }
        $out = [];
        foreach ($headers as $k => $v) {
            if (!\is_string($k) || !\is_string($v)) {
                throw new \InvalidArgumentException("$what must be an array of header names to string values");
            }
            $out[$k] = $v;
        }

        return $out;
    }

    private static function errorFor(int $status, mixed $body, ApiResponse $info): ZapSMTPException
    {
        $error = \is_array($body) && \is_array($body['error'] ?? null) ? $body['error'] : [];
        $str = static fn (mixed $v): ?string => \is_string($v) ? $v : null;
        $code = $str($error['code'] ?? null) ?? (\is_array($body) ? $str($body['name'] ?? null) : null) ?? '';
        $message = $str($error['message'] ?? null) ?? (\is_array($body) ? $str($body['message'] ?? null) : null) ?? "HTTP {$status}";
        $type = $str($error['type'] ?? null) ?? '';
        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 400, $status === 413, $status === 422 => ValidationException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => ZapSMTPException::class,
        };

        return new $class($message, $status, $code, $type, $info, $body);
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
