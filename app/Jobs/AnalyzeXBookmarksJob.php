<?php

namespace App\Jobs;

use App\Models\XBookmark;
use App\Models\XCredential;
use App\Services\AI\ClaudeCliService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Analyze X bookmarks using AI to identify actionable items.
 *
 * This job processes pending bookmarks through Claude to:
 * - Categorize content (AI model, prompt technique, feature idea, etc.)
 * - Score relevance to Zao Dashboard project
 * - Extract action summaries for PR creation
 */
class AnalyzeXBookmarksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300; // 5 minutes

    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $credentialId,
        public int $batchSize = 10
    ) {}

    public function middleware(): array
    {
        return [
            new RateLimited('anthropic-agents'),
        ];
    }

    public function handle(): void
    {
        $credential = XCredential::find($this->credentialId);

        if (! $credential) {
            Log::warning('AnalyzeXBookmarks: credential not found', ['id' => $this->credentialId]);

            return;
        }

        // Get pending bookmarks
        $bookmarks = XBookmark::where('x_credential_id', $this->credentialId)
            ->pending()
            ->orderBy('created_at', 'desc')
            ->limit($this->batchSize)
            ->get();

        if ($bookmarks->isEmpty()) {
            Log::info('AnalyzeXBookmarks: no pending bookmarks', ['credential_id' => $this->credentialId]);

            return;
        }

        Log::info('Analyzing X bookmarks', [
            'credential_id' => $this->credentialId,
            'count' => $bookmarks->count(),
        ]);

        foreach ($bookmarks as $bookmark) {
            try {
                $analysis = $this->analyzeBookmark($bookmark);
                $bookmark->markAsAnalyzed($analysis);

                Log::info('Bookmark analyzed', [
                    'bookmark_id' => $bookmark->id,
                    'category' => $analysis['category'],
                    'relevance' => $analysis['relevance_score'],
                    'actionable' => $analysis['is_actionable'],
                ]);

            } catch (\Exception $e) {
                Log::error('Bookmark analysis failed', [
                    'bookmark_id' => $bookmark->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Check if more pending bookmarks exist
        $remaining = XBookmark::where('x_credential_id', $this->credentialId)
            ->pending()
            ->count();

        if ($remaining > 0) {
            // Dispatch another batch with delay
            self::dispatch($this->credentialId, $this->batchSize)
                ->delay(now()->addSeconds(30));
        }
    }

    protected function analyzeBookmark(XBookmark $bookmark): array
    {
        $cli = new ClaudeCliService;
        $prompt = $this->buildAnalysisPrompt($bookmark);

        $systemPrompt = 'You are a bookmark analyzer. Categorize and score bookmarks for relevance to a Laravel/Vue.js dashboard project.';

        $parsed = $cli->messageJson($prompt, $systemPrompt, 'sonnet', 60);

        if (! $parsed) {
            return [
                'category' => XBookmark::CATEGORY_OTHER,
                'relevance_score' => 0,
                'is_actionable' => false,
                'action_summary' => null,
                'reasoning' => 'Failed to parse AI response',
            ];
        }

        return [
            'category' => $parsed['category'] ?? XBookmark::CATEGORY_OTHER,
            'relevance_score' => (int) ($parsed['relevance_score'] ?? 0),
            'is_actionable' => (bool) ($parsed['is_actionable'] ?? false),
            'action_summary' => $parsed['action_summary'] ?? null,
            'reasoning' => $parsed['reasoning'] ?? null,
        ];
    }

    protected function buildAnalysisPrompt(XBookmark $bookmark): string
    {
        $fullText = $bookmark->getFullText();
        $hashtags = collect($bookmark->hashtags ?? [])->pluck('tag')->filter()->implode(', ');

        $urlSection = $this->formatUrlSection($bookmark);
        $videoSection = $this->formatVideoSection($bookmark);

        return <<<PROMPT
Analyze this X (Twitter) bookmark to determine if it's relevant to a Laravel/Vue.js business dashboard project called "Zao Dashboard". The project includes:
- AI agent system for task automation
- Integrations: QuickBooks, Harvest, Slack, GitHub, Google Calendar
- Client/project/task management
- Invoice and time tracking

BOOKMARK CONTENT:
Tweet by @{$bookmark->author_username} ({$bookmark->author_name}):
"{$fullText}"

{$urlSection}
{$videoSection}
Hashtags: {$hashtags}
Engagement: {$bookmark->like_count} likes, {$bookmark->retweet_count} RTs

Respond with a JSON object (no markdown code blocks):
{
    "category": "ai_model|prompt_technique|feature_idea|integration|bug_fix|tool|research|other",
    "relevance_score": 0-100,
    "is_actionable": true|false,
    "action_summary": "Brief description of what action to take, or null if not actionable",
    "reasoning": "Why this is or isn't relevant/actionable"
}

Categories:
- ai_model: New AI models, APIs, or capabilities (Claude, GPT, etc.)
- prompt_technique: Prompting strategies, system prompts, agent patterns
- feature_idea: Feature that could be added to Zao Dashboard
- integration: New service integration opportunity
- bug_fix: Bug report or fix that might apply
- tool: Development tool, library, or utility
- research: Interesting research or article worth reading
- other: Doesn't fit other categories

Be selective - only mark as actionable if it would genuinely improve the project.
PROMPT;
    }

    protected function formatVideoSection(XBookmark $bookmark): string
    {
        $transcript = $bookmark->getVideoTranscript();

        if (! $transcript) {
            return '';
        }

        return "Video Transcript:\n\"{$transcript}\"";
    }

    protected function formatUrlSection(XBookmark $bookmark): string
    {
        $urlSummaries = $bookmark->getUrlSummaries();

        if (empty($urlSummaries)) {
            $urls = collect($bookmark->urls ?? [])->pluck('url')->filter()->implode("\n");

            return "URLs: {$urls}";
        }

        $formatted = collect($urlSummaries)->map(function ($url) {
            $parts = [$url['url']];
            if (! empty($url['title'])) {
                $parts[] = "  Title: {$url['title']}";
            }
            if (! empty($url['summary'])) {
                $parts[] = "  Summary: {$url['summary']}";
            }

            return implode("\n", $parts);
        })->implode("\n\n");

        return "Referenced URLs with context:\n{$formatted}";
    }

    protected function parseAnalysisResponse(string $content): array
    {
        // Clean up response - remove markdown code blocks if present
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Failed to parse analysis JSON', ['content' => $content]);

            return [
                'category' => XBookmark::CATEGORY_OTHER,
                'relevance_score' => 0,
                'is_actionable' => false,
                'action_summary' => null,
                'reasoning' => 'Failed to parse AI response',
                'raw_response' => $content,
            ];
        }

        return [
            'category' => $data['category'] ?? XBookmark::CATEGORY_OTHER,
            'relevance_score' => (int) ($data['relevance_score'] ?? 0),
            'is_actionable' => (bool) ($data['is_actionable'] ?? false),
            'action_summary' => $data['action_summary'] ?? null,
            'reasoning' => $data['reasoning'] ?? null,
        ];
    }
}
