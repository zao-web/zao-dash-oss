<?php

namespace App\Agents\Tools;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Trait for tools that should retry on transient failures.
 *
 * Add `use Retryable;` to your tool class and optionally override
 * maxRetries() or retryableExceptions() to customize behavior.
 *
 * Example:
 *
 *     class MyExternalApiTool extends BaseTool
 *     {
 *         use Retryable;
 *
 *         // Optional: Override for more retries
 *         public function maxRetries(): int
 *         {
 *             return 5;
 *         }
 *     }
 */
trait Retryable
{
    /**
     * Maximum number of retry attempts.
     *
     * Override this in your tool for custom retry counts.
     * Default: 3 retries (4 total attempts)
     */
    public function maxRetries(): int
    {
        return 3;
    }

    /**
     * Exception classes that should trigger a retry.
     *
     * Override this to add custom retryable exceptions.
     *
     * @return array<class-string<\Throwable>>
     */
    public function retryableExceptions(): array
    {
        return [
            ConnectionException::class,
            RequestException::class,
            ConnectException::class,
            ServerException::class,
        ];
    }
}
