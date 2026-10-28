<?php

declare(strict_types=1);

namespace ZapSMTP\Internal;

use Psr\Http\Message\StreamInterface;

/**
 * An API call, before it is sent.
 *
 * @internal
 */
final class Request
{
    /**
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed>|null $body JSON; an empty array is sent as {}, a list as [...]
     * @param array<string, string|null> $headers null values are not sent
     * @param bool $idempotent the operation takes an Idempotency-Key: the SDK always sends one and may retry
     * @param list<string> $objects paths in the body that are JSON objects, so an empty array there is sent as {}:
     *        '' the body, 'a.b' a nested field, 'a.*' every item of a list or map
     * @param list<string> $queryMaps query parameters that are maps: key[name]=value, whatever the names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?array $body = null,
        public readonly array $headers = [],
        public readonly bool $idempotent = false,
        public readonly ?StreamInterface $multipart = null,
        public readonly string $boundary = '',
        public readonly array $objects = [],
        public readonly array $queryMaps = [],
    ) {
    }

    /**
     * The same request with more query parameters.
     *
     * @param array<array-key, mixed> $query
     */
    public function withQuery(array $query): self
    {
        return new self($this->method, $this->path, array_merge($this->query, $query), $this->body, $this->headers,
            $this->idempotent, $this->multipart, $this->boundary, $this->objects, $this->queryMaps);
    }
}
