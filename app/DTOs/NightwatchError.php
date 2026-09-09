<?php

namespace App\DTOs;

class NightwatchError
{
    public function __construct(
        public string $exceptionClass,
        public string $message,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $sourceJob = null,
        public string $environment = 'production',
        public int $occurrenceCount = 1,
        public ?string $nightwatchUrl = null,
        public string $slackMessageTs = '',
        public string $slackChannelId = '',
    ) {}

    /**
     * Generate a unique signature for deduplication.
     */
    public function getSignature(): string
    {
        return md5(implode('|', [
            $this->exceptionClass,
            $this->message,
            $this->sourceJob ?? '',
        ]));
    }

    /**
     * Check if this error type should be auto-fixed.
     */
    public function isAutoFixable(): bool
    {
        $autoFixPatterns = [
            '/Undefined column/i',
            '/column .* does not exist/i',
            '/Target class .* does not exist/i',
            '/Class .* not found/i',
            '/Call to undefined method/i',
            '/Undefined property/i',
            '/Type error: Argument/i',
            '/must be of type/i',
        ];

        foreach ($autoFixPatterns as $pattern) {
            if (preg_match($pattern, $this->message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this error should be skipped (infrastructure issues).
     */
    public function shouldSkip(): bool
    {
        // Skip self-healing's own errors to prevent infinite loops
        if ($this->sourceJob && str_contains($this->sourceJob, 'SelfHealing')) {
            return true;
        }

        $skipPatterns = [
            // Infrastructure errors
            '/Connection refused/i',
            '/Too many connections/i',
            '/Out of memory/i',
            '/Maximum execution time/i',
            '/Connection timed out/i',
            '/SQLSTATE\[HY000\].*Gone away/i',
            '/Redis connection/i',
            '/cURL error/i',
            // Queue/job system errors (not fixable by code changes)
            '/MaxAttemptsExceededException/i',
            '/SelfHealingJob/i',
        ];

        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $this->message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a brief description for commit messages.
     */
    public function getBriefDescription(): string
    {
        // Extract key info for commit message
        if (preg_match('/column ["\']?(\w+)["\']?.*does not exist/i', $this->message, $matches)) {
            return "Add missing column '{$matches[1]}'";
        }

        if (preg_match('/Target class \[([^\]]+)\] does not exist/i', $this->message, $matches)) {
            return "Add missing class {$matches[1]}";
        }

        if (preg_match('/Call to undefined method ([^(]+)/i', $this->message, $matches)) {
            return "Add missing method {$matches[1]}";
        }

        // Fallback: truncate message
        return \Illuminate\Support\Str::limit($this->message, 50);
    }

    public function toArray(): array
    {
        return [
            'exception_class' => $this->exceptionClass,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'source_job' => $this->sourceJob,
            'environment' => $this->environment,
            'occurrence_count' => $this->occurrenceCount,
            'nightwatch_url' => $this->nightwatchUrl,
            'slack_message_ts' => $this->slackMessageTs,
            'slack_channel_id' => $this->slackChannelId,
        ];
    }
}
