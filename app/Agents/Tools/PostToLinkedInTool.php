<?php

namespace App\Agents\Tools;

use App\Models\User;
use App\Services\LinkedIn\LinkedInService;

/**
 * Post content to LinkedIn (personal or company page).
 */
class PostToLinkedInTool extends BaseTool
{
    protected LinkedInService $linkedin;

    public function __construct(LinkedInService $linkedin)
    {
        $this->linkedin = $linkedin;
    }

    public function category(): string
    {
        return 'social';
    }

    public function name(): string
    {
        return 'Post to LinkedIn';
    }

    public function description(): string
    {
        return 'Create a post on LinkedIn. Can post as personal profile or company page. Posts should include strong CTAs encouraging comments, replies, or DMs since we cannot initiate outreach via API. Requires approval.';
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
                    'description' => 'The text content of the LinkedIn post. Max 3000 characters.',
                ],
                'post_type' => [
                    'type' => 'string',
                    'enum' => ['text', 'article'],
                    'description' => 'Type of post: "text" for simple post, "article" for link share.',
                    'default' => 'text',
                ],
                'article_url' => [
                    'type' => 'string',
                    'description' => 'URL to share (required if post_type is "article").',
                ],
                'article_title' => [
                    'type' => 'string',
                    'description' => 'Title for the article link (optional).',
                ],
                'as_organization' => [
                    'type' => 'boolean',
                    'description' => 'Post as company page instead of personal profile.',
                    'default' => false,
                ],
            ],
            'required' => ['content'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'content' => 'required|string|max:3000',
            'post_type' => 'sometimes|string|in:text,article',
            'article_url' => 'required_if:post_type,article|nullable|url',
        ];
    }

    public function execute(array $params): array
    {
        // Get first user with LinkedIn connection (in real app, would be contextual)
        $user = User::whereHas('linkedInCredential')->first();

        if (! $user || ! $user->linkedInCredential) {
            return [
                'success' => false,
                'error' => 'No LinkedIn account connected.',
            ];
        }

        $credential = $user->linkedInCredential;

        try {
            $postType = $params['post_type'] ?? 'text';
            $asOrg = $params['as_organization'] ?? false;

            if ($asOrg && $credential->organization_id) {
                // Post as organization
                $result = $this->linkedin->postAsOrganization(
                    $credential,
                    $params['content'],
                    $postType === 'article' ? $params['article_url'] : null
                );
            } elseif ($postType === 'article' && ! empty($params['article_url'])) {
                // Share article
                $result = $this->linkedin->shareArticle(
                    $credential,
                    $params['content'],
                    $params['article_url'],
                    $params['article_title'] ?? null
                );
            } else {
                // Text post
                $result = $this->linkedin->createPost($credential, $params['content']);
            }

            return [
                'success' => true,
                'platform' => 'linkedin',
                'post_id' => $result['id'] ?? null,
                'posted_as' => $asOrg ? $credential->organization_name : $credential->name,
                'content_preview' => substr($params['content'], 0, 100).'...',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
