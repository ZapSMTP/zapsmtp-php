<?php

declare(strict_types=1);

namespace ZapSMTP\Exception;

use ZapSMTP\Internal\Retry;

/** Too many requests, or a quota is used up (429). */
class RateLimitException extends ZapSMTPException
{
    /** Seconds the API asked to wait (Retry-After), when it said. */
    public function getRetryAfter(): ?float
    {
        return Retry::retryAfterSeconds($this->getHeader('retry-after'));
    }
}
