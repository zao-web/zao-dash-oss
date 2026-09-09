<?php

namespace App\Services\Slack;

use App\Models\SlackMessage;
use App\Models\Task;

class SlackActionItemService
{
    /**
     * Action item trigger phrases.
     */
    protected array $actionPhrases = [
        'can you',
        'could you',
        'please',
        'need to',
        'needs to',
        'should',
        'will you',
        'would you',
        'todo:',
        'action:',
        'task:',
        'follow up',
        'let\'s',
        'we need',
        'i need',
        'make sure',
        'don\'t forget',
        'reminder:',
        'asap',
        'urgent',
        'by eod',
        'by end of day',
        'by friday',
        'by monday',
        'deadline',
    ];

    /**
     * Analyze a Slack message for potential action items.
     */
    public function analyzeMessage(SlackMessage $message): array
    {
        $text = strtolower($message->text ?? '');
        $actions = [];

        // Skip bot messages and very short messages
        if ($message->is_bot || strlen($text) < 10) {
            return $actions;
        }

        // Check for action phrases
        $hasActionPhrase = false;
        foreach ($this->actionPhrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $hasActionPhrase = true;
                break;
            }
        }

        if (! $hasActionPhrase) {
            return $actions;
        }

        // Score the message
        $score = $this->calculateActionScore($message);

        if ($score >= 0.5) {
            $actions[] = [
                'message_id' => $message->id,
                'text' => $message->text,
                'score' => $score,
                'channel' => $message->channel?->name,
                'author' => $message->user_name,
                'timestamp' => $message->slack_ts,
                'suggested_task' => $this->suggestTaskTitle($message->text),
                'urgency' => $this->detectUrgency($text),
                'mentioned_users' => $this->extractMentions($message->text),
            ];
        }

        return $actions;
    }

    /**
     * Calculate a score for how likely this is an action item.
     */
    protected function calculateActionScore(SlackMessage $message): float
    {
        $text = strtolower($message->text ?? '');
        $score = 0.0;

        // Direct request phrases
        if (preg_match('/can you|could you|will you|would you/i', $text)) {
            $score += 0.3;
        }

        // Task markers
        if (preg_match('/todo:|action:|task:|reminder:/i', $text)) {
            $score += 0.5;
        }

        // Urgency indicators
        if (preg_match('/asap|urgent|deadline|eod|end of day/i', $text)) {
            $score += 0.2;
        }

        // Contains a question
        if (str_contains($text, '?')) {
            $score += 0.1;
        }

        // Contains user mentions
        if (preg_match('/<@[A-Z0-9]+>/', $message->text ?? '')) {
            $score += 0.2;
        }

        // Contains dates
        if (preg_match('/monday|tuesday|wednesday|thursday|friday|tomorrow|next week/i', $text)) {
            $score += 0.15;
        }

        return min(1.0, $score);
    }

    /**
     * Suggest a task title from the message.
     */
    protected function suggestTaskTitle(string $text): string
    {
        // Remove user mentions and emoji
        $clean = preg_replace('/<@[A-Z0-9]+>|:[a-z_]+:/i', '', $text);

        // Take first sentence or first 100 chars
        $firstSentence = preg_split('/[.!?]/', $clean, 2)[0];
        $title = trim($firstSentence);

        if (strlen($title) > 100) {
            $title = substr($title, 0, 97).'...';
        }

        return $title;
    }

    /**
     * Detect urgency level.
     */
    protected function detectUrgency(string $text): string
    {
        if (preg_match('/asap|urgent|immediately|critical/i', $text)) {
            return 'high';
        }

        if (preg_match('/eod|end of day|today|by tonight/i', $text)) {
            return 'medium';
        }

        if (preg_match('/this week|by friday/i', $text)) {
            return 'normal';
        }

        return 'low';
    }

    /**
     * Extract user mentions.
     */
    protected function extractMentions(string $text): array
    {
        preg_match_all('/<@([A-Z0-9]+)>/', $text, $matches);

        return $matches[1] ?? [];
    }

    /**
     * Process all unprocessed messages from the past N hours.
     */
    public function processRecentMessages(int $hours = 24): array
    {
        $messages = SlackMessage::where('created_at', '>=', now()->subHours($hours))
            ->whereNull('action_processed_at')
            ->with('channel')
            ->get();

        $actions = [];

        foreach ($messages as $message) {
            $found = $this->analyzeMessage($message);
            if (! empty($found)) {
                $actions = array_merge($actions, $found);
            }
            $message->update(['action_processed_at' => now()]);
        }

        return $actions;
    }

    /**
     * Create a task from an action item.
     */
    public function createTaskFromAction(array $action, ?int $projectId = null, ?int $assigneeId = null): Task
    {
        $priority = match ($action['urgency']) {
            'high' => 1,
            'medium' => 2,
            'normal' => 3,
            default => 4,
        };

        return Task::create([
            'title' => $action['suggested_task'],
            'description' => "From Slack ({$action['channel']}):\n\n{$action['text']}\n\n— {$action['author']}",
            'project_id' => $projectId,
            'assignee_id' => $assigneeId,
            'priority' => $priority,
            'status' => 'pending',
            'source' => 'slack',
            'source_reference' => $action['message_id'],
        ]);
    }

    /**
     * Get action suggestions for the dashboard.
     */
    public function getSuggestions(int $limit = 10): array
    {
        return collect($this->processRecentMessages(48))
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->toArray();
    }
}
