<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportXBookmarksRequest;
use App\Jobs\AnalyzeXBookmarksJob;
use App\Jobs\CreatePRFromBookmarkJob;
use App\Jobs\EnrichXBookmarksJob;
use App\Jobs\SyncXBookmarksJob;
use App\Models\XBookmark;
use App\Models\XCredential;
use App\Services\X\XService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class XBookmarkController extends Controller
{
    public function __construct(
        protected XService $xService
    ) {}

    /**
     * Display the bookmarks dashboard.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Get ALL user's X credentials (not filtered by scope)
        $allCredentials = $user->xCredentials()
            ->where('is_active', true)
            ->get();

        // Check which have bookmark scope
        $credentialsWithScope = $allCredentials->filter(fn ($c) => $this->xService->hasBookmarkScope($c));

        // Get bookmarks only from credentials WITH bookmark scope
        $query = XBookmark::whereIn('x_credential_id', $credentialsWithScope->pluck('id'))
            ->with('xCredential:id,username,name,profile_image_url')
            ->orderBy('created_at', 'desc');

        // Apply filters
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($minRelevance = $request->input('min_relevance')) {
            $query->where('relevance_score', '>=', (int) $minRelevance);
        }

        $bookmarks = $query->paginate(20)->withQueryString();

        $credentialIds = $credentialsWithScope->pluck('id');
        $stats = [
            'total' => XBookmark::whereIn('x_credential_id', $credentialIds)->count(),
            'pending' => XBookmark::whereIn('x_credential_id', $credentialIds)->pending()->count(),
            'actionable' => XBookmark::whereIn('x_credential_id', $credentialIds)->actionable()->count(),
            'with_pr' => XBookmark::whereIn('x_credential_id', $credentialIds)->withPr()->count(),
            'unenriched' => XBookmark::whereIn('x_credential_id', $credentialIds)->needsEnrichment()->count(),
        ];

        // Check sync status for credentials with scope
        $syncStatus = [];
        foreach ($credentialsWithScope as $credential) {
            $cacheKey = "x_bookmarks_synced:{$credential->id}";
            $syncStatus[$credential->id] = [
                'last_sync' => Cache::get($cacheKey),
                'can_sync' => ! Cache::has($cacheKey),
            ];
        }

        return Inertia::render('Bookmarks/Index', [
            'bookmarks' => $bookmarks,
            // Pass ALL credentials so user can see which need re-auth
            'credentials' => $allCredentials->map(fn ($c) => [
                'id' => $c->id,
                'username' => $c->username,
                'name' => $c->name,
                'profile_image_url' => $c->profile_image_url,
                'has_bookmark_scope' => $this->xService->hasBookmarkScope($c),
                'sync_status' => $syncStatus[$c->id] ?? null,
            ]),
            'stats' => $stats,
            'filters' => [
                'status' => $request->input('status'),
                'category' => $request->input('category'),
                'min_relevance' => $request->input('min_relevance'),
            ],
            'categories' => [
                XBookmark::CATEGORY_AI_MODEL => 'AI Model',
                XBookmark::CATEGORY_PROMPT_TECHNIQUE => 'Prompt Technique',
                XBookmark::CATEGORY_FEATURE_IDEA => 'Feature Idea',
                XBookmark::CATEGORY_INTEGRATION => 'Integration',
                XBookmark::CATEGORY_BUG_FIX => 'Bug Fix',
                XBookmark::CATEGORY_TOOL => 'Tool',
                XBookmark::CATEGORY_RESEARCH => 'Research',
                XBookmark::CATEGORY_OTHER => 'Other',
            ],
            'statuses' => [
                XBookmark::STATUS_PENDING => 'Pending',
                XBookmark::STATUS_ANALYZED => 'Analyzed',
                XBookmark::STATUS_ACTIONABLE => 'Actionable',
                XBookmark::STATUS_PR_CREATED => 'PR Created',
                XBookmark::STATUS_DISMISSED => 'Dismissed',
            ],
        ]);
    }

    /**
     * Trigger bookmark sync for a credential.
     */
    public function sync(Request $request, int $credentialId)
    {
        $credential = auth()->user()->xCredentials()->find($credentialId);

        if (! $credential) {
            return response()->json(['error' => 'Credential not found'], 404);
        }

        if (! $this->xService->hasBookmarkScope($credential)) {
            return response()->json([
                'error' => 'Missing bookmark.read scope. Please reconnect your X account.',
            ], 400);
        }

        $force = $request->boolean('force', false);

        SyncXBookmarksJob::dispatch($credential->id, $force);

        return response()->json(['message' => 'Bookmark sync queued']);
    }

    /**
     * Trigger AI analysis for pending bookmarks.
     */
    public function analyze(Request $request, int $credentialId)
    {
        $credential = auth()->user()->xCredentials()->find($credentialId);

        if (! $credential) {
            return response()->json(['error' => 'Credential not found'], 404);
        }

        AnalyzeXBookmarksJob::dispatch($credential->id);

        return response()->json(['message' => 'Bookmark analysis queued']);
    }

    public function enrich(Request $request, int $credentialId): JsonResponse
    {
        $credential = auth()->user()->xCredentials()->find($credentialId);

        if (! $credential) {
            return response()->json(['error' => 'Credential not found'], 404);
        }

        $unenrichedCount = XBookmark::where('x_credential_id', $credential->id)
            ->needsEnrichment()
            ->count();

        if ($unenrichedCount === 0) {
            return response()->json(['message' => 'All bookmarks are already enriched']);
        }

        EnrichXBookmarksJob::dispatch($credential->id);

        return response()->json([
            'message' => "Enrichment queued for {$unenrichedCount} bookmarks",
            'count' => $unenrichedCount,
        ]);
    }

    public function show(int $id)
    {
        $bookmark = $this->getBookmarkForUser($id);

        if (! $bookmark) {
            return response()->json(['error' => 'Bookmark not found'], 404);
        }

        return response()->json([
            'bookmark' => $bookmark->load('xCredential:id,username,name,profile_image_url'),
        ]);
    }

    /**
     * Create a PR from an actionable bookmark.
     */
    public function createPr(int $id)
    {
        $bookmark = $this->getBookmarkForUser($id);

        if (! $bookmark) {
            return response()->json(['error' => 'Bookmark not found'], 404);
        }

        if (! $bookmark->isActionable()) {
            return response()->json(['error' => 'Bookmark is not actionable'], 400);
        }

        if ($bookmark->hasPr()) {
            return response()->json([
                'error' => 'PR already exists',
                'pr_url' => $bookmark->pr_url,
            ], 400);
        }

        CreatePRFromBookmarkJob::dispatch($bookmark->id);

        return response()->json(['message' => 'PR creation queued']);
    }

    /**
     * Dismiss a bookmark (won't create PR).
     */
    public function dismiss(int $id)
    {
        $bookmark = $this->getBookmarkForUser($id);

        if (! $bookmark) {
            return response()->json(['error' => 'Bookmark not found'], 404);
        }

        $bookmark->dismiss();

        return response()->json(['message' => 'Bookmark dismissed']);
    }

    /**
     * Bulk actions on bookmarks.
     */
    public function bulk(Request $request)
    {
        $request->validate([
            'action' => 'required|in:analyze,dismiss,create_pr',
            'bookmark_ids' => 'required|array',
            'bookmark_ids.*' => 'integer',
        ]);

        $user = auth()->user();
        $credentialIds = $user->xCredentials()->pluck('id');

        $bookmarks = XBookmark::whereIn('id', $request->input('bookmark_ids'))
            ->whereIn('x_credential_id', $credentialIds)
            ->get();

        $action = $request->input('action');
        $processed = 0;

        foreach ($bookmarks as $bookmark) {
            switch ($action) {
                case 'dismiss':
                    $bookmark->dismiss();
                    $processed++;
                    break;

                case 'create_pr':
                    if ($bookmark->isActionable() && ! $bookmark->hasPr()) {
                        CreatePRFromBookmarkJob::dispatch($bookmark->id);
                        $processed++;
                    }
                    break;
            }
        }

        return response()->json([
            'message' => "Processed {$processed} bookmark(s)",
            'processed' => $processed,
        ]);
    }

    /**
     * Check bookmark scope status and provide re-auth link if needed.
     */
    public function scopeStatus()
    {
        $user = auth()->user();
        $credentials = $user->xCredentials()->where('is_active', true)->get();

        $status = $credentials->map(fn ($c) => [
            'id' => $c->id,
            'username' => $c->username,
            'has_bookmark_scope' => $this->xService->hasBookmarkScope($c),
            'scopes' => $c->scopes,
        ]);

        $needsReauth = $status->filter(fn ($s) => ! $s['has_bookmark_scope']);

        return response()->json([
            'credentials' => $status,
            'needs_reauth' => $needsReauth->isNotEmpty(),
            'reauth_url' => $needsReauth->isNotEmpty()
                ? route('x.redirect', ['include_bookmarks' => 1])
                : null,
        ]);
    }

    /**
     * Preview import to show duplicates before actually importing.
     */
    public function previewImport(Request $request): JsonResponse
    {
        $request->validate([
            'credential_id' => 'required|integer',
            'bookmarks' => 'required|array|min:1',
        ]);

        $credentialId = $request->input('credential_id');
        $credential = auth()->user()->xCredentials()->find($credentialId);

        if (! $credential) {
            return response()->json(['error' => 'Credential not found'], 404);
        }

        $bookmarks = $request->input('bookmarks');
        $tweetIds = collect($bookmarks)->map(fn ($b) => (string) ($b['tweet_id'] ?? $b['id'] ?? $b['tweetId'] ?? null))->filter();

        $existingTweetIds = XBookmark::where('x_credential_id', $credentialId)
            ->whereIn('tweet_id', $tweetIds)
            ->pluck('tweet_id')
            ->toArray();

        $newCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;
        $previewBookmarks = [];

        foreach ($bookmarks as $bookmark) {
            $tweetId = (string) ($bookmark['tweet_id'] ?? $bookmark['id'] ?? $bookmark['tweetId'] ?? null);

            if (! $tweetId) {
                $invalidCount++;

                continue;
            }

            $isDuplicate = in_array($tweetId, $existingTweetIds, true);

            if ($isDuplicate) {
                $duplicateCount++;
            } else {
                $newCount++;
            }

            $previewBookmarks[] = [
                'tweet_id' => $tweetId,
                'author_username' => $bookmark['author_username'] ?? $bookmark['authorUsername'] ?? $bookmark['username'] ?? $bookmark['screen_name'] ?? null,
                'text' => mb_substr($bookmark['text'] ?? $bookmark['full_text'] ?? $bookmark['content'] ?? '', 0, 280),
                'is_duplicate' => $isDuplicate,
            ];
        }

        return response()->json([
            'total' => count($bookmarks),
            'new_count' => $newCount,
            'duplicate_count' => $duplicateCount,
            'invalid_count' => $invalidCount,
            'bookmarks' => $previewBookmarks,
        ]);
    }

    /**
     * Import bookmarks from JSON data.
     */
    public function import(ImportXBookmarksRequest $request): JsonResponse
    {
        $credentialId = $request->input('credential_id');
        $credential = auth()->user()->xCredentials()->find($credentialId);

        if (! $credential) {
            return response()->json(['error' => 'Credential not found'], 404);
        }

        $bookmarks = $request->input('bookmarks');
        $skipDuplicates = $request->boolean('skip_duplicates', true);

        $existingTweetIds = [];
        if ($skipDuplicates) {
            $tweetIds = collect($bookmarks)->map(fn ($b) => (string) ($b['tweet_id'] ?? $b['id'] ?? $b['tweetId'] ?? null))->filter();
            $existingTweetIds = XBookmark::where('x_credential_id', $credentialId)
                ->whereIn('tweet_id', $tweetIds)
                ->pluck('tweet_id')
                ->toArray();
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($bookmarks as $data) {
            try {
                $result = $this->processBookmarkData($credential, $data, $existingTweetIds, $skipDuplicates);
                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'skipped' => $skipped++,
                };
            } catch (\Exception $e) {
                $errors++;
                Log::warning('Import bookmark failed', [
                    'error' => $e->getMessage(),
                    'data' => $data,
                ]);
            }
        }

        Log::info('X bookmarks import completed via UI', [
            'credential_id' => $credentialId,
            'total' => count($bookmarks),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return response()->json([
            'message' => 'Import completed',
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    /**
     * Process a single bookmark for import.
     *
     * @param  array<string>  $existingTweetIds
     */
    protected function processBookmarkData(
        XCredential $credential,
        array $data,
        array $existingTweetIds,
        bool $skipDuplicates
    ): string {
        $tweetId = (string) ($data['tweet_id'] ?? $data['id'] ?? $data['tweetId'] ?? null);

        if (! $tweetId) {
            return 'skipped';
        }

        $isDuplicate = in_array($tweetId, $existingTweetIds, true);

        if ($skipDuplicates && $isDuplicate) {
            return 'skipped';
        }

        $normalized = [
            'x_credential_id' => $credential->id,
            'tweet_id' => $tweetId,
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

        $existing = XBookmark::where('x_credential_id', $credential->id)
            ->where('tweet_id', $tweetId)
            ->first();

        if ($existing) {
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

    /**
     * @return array<int, array{url: string|null, title?: string|null, description?: string|null}>|null
     */
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

        if (isset($urls[0]) && is_string($urls[0])) {
            return array_map(fn ($url) => ['url' => $url], $urls);
        }

        return collect($urls)->map(fn ($u) => [
            'url' => $u['expanded_url'] ?? $u['url'] ?? null,
            'title' => $u['title'] ?? null,
            'description' => $u['description'] ?? null,
        ])->filter(fn ($u) => $u['url'])->values()->all();
    }

    protected function getBookmarkForUser(int $id): ?XBookmark
    {
        $credentialIds = auth()->user()->xCredentials()->pluck('id');

        return XBookmark::where('id', $id)
            ->whereIn('x_credential_id', $credentialIds)
            ->first();
    }
}
