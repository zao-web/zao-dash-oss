<?php

namespace App\Console\Commands;

use App\Models\XBookmark;
use App\Models\XCredential;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportXBookmarksCommand extends Command
{
    protected $signature = 'x:import-bookmarks 
                            {file : Path to JSON file containing bookmarks}
                            {--credential= : X credential ID to associate bookmarks with}
                            {--username= : X username to find credential (alternative to --credential)}
                            {--dry-run : Show what would be imported without saving}';

    protected $description = 'Import X bookmarks from a JSON file (e.g., scraped via browser)';

    public function handle(): int
    {
        $filePath = $this->argument('file');

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return 1;
        }

        $json = file_get_contents($filePath);
        $bookmarks = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON: '.json_last_error_msg());

            return 1;
        }

        if (! is_array($bookmarks)) {
            $this->error('JSON must be an array of bookmarks');

            return 1;
        }

        // Find credential
        $credential = $this->findCredential();
        if (! $credential) {
            return 1;
        }

        $this->info("Importing bookmarks for @{$credential->username} (credential #{$credential->id})");
        $this->info('Found '.count($bookmarks).' bookmarks in file');

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN - no changes will be saved');
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar(count($bookmarks));
        $progressBar->start();

        foreach ($bookmarks as $index => $data) {
            try {
                $result = $this->processBookmark($credential, $data, $index);
                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'skipped' => $skipped++,
                };
            } catch (\Exception $e) {
                $errors++;
                Log::warning('Import bookmark failed', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                    'data' => $data,
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Import complete:');
        $this->line("  Created: {$created}");
        $this->line("  Updated: {$updated}");
        $this->line("  Skipped: {$skipped}");
        if ($errors > 0) {
            $this->warn("  Errors: {$errors}");
        }

        Log::info('X bookmarks import completed', [
            'credential_id' => $credential->id,
            'file' => $filePath,
            'total' => count($bookmarks),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return 0;
    }

    protected function findCredential(): ?XCredential
    {
        if ($id = $this->option('credential')) {
            $credential = XCredential::find($id);
            if (! $credential) {
                $this->error("Credential not found: {$id}");

                return null;
            }

            return $credential;
        }

        if ($username = $this->option('username')) {
            $credential = XCredential::where('username', $username)->first();
            if (! $credential) {
                $this->error("No credential found for username: {$username}");

                return null;
            }

            return $credential;
        }

        // Try to find the only active credential
        $credentials = XCredential::where('is_active', true)->get();

        if ($credentials->isEmpty()) {
            $this->error('No active X credentials found. Please connect an X account first.');

            return null;
        }

        if ($credentials->count() > 1) {
            $this->error('Multiple active credentials found. Please specify --credential=ID or --username=handle');
            $this->table(
                ['ID', 'Username', 'Last Synced'],
                $credentials->map(fn ($c) => [$c->id, $c->username, $c->last_synced_at?->diffForHumans() ?? 'never'])
            );

            return null;
        }

        return $credentials->first();
    }

    protected function processBookmark(XCredential $credential, array $data, int $index): string
    {
        // Support multiple field name formats from different scrapers
        $tweetId = $data['tweet_id'] ?? $data['id'] ?? $data['tweetId'] ?? null;

        if (! $tweetId) {
            Log::debug('Skipping bookmark without tweet_id', ['index' => $index, 'keys' => array_keys($data)]);

            return 'skipped';
        }

        // Normalize the data
        $normalized = [
            'x_credential_id' => $credential->id,
            'tweet_id' => (string) $tweetId,
            'author_id' => $data['author_id'] ?? $data['authorId'] ?? $data['user_id'] ?? '',
            'author_username' => $data['author_username'] ?? $data['authorUsername'] ?? $data['username'] ?? $data['screen_name'] ?? null,
            'author_name' => $data['author_name'] ?? $data['authorName'] ?? $data['name'] ?? null,
            'text' => $data['text'] ?? $data['full_text'] ?? $data['content'] ?? '',
            'tweet_created_at' => $this->parseDate($data['created_at'] ?? $data['createdAt'] ?? $data['tweet_created_at'] ?? null),
            'urls' => $this->normalizeUrls($data['urls'] ?? $data['entities']['urls'] ?? null),
            'mentions' => $data['mentions'] ?? $data['entities']['mentions'] ?? null,
            'hashtags' => $data['hashtags'] ?? $data['entities']['hashtags'] ?? null,
            'like_count' => (int) ($data['like_count'] ?? $data['likeCount'] ?? $data['favorite_count'] ?? 0),
            'retweet_count' => (int) ($data['retweet_count'] ?? $data['retweetCount'] ?? 0),
            'reply_count' => (int) ($data['reply_count'] ?? $data['replyCount'] ?? 0),
            'quote_count' => (int) ($data['quote_count'] ?? $data['quoteCount'] ?? 0),
        ];

        if ($this->option('dry-run')) {
            Log::debug('Would import bookmark', $normalized);

            return 'created';
        }

        $existing = XBookmark::where('x_credential_id', $credential->id)
            ->where('tweet_id', $normalized['tweet_id'])
            ->first();

        if ($existing) {
            // Update metrics only
            $existing->update([
                'like_count' => $normalized['like_count'],
                'retweet_count' => $normalized['retweet_count'],
                'reply_count' => $normalized['reply_count'],
                'quote_count' => $normalized['quote_count'],
            ]);

            return 'updated';
        }

        XBookmark::create($normalized);

        return 'created';
    }

    protected function parseDate(?string $date): ?Carbon
    {
        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception) {
            return null;
        }
    }

    protected function normalizeUrls(mixed $urls): ?array
    {
        if (! $urls) {
            return null;
        }

        if (is_string($urls)) {
            return [['url' => $urls]];
        }

        if (! is_array($urls)) {
            return null;
        }

        // Handle array of strings
        if (isset($urls[0]) && is_string($urls[0])) {
            return array_map(fn ($url) => ['url' => $url], $urls);
        }

        // Handle array of objects with expanded_url
        return collect($urls)->map(fn ($u) => [
            'url' => $u['expanded_url'] ?? $u['url'] ?? null,
            'title' => $u['title'] ?? null,
            'description' => $u['description'] ?? null,
        ])->filter(fn ($u) => $u['url'])->values()->all();
    }
}
