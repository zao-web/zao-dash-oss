<?php

namespace App\Jobs;

use App\Models\XBookmark;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Create a PR from an actionable X bookmark using Claude Code.
 *
 * This job:
 * 1. Creates a feature branch
 * 2. Runs Claude Code with the action summary as context
 * 3. Creates a PR with a link back to the original tweet
 */
class CreatePRFromBookmarkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Only try once - PR creation is complex

    public int $timeout = 1800; // 30 minutes for implementation

    public function __construct(
        public int $bookmarkId
    ) {}

    public function middleware(): array
    {
        // Only one PR creation at a time
        return [
            (new WithoutOverlapping('create-pr-from-bookmark'))
                ->releaseAfter(300)
                ->expireAfter(1800),
        ];
    }

    public function handle(): void
    {
        $bookmark = XBookmark::with('xCredential')->find($this->bookmarkId);

        if (! $bookmark) {
            Log::warning('CreatePRFromBookmark: bookmark not found', ['id' => $this->bookmarkId]);

            return;
        }

        if (! $bookmark->isActionable()) {
            Log::info('CreatePRFromBookmark: bookmark not actionable', ['id' => $this->bookmarkId]);

            return;
        }

        if ($bookmark->hasPr()) {
            Log::info('CreatePRFromBookmark: PR already exists', [
                'id' => $this->bookmarkId,
                'pr_url' => $bookmark->pr_url,
            ]);

            return;
        }

        Log::info('Creating PR from bookmark', [
            'bookmark_id' => $bookmark->id,
            'category' => $bookmark->category,
            'action' => $bookmark->action_summary,
        ]);

        try {
            $branchName = $this->createBranchName($bookmark);
            $prompt = $this->buildClaudePrompt($bookmark);

            // Create branch
            $this->runGitCommand("git checkout -b {$branchName}");

            // Run Claude Code to implement the change
            $result = $this->runClaudeCode($prompt);

            if (! $result['success']) {
                throw new \Exception('Claude Code execution failed: '.($result['error'] ?? 'Unknown error'));
            }

            // Create PR
            $prUrl = $this->createPullRequest($bookmark, $branchName);

            // Update bookmark
            $bookmark->markPrCreated($branchName, $prUrl);

            // Return to main branch
            $this->runGitCommand('git checkout main');

            Log::info('PR created from bookmark', [
                'bookmark_id' => $bookmark->id,
                'branch' => $branchName,
                'pr_url' => $prUrl,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create PR from bookmark', [
                'bookmark_id' => $bookmark->id,
                'error' => $e->getMessage(),
            ]);

            // Try to return to main branch
            try {
                $this->runGitCommand('git checkout main');
            } catch (\Exception $ignored) {
            }

            throw $e;
        }
    }

    protected function createBranchName(XBookmark $bookmark): string
    {
        $category = $bookmark->category ?? 'feature';
        $summary = Str::slug(Str::limit($bookmark->action_summary ?? 'bookmark-implementation', 40, ''));
        $id = Str::random(6);

        return "bookmark/{$category}/{$summary}-{$id}";
    }

    protected function buildClaudePrompt(XBookmark $bookmark): string
    {
        $tweetUrl = $bookmark->getTweetUrl();
        $analysis = $bookmark->ai_analysis ?? [];
        $reasoning = $analysis['reasoning'] ?? 'No reasoning provided';

        return <<<PROMPT
I found an interesting idea from an X bookmark that I'd like you to implement in the Zao Dashboard project.

## Source Tweet
- URL: {$tweetUrl}
- Author: @{$bookmark->author_username}
- Content: "{$bookmark->text}"

## AI Analysis
- Category: {$bookmark->category}
- Relevance Score: {$bookmark->relevance_score}/100
- Action Summary: {$bookmark->action_summary}
- Reasoning: {$reasoning}

## Your Task
Implement this idea in the Zao Dashboard codebase. This is a Laravel 11 + Vue 3 + Inertia.js project.

Guidelines:
1. Keep the implementation focused and minimal - don't over-engineer
2. Follow existing patterns in the codebase
3. Add tests if the change is significant
4. Update relevant documentation in /docs if needed

After implementation, stage and commit your changes with a descriptive message that references the source tweet.

Do NOT push - I will review and push after your implementation is complete.
PROMPT;
    }

    protected function runClaudeCode(string $prompt): array
    {
        $projectPath = base_path();

        // Use --dangerously-skip-permissions for non-interactive execution
        // Pipe the prompt via stdin
        $result = Process::timeout(1500)
            ->path($projectPath)
            ->input($prompt)
            ->run('claude --dangerously-skip-permissions -p -');

        return [
            'success' => $result->successful(),
            'output' => $result->output(),
            'error' => $result->errorOutput(),
        ];
    }

    protected function createPullRequest(XBookmark $bookmark, string $branchName): string
    {
        $tweetUrl = $bookmark->getTweetUrl();
        $category = ucfirst(str_replace('_', ' ', $bookmark->category ?? 'feature'));

        $title = "[Bookmark] {$category}: ".Str::limit($bookmark->action_summary ?? 'Implementation from X bookmark', 60);

        $body = <<<BODY
## Source

This PR was automatically generated from an X bookmark.

**Tweet:** {$tweetUrl}
**Author:** @{$bookmark->author_username}

> {$bookmark->text}

## AI Analysis

- **Category:** {$bookmark->category}
- **Relevance Score:** {$bookmark->relevance_score}/100
- **Action:** {$bookmark->action_summary}

## Changes

_Implemented by Claude Code based on the bookmark content._

## Test Plan

- [ ] Review changes for correctness
- [ ] Run tests: `php artisan test`
- [ ] Manual testing if applicable
BODY;

        // Push branch first
        $this->runGitCommand("git push -u origin {$branchName}");

        // Create PR using gh CLI
        $bodyFile = storage_path('app/temp/pr-body-'.Str::random(8).'.md');
        file_put_contents($bodyFile, $body);

        try {
            $result = Process::timeout(60)
                ->path(base_path())
                ->run("gh pr create --title \"{$title}\" --body-file \"{$bodyFile}\"");

            if (! $result->successful()) {
                throw new \Exception('Failed to create PR: '.$result->errorOutput());
            }

            // Extract PR URL from output
            $output = trim($result->output());
            if (preg_match('/https:\/\/github\.com\/[^\s]+\/pull\/\d+/', $output, $matches)) {
                return $matches[0];
            }

            return $output; // Fallback to full output
        } finally {
            @unlink($bodyFile);
        }
    }

    protected function runGitCommand(string $command): string
    {
        $result = Process::timeout(60)
            ->path(base_path())
            ->run($command);

        if (! $result->successful()) {
            throw new \Exception("Git command failed: {$command}\n".$result->errorOutput());
        }

        return $result->output();
    }
}
