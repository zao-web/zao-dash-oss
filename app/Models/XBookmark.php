<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XBookmark extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ANALYZED = 'analyzed';

    public const STATUS_ACTIONABLE = 'actionable';

    public const STATUS_PR_CREATED = 'pr_created';

    public const STATUS_DISMISSED = 'dismissed';

    public const CATEGORY_AI_MODEL = 'ai_model';

    public const CATEGORY_PROMPT_TECHNIQUE = 'prompt_technique';

    public const CATEGORY_FEATURE_IDEA = 'feature_idea';

    public const CATEGORY_INTEGRATION = 'integration';

    public const CATEGORY_BUG_FIX = 'bug_fix';

    public const CATEGORY_TOOL = 'tool';

    public const CATEGORY_RESEARCH = 'research';

    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'x_credential_id',
        'tweet_id',
        'author_id',
        'author_username',
        'author_name',
        'text',
        'tweet_created_at',
        'urls',
        'mentions',
        'hashtags',
        'media',
        'enriched_content',
        'enriched_at',
        'like_count',
        'retweet_count',
        'reply_count',
        'quote_count',
        'status',
        'ai_analysis',
        'category',
        'relevance_score',
        'action_summary',
        'pr_branch',
        'pr_url',
        'analyzed_at',
        'pr_created_at',
        'dismissed_at',
    ];

    protected $casts = [
        'tweet_created_at' => 'datetime',
        'analyzed_at' => 'datetime',
        'enriched_at' => 'datetime',
        'pr_created_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'urls' => 'array',
        'mentions' => 'array',
        'hashtags' => 'array',
        'media' => 'array',
        'enriched_content' => 'array',
        'ai_analysis' => 'array',
        'like_count' => 'integer',
        'retweet_count' => 'integer',
        'reply_count' => 'integer',
        'quote_count' => 'integer',
        'relevance_score' => 'integer',
    ];

    public function xCredential(): BelongsTo
    {
        return $this->belongsTo(XCredential::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeAnalyzed($query)
    {
        return $query->where('status', self::STATUS_ANALYZED);
    }

    public function scopeActionable($query)
    {
        return $query->where('status', self::STATUS_ACTIONABLE);
    }

    public function scopeWithPr($query)
    {
        return $query->where('status', self::STATUS_PR_CREATED);
    }

    public function scopeNotDismissed($query)
    {
        return $query->where('status', '!=', self::STATUS_DISMISSED);
    }

    public function scopeHighRelevance($query, int $minScore = 70)
    {
        return $query->where('relevance_score', '>=', $minScore);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActionable(): bool
    {
        return $this->status === self::STATUS_ACTIONABLE;
    }

    public function hasPr(): bool
    {
        return $this->status === self::STATUS_PR_CREATED && ! empty($this->pr_url);
    }

    public function getTweetUrl(): string
    {
        return "https://x.com/{$this->author_username}/status/{$this->tweet_id}";
    }

    public function markAsAnalyzed(array $analysis): void
    {
        $this->update([
            'status' => $analysis['is_actionable'] ? self::STATUS_ACTIONABLE : self::STATUS_ANALYZED,
            'ai_analysis' => $analysis,
            'category' => $analysis['category'] ?? null,
            'relevance_score' => $analysis['relevance_score'] ?? null,
            'action_summary' => $analysis['action_summary'] ?? null,
            'analyzed_at' => now(),
        ]);
    }

    public function markPrCreated(string $branch, string $prUrl): void
    {
        $this->update([
            'status' => self::STATUS_PR_CREATED,
            'pr_branch' => $branch,
            'pr_url' => $prUrl,
            'pr_created_at' => now(),
        ]);
    }

    public function dismiss(): void
    {
        $this->update([
            'status' => self::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ]);
    }

    public function isEnriched(): bool
    {
        return $this->enriched_at !== null;
    }

    public function needsEnrichment(): bool
    {
        return $this->enriched_at === null && $this->isPending();
    }

    public function scopeNeedsEnrichment($query)
    {
        return $query->whereNull('enriched_at')->pending();
    }

    public function markAsEnriched(array $enrichedContent): void
    {
        $this->update([
            'enriched_content' => $enrichedContent,
            'enriched_at' => now(),
        ]);
    }

    /**
     * Get the best available text for analysis.
     * Prefers enriched full_text over stored truncated text.
     */
    public function getFullText(): string
    {
        return $this->enriched_content['full_text'] ?? $this->text ?? '';
    }

    /**
     * @return array<array{url: string, title: string|null, summary: string|null}>
     */
    public function getUrlSummaries(): array
    {
        return $this->enriched_content['url_summaries'] ?? [];
    }

    public function getVideoTranscript(): ?string
    {
        return $this->enriched_content['video_transcript']['text'] ?? null;
    }

    public function hasVideoTranscript(): bool
    {
        return ! empty($this->enriched_content['video_transcript']['text']);
    }
}
