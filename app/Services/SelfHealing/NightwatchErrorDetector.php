<?php

namespace App\Services\SelfHealing;

use App\DTOs\NightwatchError;
use App\Models\SlackMessage;
use Illuminate\Support\Facades\Log;

class NightwatchErrorDetector
{
    protected string $nightwatchBotId;

    protected string $monitoredChannel;

    public function __construct()
    {
        $this->nightwatchBotId = config('self-healing.nightwatch_bot_id', '');
        $this->monitoredChannel = config('self-healing.slack_channel', '#ops-logs');
    }

    /**
     * Check if a Slack message is from Nightwatch.
     */
    public function isNightwatchError(SlackMessage $message): bool
    {
        // Check if from Nightwatch bot
        if ($this->nightwatchBotId && $message->user_id !== $this->nightwatchBotId) {
            // Also check bot_id in message metadata if available
            $metadata = $message->metadata ?? [];
            if (($metadata['bot_id'] ?? null) !== $this->nightwatchBotId) {
                return false;
            }
        }

        // Check for error indicators in content
        $content = $message->content ?? '';
        $hasErrorMarkers = (
            str_contains($content, 'Exception') ||
            str_contains($content, 'Error') ||
            str_contains($content, 'SQLSTATE') ||
            str_contains($content, 'Stack trace')
        );

        return $hasErrorMarkers;
    }

    /**
     * Check if raw Slack event data is from Nightwatch.
     */
    public function isNightwatchEvent(array $event): bool
    {
        $botId = $event['bot_id'] ?? $event['user'] ?? null;
        $text = $event['text'] ?? '';
        $attachments = $event['attachments'] ?? [];

        Log::debug('Self-healing: isNightwatchEvent check', [
            'configured_bot_id' => $this->nightwatchBotId,
            'event_bot_id' => $botId,
            'text_preview' => substr($text, 0, 100),
            'has_attachments' => ! empty($attachments),
        ]);

        // Check bot ID if configured
        if ($this->nightwatchBotId && $botId !== $this->nightwatchBotId) {
            // Check attachments for Nightwatch format
            if (empty($attachments)) {
                Log::debug('Self-healing: Bot ID mismatch and no attachments', [
                    'expected' => $this->nightwatchBotId,
                    'got' => $botId,
                ]);

                return false;
            }
        }

        // Check for Nightwatch error patterns
        $hasErrorMarkers = (
            str_contains($text, 'Exception') ||
            str_contains($text, 'Error') ||
            str_contains($text, 'SQLSTATE') ||
            $this->hasErrorAttachments($event)
        );

        Log::debug('Self-healing: Error markers check', [
            'has_error_markers' => $hasErrorMarkers,
            'contains_exception' => str_contains($text, 'Exception'),
            'contains_error' => str_contains($text, 'Error'),
            'contains_sqlstate' => str_contains($text, 'SQLSTATE'),
            'has_error_attachments' => $this->hasErrorAttachments($event),
        ]);

        return $hasErrorMarkers;
    }

    /**
     * Parse a Slack message into a NightwatchError DTO.
     */
    public function parse(SlackMessage $message): ?NightwatchError
    {
        return $this->parseFromData(
            content: $message->content ?? '',
            attachments: $message->attachments ?? [],
            messageTs: $message->message_ts,
            channelId: $message->channel_id,
        );
    }

    /**
     * Parse from raw Slack event data.
     */
    public function parseFromEvent(array $event): ?NightwatchError
    {
        return $this->parseFromData(
            content: $event['text'] ?? '',
            attachments: $event['attachments'] ?? [],
            messageTs: $event['ts'] ?? '',
            channelId: $event['channel'] ?? '',
        );
    }

    /**
     * Core parsing logic.
     */
    protected function parseFromData(
        string $content,
        array $attachments,
        string $messageTs,
        string $channelId
    ): ?NightwatchError {
        try {
            // Try to parse from attachments first (structured data)
            if (! empty($attachments)) {
                $error = $this->parseFromAttachments($attachments, $messageTs, $channelId);
                if ($error) {
                    return $error;
                }
            }

            // Fall back to parsing plain text
            return $this->parseFromText($content, $messageTs, $channelId);

        } catch (\Exception $e) {
            Log::warning('Failed to parse Nightwatch error', [
                'error' => $e->getMessage(),
                'content' => substr($content, 0, 500),
            ]);

            return null;
        }
    }

