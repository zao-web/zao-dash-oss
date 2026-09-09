<?php

namespace App\Agents\Tools;

use App\Models\OutreachCampaign;
use App\Models\OutreachSequence;

/**
 * Create a new outreach campaign.
 * Requires approval because it creates automated communication.
 */
class CreateOutreachCampaignTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Create Outreach Campaign';
    }

    public function description(): string
    {
        return 'Create a new multi-step outreach campaign for prospects. Requires approval before activation.';
    }

    public function requiresApproval(): bool
    {
        return true;
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
                'name' => [
                    'type' => 'string',
                    'description' => 'Campaign name (required)',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Campaign description and goals',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['cold_outreach', 'nurture', 'reengagement'],
                    'description' => 'Campaign type',
                ],
                'icp_id' => [
                    'type' => 'integer',
                    'description' => 'Target ICP for this campaign',
                ],
                'min_icp_score' => [
                    'type' => 'integer',
                    'description' => 'Minimum ICP score to target (default 60)',
                ],
                'target_industries' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Industries to target',
                ],
                'target_titles' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Job titles to target',
                ],
                'use_email' => [
                    'type' => 'boolean',
                    'description' => 'Include email outreach',
                ],
                'use_linkedin' => [
                    'type' => 'boolean',
                    'description' => 'Include LinkedIn outreach',
                ],
                'sequences' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'channel' => ['type' => 'string', 'enum' => ['email', 'linkedin', 'phone', 'manual']],
                            'subject_template' => ['type' => 'string'],
                            'body_template' => ['type' => 'string'],
                            'delay_days' => ['type' => 'integer'],
                            'condition' => ['type' => 'string', 'enum' => ['always', 'no_reply', 'opened', 'not_opened']],
                        ],
                    ],
                    'description' => 'Sequence steps for the campaign',
                ],
            ],
            'required' => ['name'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'nullable|in:cold_outreach,nurture,reengagement',
            'icp_id' => 'nullable|integer|exists:ideal_customer_profiles,id',
            'min_icp_score' => 'nullable|integer|min:0|max:100',
            'target_industries' => 'nullable|array',
            'target_titles' => 'nullable|array',
            'use_email' => 'nullable|boolean',
            'use_linkedin' => 'nullable|boolean',
            'sequences' => 'nullable|array',
            'sequences.*.channel' => 'required|in:email,linkedin,phone,manual',
            'sequences.*.body_template' => 'required|string',
            'sequences.*.subject_template' => 'nullable|string',
            'sequences.*.delay_days' => 'nullable|integer|min:0',
            'sequences.*.condition' => 'nullable|in:always,no_reply,opened,not_opened',
        ];
    }

    public function execute(array $params): array
    {
        $campaign = OutreachCampaign::create([
            'name' => $params['name'],
            'description' => $params['description'] ?? null,
            'type' => $params['type'] ?? 'cold_outreach',
            'icp_id' => $params['icp_id'] ?? null,
            'min_icp_score' => $params['min_icp_score'] ?? 60,
            'target_industries' => $params['target_industries'] ?? [],
            'target_titles' => $params['target_titles'] ?? [],
            'use_email' => $params['use_email'] ?? true,
            'use_linkedin' => $params['use_linkedin'] ?? false,
            'use_phone' => $params['use_phone'] ?? false,
            'status' => 'draft', // Always starts as draft, needs approval
        ]);

        // Create sequence steps
        $sequences = $params['sequences'] ?? $this->getDefaultSequences();
        foreach ($sequences as $index => $seq) {
            OutreachSequence::create([
                'campaign_id' => $campaign->id,
                'step_number' => $index + 1,
                'channel' => $seq['channel'] ?? 'email',
                'subject_template' => $seq['subject_template'] ?? null,
                'body_template' => $seq['body_template'],
                'delay_days' => $seq['delay_days'] ?? ($index * 3), // 0, 3, 6 days default
                'condition' => $seq['condition'] ?? 'always',
                'requires_approval' => $index === 0, // First message needs approval
            ]);
        }

        // Get eligible prospect count
        $eligibleCount = $campaign->getEligibleProspects()->count();

        return [
            'success' => true,
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'slug' => $campaign->slug,
                'status' => $campaign->status,
                'type' => $campaign->type,
                'sequence_count' => $campaign->sequences()->count(),
            ],
            'eligible_prospects' => $eligibleCount,
            'message' => "Campaign created with {$campaign->sequences()->count()} sequence steps. Requires approval to activate.",
        ];
    }

    protected function getDefaultSequences(): array
    {
        return [
            [
                'channel' => 'email',
                'subject_template' => '{{company_name}} + Our Services',
                'body_template' => "Hi {{first_name}},\n\nI came across {{company_name}} and was impressed by what you're building.\n\n[Personalized value proposition here]\n\nWould you be open to a brief call to explore how we might help?\n\nBest,\n[Your Name]",
                'delay_days' => 0,
                'condition' => 'always',
            ],
            [
                'channel' => 'email',
                'subject_template' => 'Re: {{company_name}} + Our Services',
                'body_template' => "Hi {{first_name}},\n\nJust following up on my previous email. I'd love to learn more about your current challenges.\n\n[Additional value point]\n\nWould next week work for a quick chat?\n\nBest,\n[Your Name]",
                'delay_days' => 3,
                'condition' => 'no_reply',
            ],
            [
                'channel' => 'email',
                'subject_template' => 'One more thought for {{company_name}}',
                'body_template' => "Hi {{first_name}},\n\nI wanted to share one more idea that might be relevant to {{company_name}}.\n\n[Specific insight or case study]\n\nIf timing isn't right, no worries. Feel free to reach out whenever makes sense.\n\nBest,\n[Your Name]",
                'delay_days' => 7,
                'condition' => 'no_reply',
            ],
        ];
    }
}
