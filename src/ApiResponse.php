<?php

declare(strict_types=1);

namespace ZapSMTP;

/**
 * What the API answered besides the decoded body: the status, the headers
 * (rate limits, quota) and the request id.
 */
final class ApiResponse
{
    /** X-Request-Id: quote it to support. */
    public readonly ?string $requestId;

    /**
     * @param int $statusCode HTTP status
     * @param array<string, list<string>> $headers header names in lower case
     * @param string $body the raw body
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {
        $this->requestId = $this->getHeader('x-request-id');
    }

    /** One header (case-insensitive), its values joined with ", "; null when absent. */
    public function getHeader(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : implode(', ', $values);
    }
}