    /**
     * Parse error from Slack attachments (Nightwatch often uses these).
     */
    protected function parseFromAttachments(array $attachments, string $messageTs, string $channelId): ?NightwatchError
    {
        foreach ($attachments as $attachment) {
            $title = $attachment['title'] ?? '';
            $text = $attachment['text'] ?? '';
            $fallback = $attachment['fallback'] ?? '';
            $fields = $attachment['fields'] ?? [];

            // Extract exception class from title
            $exceptionClass = $this->extractExceptionClass($title) ??
                              $this->extractExceptionClass($fallback) ??
                              'Unknown';

            // Extract message
            $message = $this->extractErrorMessage($text) ??
                      $this->extractErrorMessage($fallback) ??
                      $text;

            if (empty($message)) {
                continue;
            }

            // Extract additional info from fields
            $file = null;
            $line = null;
            $sourceJob = null;
            $environment = 'production';
            $occurrenceCount = 1;
            $nightwatchUrl = $attachment['title_link'] ?? null;

            foreach ($fields as $field) {
                $fieldTitle = strtolower($field['title'] ?? '');
                $fieldValue = $field['value'] ?? '';

                if (str_contains($fieldTitle, 'file') || str_contains($fieldTitle, 'location')) {
                    if (preg_match('/([^:]+):(\d+)/', $fieldValue, $matches)) {
                        $file = $matches[1];
                        $line = (int) $matches[2];
                    } else {
                        $file = $fieldValue;
                    }
                }

                if (str_contains($fieldTitle, 'job') || str_contains($fieldTitle, 'source')) {
                    $sourceJob = $fieldValue;
                }

                if (str_contains($fieldTitle, 'environment') || str_contains($fieldTitle, 'env')) {
                    $environment = $fieldValue;
                }

                if (str_contains($fieldTitle, 'occurrence') || str_contains($fieldTitle, 'count')) {
                    $occurrenceCount = (int) preg_replace('/\D/', '', $fieldValue) ?: 1;
                }
            }

            return new NightwatchError(
                exceptionClass: $exceptionClass,
                message: $message,
                file: $file,
                line: $line,
                sourceJob: $sourceJob,
                environment: $environment,
                occurrenceCount: $occurrenceCount,
                nightwatchUrl: $nightwatchUrl,
                slackMessageTs: $messageTs,
                slackChannelId: $channelId,
            );
        }

        return null;
    }

    /**
     * Parse error from plain text content.
     */
    protected function parseFromText(string $content, string $messageTs, string $channelId): ?NightwatchError
    {
        if (empty(trim($content))) {
            return null;
        }

        $exceptionClass = $this->extractExceptionClass($content) ?? 'Unknown';
        $message = $this->extractErrorMessage($content) ?? $content;

        // Try to extract file:line
        $file = null;
        $line = null;
        if (preg_match('/(?:in |at )([\/\w.-]+\.php):(\d+)/i', $content, $matches)) {
            $file = $matches[1];
            $line = (int) $matches[2];
        }

        // Try to extract job name
        $sourceJob = null;
        if (preg_match('/(?:job|Job)[:\s]+([A-Z][\w\\\\]+)/i', $content, $matches)) {
            $sourceJob = $matches[1];
        } elseif (preg_match('/App\\\\Jobs\\\\(\w+)/', $content, $matches)) {
            $sourceJob = "App\\Jobs\\{$matches[1]}";
        }

        // Try to extract occurrence count
        $occurrenceCount = 1;
        if (preg_match('/(\d+)\s*(?:times?|occurrences?)/i', $content, $matches)) {
            $occurrenceCount = (int) $matches[1];
        }

        // Try to extract Nightwatch URL
        $nightwatchUrl = null;
        if (preg_match('/(https?:\/\/[^\s]+nightwatch[^\s>]+)/i', $content, $matches)) {
            $nightwatchUrl = $matches[1];
        }

        return new NightwatchError(
            exceptionClass: $exceptionClass,
            message: $message,
            file: $file,
            line: $line,
            sourceJob: $sourceJob,
            environment: 'production',
            occurrenceCount: $occurrenceCount,
            nightwatchUrl: $nightwatchUrl,
            slackMessageTs: $messageTs,
            slackChannelId: $channelId,
        );
    }

    /**
     * Extract exception class name from text.
     */
    protected function extractExceptionClass(string $text): ?string
    {
        // Match fully qualified class names ending in Exception or Error
        if (preg_match('/([A-Z][\w\\\\]*(?:Exception|Error))/', $text, $matches)) {
            return $matches[1];
        }

        // Match SQLSTATE errors
        if (preg_match('/SQLSTATE\[(\w+)\]/', $text, $matches)) {
            return "SQLSTATE[{$matches[1]}]";
        }

        return null;
    }

    /**
     * Extract the actual error message from text.
     */
    protected function extractErrorMessage(string $text): ?string
    {
        // Pattern: Exception message after colon
        if (preg_match('/(?:Exception|Error)[:\s]+(.+?)(?:\n|$)/i', $text, $matches)) {
            return trim($matches[1]);
        }

        // SQLSTATE pattern
        if (preg_match('/SQLSTATE\[\w+\][:\s]+(.+?)(?:\n|$)/i', $text, $matches)) {
            return trim($matches[1]);
        }

        // Just get first line as message
        $lines = explode("\n", $text);
        $firstLine = trim($lines[0] ?? '');

        return ! empty($firstLine) ? $firstLine : null;
    }

    /**
     * Check if event has error-indicating attachments.
     */
    protected function hasErrorAttachments(array $event): bool
    {
        $attachments = $event['attachments'] ?? [];

        foreach ($attachments as $attachment) {
            $color = $attachment['color'] ?? '';
            $title = $attachment['title'] ?? '';

            // Red/danger color often indicates error
            if (in_array($color, ['danger', '#ff0000', '#e01e5a', 'red'])) {
                return true;
            }

            // Error keywords in title
            if (preg_match('/exception|error|failed|fatal/i', $title)) {
                return true;
            }
        }

        return false;
    }
}
