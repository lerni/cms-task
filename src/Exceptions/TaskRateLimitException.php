<?php

namespace Kraftausdruck\Exceptions;

/**
 * Thrown by BackgroundTaskService when a fresh spawn is blocked by the
 * rate limiter. Carries the number of seconds until the limit resets so
 * callers can translate it into an HTTP 429 Retry-After response.
 */
class TaskRateLimitException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfter,
        string $message = 'Too many start requests. Please wait before retrying.',
    ) {
        parent::__construct($message);
    }
}
