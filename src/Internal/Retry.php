<?php

declare(strict_types=1);

namespace ZapSMTP\Internal;

/**
 * When to retry and how long to wait (docs/DESIGN-sdks.md, Retries).
 *
 * @internal
 */
final class Retry
{
    private const RETRY_STATUSES = [408, 429, 500, 502, 503, 504];
    private const NO_RETRY_429 = ['monthly_quota_exceeded', 'daily_quota_exceeded', 'thread_reply_limit'];

    /** Whether an error answer is worth another attempt. */
    public static function retryable(int $status, string $code, bool $safe): bool
    {
        if ($status === 429) {
            return !\in_array($code, self::NO_RETRY_429, true); // refused before any work: always safe
        }
        if ($status === 409) {
            return $code === 'idempotency_request_in_progress';
        }

        return $safe && \in_array($status, self::RETRY_STATUSES, true);
    }

    /** Seconds to wait before attempt number $attempt + 1: Retry-After (up to 60 s), else exponential with jitter. */
    public static function backoff(int $attempt, ?float $retryAfter): float
    {
        if ($retryAfter !== null) {
            return min($retryAfter, 60.0);
        }
        $base = min(0.5 * 2 ** min($attempt, 16), 8.0);

        return $base / 2 + mt_rand() / mt_getrandmax() * ($base / 2);
    }

    /** A Retry-After value in seconds: a number, or an HTTP date; null when absent or unreadable. */
    public static function retryAfterSeconds(?string $value, ?float $now = null): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (is_numeric(trim($value))) {
            $seconds = (float) trim($value);

            return is_finite($seconds) ? max(0.0, $seconds) : null;
        }
        $at = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', trim($value), new \DateTimeZone('UTC'));
        if ($at === false) {
            return null;
        }

        return max(0.0, (float) $at->getTimestamp() - ($now ?? microtime(true)));
    }
}
