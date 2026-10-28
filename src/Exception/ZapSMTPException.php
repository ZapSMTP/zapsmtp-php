<?php

declare(strict_types=1);

namespace ZapSMTP\Exception;

use ZapSMTP\ApiResponse;

/**
 * An error answer of the API, or no answer at all (ConnectionException).
 *
 * Every exception the SDK throws for a request is one of these; catch the
 * subclasses for the cases you handle and this class for the rest.
 */
class ZapSMTPException extends \RuntimeException
{
    /**
     * @param int $statusCode HTTP status; 0 when there was no answer
     * @param string $errorCode the API's error code, e.g. "invalid_parameter"
     * @param string $errorType the error type, e.g. "invalid_request_error"
     * @param mixed $body the parsed error body
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly string $errorCode = '',
        private readonly string $errorType = '',
        private readonly ?ApiResponse $response = null,
        private readonly mixed $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /** HTTP status; 0 when there was no answer. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** The API's error code, e.g. "invalid_parameter"; empty when it gave none. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** The error type, e.g. "invalid_request_error"; empty when it gave none. */
    public function getErrorType(): string
    {
        return $this->errorType;
    }

    /** X-Request-Id of the answer: quote it to support. */
    public function getRequestId(): ?string
    {
        return $this->response?->requestId;
    }

    /**
     * The answer's headers, names in lower case.
     *
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->response === null ? [] : $this->response->headers;
    }

    /** One header of the answer (case-insensitive), its values joined with ", ". */
    public function getHeader(string $name): ?string
    {
        return $this->response?->getHeader($name);
    }

    /** The parsed error body (JSON), the raw text when it was not JSON, or null. */
    public function getBody(): mixed
    {
        return $this->body;
    }

    /** The answer, when there was one. */
    public function getResponse(): ?ApiResponse
    {
        return $this->response;
    }
}
