<?php

declare(strict_types=1);

namespace ZapSMTP;

use GuzzleHttp\Client as GuzzleHttpClient;
use Psr\Http\Client\ClientInterface;
use ZapSMTP\Exception\ZapSMTPException;
use ZapSMTP\Internal\Transport;
use ZapSMTP\Service\ClientServices;

/**
 * The ZapSMTP API client.
 *
 *     $zap = new \ZapSMTP\Client('zap_...');            // or new Client() to read ZAP_API_KEY
 *     $sent = $zap->emails->send([
 *         'from' => 'Acme <hello@acme.com>',
 *         'to' => 'jane@example.org',
 *         'subject' => 'Hello',
 *         'html' => '<p>Hi Jane</p>',
 *     ]);
 *     echo $sent->id;
 *
 * Every API resource is a property ($zap->emails, $zap->domains, ...); each
 * method takes the API's parameters as an array and, last, per-request options:
 * ['idempotency_key' => ..., 'headers' => [...], 'timeout' => seconds, 'max_retries' => n].
 */
class Client
{
    use ClientServices;

    /** This SDK's version. */
    public const VERSION = '0.1.0';

    public const DEFAULT_BASE_URL = 'https://api.zapsmtp.com/v1';

    private const OPTIONS = ['base_url', 'timeout', 'max_retries', 'default_headers', 'http_client', 'sleeper'];

    private readonly Transport $transport;

    /**
     * @param string|null $apiKey the API key; null reads the ZAP_API_KEY environment variable
     * @param array{
     *     base_url?: string,
     *     timeout?: int|float,
     *     max_retries?: int,
     *     default_headers?: array<string, string>,
     *     http_client?: ClientInterface,
     *     sleeper?: callable(float): void,
     * } $options
     *   - base_url: defaults to ZAP_BASE_URL, else https://api.zapsmtp.com/v1
     *   - timeout: seconds per attempt (default 60); applied to Guzzle clients, other PSR-18 clients use their own
     *   - max_retries: retries of a failed request that is safe to retry (default 2)
     *   - default_headers: headers sent with every request
     *   - http_client: a PSR-18 client (default: a Guzzle client)
     *   - sleeper: waits between retries, in seconds (default usleep); for tests and event loops
     *
     * @throws ZapSMTPException no API key
     * @throws \InvalidArgumentException an invalid option
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        foreach (array_keys($options) as $k) {
            if (!\in_array($k, self::OPTIONS, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown client option "%s"; the options are %s', $k, implode(', ', self::OPTIONS)));
            }
        }
        $apiKey ??= self::env('ZAP_API_KEY');
        if ($apiKey === null || $apiKey === '') {
            throw new ZapSMTPException('Missing API key: pass it to new \ZapSMTP\Client(...) or set ZAP_API_KEY');
        }
        $baseUrl = $options['base_url'] ?? self::env('ZAP_BASE_URL') ?? self::DEFAULT_BASE_URL;
        if (!\is_string($baseUrl) || $baseUrl === '') {
            throw new \InvalidArgumentException('base_url must be a URL');
        }
        $timeout = $options['timeout'] ?? 60;
        if (!Transport::positive($timeout)) {
            throw new \InvalidArgumentException('timeout must be a number of seconds above 0');
        }
        $maxRetries = $options['max_retries'] ?? 2;
        if (!\is_int($maxRetries) || $maxRetries < 0) {
            throw new \InvalidArgumentException('max_retries must be an integer of at least 0');
        }
        $http = $options['http_client'] ?? new GuzzleHttpClient();
        if (!$http instanceof ClientInterface) {
            throw new \InvalidArgumentException('http_client must be a PSR-18 client (Psr\Http\Client\ClientInterface)');
        }
        $sleeper = $options['sleeper'] ?? null;
        if ($sleeper !== null && !\is_callable($sleeper)) {
            throw new \InvalidArgumentException('sleeper must be callable');
        }
        $this->transport = new Transport(
            $apiKey,
            rtrim($baseUrl, '/'),
            (float) $timeout,
            $maxRetries,
            Transport::checkHeaders($options['default_headers'] ?? [], 'default_headers'),
            $http,
            $sleeper === null ? null : $sleeper(...),
        );
        $this->initServices($this->transport);
    }

    /** The base URL requests go to, without a trailing slash. */
    public function getBaseUrl(): string
    {
        return $this->transport->baseUrl;
    }

    /** Seconds each attempt may take. */
    public function getTimeout(): float
    {
        return $this->transport->timeout;
    }

    /** Retries of a failed request that is safe to retry. */
    public function getMaxRetries(): int
    {
        return $this->transport->maxRetries;
    }

    /**
     * The last answer this client got (status, headers, request id), error
     * answers included; null before the first one.
     */
    public function lastResponse(): ?ApiResponse
    {
        return $this->transport->lastResponse();
    }

    /**
     * Keeps the API key out of var_dump() and print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->getBaseUrl(), 'timeout' => $this->getTimeout(), 'maxRetries' => $this->getMaxRetries()];
    }

    private static function env(string $name): ?string
    {
        foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $v) {
            if (\is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }
}
