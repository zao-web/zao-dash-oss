<?php

namespace App\Agents\Tools;

use App\Models\SocialPost;
use App\Models\User;
use App\Services\X\XService;
use Illuminate\Support\Facades\Cache;

/**
 * Post content to X (Twitter).
 *
 * COMPLIANCE NOTES (X Developer Agreement):
 * - All posts require human approval (requiresApproval = true)
 * - Rate limit: Stay under 10 posts/day personal, 25 posts/day company
 * - No automated @mentioning of users we don't know
 * - No duplicate/near-duplicate content across accounts
 * - No coordinated posting patterns that look like manipulation
 *
 * @see docs/SOCIAL_MEDIA_COMPLIANCE.md for full policy
 */
class PostToXTool extends BaseTool
{
    /**
     * Daily posting limits per account type to stay safe.
     * X doesn't publish exact limits, but these are conservative.
     */
    protected const DAILY_LIMITS = [
        'personal' => 10,  // High-signal thought leadership only
        'company' => 25,   // Promotional/educational content
    ];

    protected XService $xService;

    public function __construct(XService $xService)
    {
        $this->xService = $xService;
    }

    public function category(): string
    {
        return 'social';
    }

    public function name(): string
    {
        return 'Post to X';
    }

    public function description(): string
    {
        return 'Post to X. MUST NOT sound like AI: no em dashes, no "delve/leverage/unlock", write like a real person. Include CTA for replies/DMs. Requires approval.';
    }

    public function requiresApproval(): bool
    {
        return true; // External communication needs human approval
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'The text content of the tweet. Max 280 characters for single tweet. Should include a call-to-action encouraging replies, comments, or DMs since we cannot initiate DMs via API.',
                ],
                'account' => [
                    'type' => 'string',
                    'enum' => ['personal', 'company'],
                    'description' => 'Which account to post from: "personal" (@JS_Zao) for high-signal thought leadership only, "company" (@zaowebdev) for promotional/educational content. Default: company.',
                    'default' => 'company',
                ],
                'is_thread' => [
                    'type' => 'boolean',
                    'description' => 'If true, content will be split into a thread.',
                    'default' => false,
                ],
                'reply_to_id' => [
                    'type' => 'string',
                    'description' => 'Tweet ID to reply to (optional).',
                ],
            ],
            'required' => ['content'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'content' => 'required|string',
            'account' => 'sometimes|string|in:personal,company',
            'is_thread' => 'sometimes|boolean',
            'reply_to_id' => 'sometimes|string',
        ];
    }

    public function execute(array $params): array
    {
        $accountType = $params['account'] ?? 'company';

        // Check daily posting limits for compliance
        $dailyLimit = self::DAILY_LIMITS[$accountType] ?? 10;
        $todayCount = $this->getTodayPostCount($accountType);

        if ($todayCount >= $dailyLimit) {
            return [
                'success' => false,
                'error' => "Daily posting limit reached for {$accountType} account ({$dailyLimit}/day). Try again tomorrow.",
                'compliance_note' => 'Rate limits protect against account suspension.',
            ];
        }

        // personal = @JS_Zao (high signal), company = @zaowebdev (promotional)
        $username = $accountType === 'personal' ? 'JS_Zao' : 'zaowebdev';

        $user = User::whereHas('xCredential', function ($q) use ($username) {
            $q->where('username', $username);
        })->first();

        // Fall back to any connected account if specific not found
        if (! $user) {
            $user = User::whereHas('xCredential')->first();
        }

        if (! $user || ! $user->xCredential) {
            return [
                'success' => false,
                'error' => "No X account connected for @{$username}.",
            ];
        }

        $credential = $user->xCredential;

        try {
            $isThread = $params['is_thread'] ?? false;
            $content = $params['content'];

            if ($isThread && strlen($content) > 280) {
                // Post as thread
                $tweets = $this->splitIntoThread($content);
                $result = $this->xService->postThread($credential, $tweets);

                return [
                    'success' => true,
                    'platform' => 'x',
                    'is_thread' => true,
                    'tweet_count' => count($tweets),
                    'first_tweet_id' => $result[0]['data']['id'] ?? null,
                    'posted_as' => '@'.$credential->username,
                ];
            } else {
                // Single tweet
                $result = $this->xService->tweet(
                    $credential,
                    $content,
                    $params['reply_to_id'] ?? null
                );

                return [
                    'success' => true,
                    'platform' => 'x',
                    'is_thread' => false,
                    'tweet_id' => $result['data']['id'] ?? null,
                    'posted_as' => '@'.$credential->username,
                    'content_preview' => substr($content, 0, 100).(strlen($content) > 100 ? '...' : ''),
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Split long content into thread-sized chunks.
     */
    protected function splitIntoThread(string $content): array
    {
        $tweets = [];
        $paragraphs = preg_split('/\n\n+/', $content);
        $current = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);

            if (strlen($current."\n\n".$para) <= 270) {
                $current = $current ? $current."\n\n".$para : $para;
            } else {
                if ($current) {
                    $tweets[] = trim($current);
                }

                // If paragraph itself is too long, split by sentences
                if (strlen($para) > 270) {
                    $sentences = preg_split('/(?<=[.!?])\s+/', $para);
                    $current = '';
                    foreach ($sentences as $sentence) {
                        if (strlen($current.' '.$sentence) <= 270) {
                            $current = $current ? $current.' '.$sentence : $sentence;
                        } else {
                            if ($current) {
                                $tweets[] = trim($current);
                            }
                            $current = $sentence;
                        }
                    }
                } else {
                    $current = $para;
                }
            }
        }

        if ($current) {
            $tweets[] = trim($current);
        }

        // Add thread indicators
        $count = count($tweets);
        if ($count > 1) {
            foreach ($tweets as $i => &$tweet) {
                $indicator = ($i + 1).'/'.$count;
                if (strlen($tweet) + strlen($indicator) + 1 <= 280) {
                    $tweet .= ' '.$indicator;
                }
            }
        }

        return $tweets;
    }

    /**
     * Get count of posts made today for rate limiting.
     */
    protected function getTodayPostCount(string $accountType): int
    {
        $cacheKey = "x_posts_today_{$accountType}_".now()->format('Y-m-d');

        // Check cache first for performance
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Count from database
        $count = SocialPost::where('platform', 'x')
            ->whereDate('published_at', today())
            ->where('status', 'published')
            ->when($accountType === 'personal', fn ($q) => $q->whereJsonContains('metadata->account', 'personal'))
            ->when($accountType === 'company', fn ($q) => $q->where(function ($q) {
                $q->whereJsonContains('metadata->account', 'company')
                    ->orWhereNull('metadata->account');
            }))
            ->count();

        // Cache for 5 minutes
        Cache::put($cacheKey, $count, 300);

        return $count;
    }
}
