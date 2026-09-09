<?php

namespace App\Jobs;

use App\Events\ActionItemsExtracted;
use App\Events\DraftTasksCreated;
use App\Models\Task;
use App\Models\Video;
use App\Services\AI\AnthropicService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeVideoContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $backoff = 30;

    public function __construct(
        public Video $video
    ) {}

    public function handle(AnthropicService $anthropic): void
    {
        // Skip if no transcript
        if (empty($this->video->transcript)) {
            Log::info('No transcript to analyze', ['video_id' => $this->video->id]);

            return;
        }

        // Skip if already analyzed
        if ($this->video->ai_processed_at) {
            Log::info('Video already analyzed', ['video_id' => $this->video->id]);

            return;
        }

        // Skip if Anthropic not configured
        if (! $anthropic->isConfigured()) {
            Log::warning('Anthropic not configured for video analysis', ['video_id' => $this->video->id]);

            return;
        }

        try {
            Log::info('Starting video content analysis', [
                'video_id' => $this->video->id,
                'transcript_length' => strlen($this->video->transcript),
            ]);

            $analysis = $this->analyzeTranscript($anthropic);

            // Update video with AI analysis
            $this->video->update([
                'ai_summary' => $analysis['summary'] ?? null,
                'ai_action_items' => $analysis['action_items'] ?? [],
                'ai_suggested_title' => $analysis['title'] ?? null,
                'ai_processed_at' => now(),
            ]);

            Log::info('Video analysis completed', [
                'video_id' => $this->video->id,
                'action_items_count' => count($analysis['action_items'] ?? []),
            ]);

            // Broadcast action items extracted
            if (! empty($analysis['action_items'])) {
                event(new ActionItemsExtracted($this->video->fresh(), $analysis['action_items']));
            }

            // Auto-create draft tasks if video has a project
            if ($this->video->project_id && ! empty($analysis['action_items'])) {
                $tasks = $this->createDraftTasks($analysis['action_items']);
                if (! empty($tasks)) {
                    event(new DraftTasksCreated($this->video->fresh(), $tasks));
                }
            }

        } catch (\Exception $e) {
            Log::error('Video analysis failed', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Analyze transcript using Claude.
     */
    protected function analyzeTranscript(AnthropicService $anthropic): array
    {
        $systemPrompt = <<<'PROMPT'
You are an expert at analyzing video transcripts for business meetings and recordings.
Your job is to:
1. Suggest a clear, descriptive title (5-10 words) that captures the main topic
2. Create a concise summary (2-3 sentences max)
3. Extract action items with owners and deadlines if mentioned

Return your analysis as JSON with this exact structure:
{
  "title": "Suggested title for the video (5-10 words)",
  "summary": "Brief summary of what was discussed",
  "action_items": [
    {
      "task": "Description of the action item",
      "owner": "Person's name or null if not specified",
      "deadline": "Date string or null if not specified",
      "priority": "high" | "medium" | "low"
    }
  ]
}

Only include action items that are clearly stated commitments or tasks, not general discussion points.
If no clear action items exist, return an empty array.
PROMPT;

        $prompt = "Analyze this video transcript and extract a summary and action items:\n\n".$this->video->transcript;

        // Truncate very long transcripts
        if (strlen($prompt) > 100000) {
            $prompt = substr($prompt, 0, 100000)."\n\n[Transcript truncated due to length]";
        }

        $response = $anthropic->message(
            prompt: $prompt,
            systemPrompt: $systemPrompt,
            model: 'claude-sonnet-4-20250514'
        );

        // Extract text content from response
        $content = collect($response['content'] ?? [])
            ->filter(fn ($block) => ($block['type'] ?? '') === 'text')
            ->pluck('text')
            ->implode('');

        // Parse JSON from response
        return $this->parseJsonResponse($content);
    }

    /**
     * Parse JSON from Claude's response.
     */
    protected function parseJsonResponse(string $content): array
    {
        // Try to extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
            $content = trim($matches[1]);
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Failed to parse AI response as JSON', [
                'video_id' => $this->video->id,
                'content' => substr($content, 0, 500),
            ]);

            return [
                'summary' => $content,
                'action_items' => [],
            ];
        }

        return $data;
    }

    /**
     * Create draft tasks from action items.
     */
    protected function createDraftTasks(array $actionItems): array
    {
        $tasks = [];

        foreach ($actionItems as $item) {
            if (empty($item['task'])) {
                continue;
            }

            // Build description with metadata
            $description = "Auto-generated from video: {$this->video->title}";
            if (! empty($item['owner'])) {
                $description .= "\n\nAssigned to: {$item['owner']}";
            }
            if (! empty($item['deadline'])) {
                $description .= "\nDeadline mentioned: {$item['deadline']}";
            }

            $task = Task::create([
                'title' => $item['task'],
                'description' => $description,
                'project_id' => $this->video->project_id,
                'status' => 'pending', // Will be reviewed and approved
                'priority' => $this->mapPriority($item['priority'] ?? 'medium'),
                'source' => 'video',
                'source_session_id' => "video:{$this->video->id}",
            ]);

            $tasks[] = $task;

            Log::info('Draft task created from video', [
                'task_id' => $task->id,
                'video_id' => $this->video->id,
            ]);
        }

        return $tasks;
    }

    /**
     * Map priority string to task priority.
     */
    protected function mapPriority(string $priority): string
    {
        return match (strtolower($priority)) {
            'high', 'urgent' => 'high',
            'low' => 'low',
            default => 'medium',
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AnalyzeVideoContentJob failed', [
            'video_id' => $this->video->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
