<?php

namespace App\Agents\Tools;

use App\Models\OutreachMessage;
use App\Models\OutreachSequence;
use App\Models\Prospect;

/**
 * Draft a personalized outreach message.
 */
class DraftOutreachMessageTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Draft Outreach Message';
    }

    public function description(): string
    {
        return 'Draft a personalized outreach message for a prospect. Messages should encourage replies and engagement since we cannot initiate DMs via social APIs. Include clear value proposition and soft CTA.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prospect_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the prospect to contact',
                ],
                'sequence_id' => [
                    'type' => 'integer',
                    'description' => 'ID of campaign sequence to use as template',
                ],
                'channel' => [
                    'type' => 'string',
                    'enum' => ['email', 'linkedin', 'phone'],
                    'description' => 'Communication channel (default: email)',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Custom email subject (overrides template)',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Custom message body (overrides template)',
                ],
                'personalization_context' => [
                    'type' => 'object',
                    'description' => 'Additional personalization variables',
                ],
            ],
            'required' => ['prospect_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'prospect_id' => 'required|integer|exists:prospects,id',
            'sequence_id' => 'nullable|integer|exists:outreach_sequences,id',
            'channel' => 'nullable|in:email,linkedin,phone',
            'subject' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:5000',
            'personalization_context' => 'nullable|array',
        ];
    }

    public function execute(array $params): array
    {
        $prospect = Prospect::with('icp')->find($params['prospect_id']);

        if (! $prospect) {
            return [
                'success' => false,
                'error' => 'prospect_not_found',
                'message' => 'Prospect not found',
            ];
        }

        $channel = $params['channel'] ?? 'email';
        $sequence = null;
        $subject = $params['subject'] ?? null;
        $body = $params['body'] ?? null;

        // Use template if sequence provided
        if (! empty($params['sequence_id'])) {
            $sequence = OutreachSequence::find($params['sequence_id']);
            if ($sequence) {
                $personalized = $sequence->personalizeFor($prospect, $params['personalization_context'] ?? []);
                $subject = $subject ?? $personalized['subject'];
                $body = $body ?? $personalized['body'];
                $channel = $sequence->channel;
            }
        }

        // Validate we have content
        if (empty($body)) {
            return [
                'success' => false,
                'error' => 'no_content',
                'message' => 'Message body is required. Provide body or sequence_id.',
            ];
        }

        // Check contact info exists
        $contactInfo = match ($channel) {
            'email' => $prospect->contact_email,
            'linkedin' => $prospect->contact_linkedin,
            'phone' => $prospect->contact_phone,
            default => $prospect->contact_email,
        };

        if (empty($contactInfo)) {
            return [
                'success' => false,
                'error' => 'missing_contact',
                'message' => "Prospect is missing {$channel} contact information",
            ];
        }

        // Create draft message
        $message = OutreachMessage::create([
            'sequence_id' => $sequence?->id,
            'prospect_id' => $prospect->id,
            'channel' => $channel,
            'subject' => $subject,
            'body' => $body,
            'personalization_context' => array_merge([
                'company_name' => $prospect->company_name,
                'contact_name' => $prospect->contact_name,
                'first_name' => explode(' ', $prospect->contact_name ?? '')[0] ?? '',
                'contact_title' => $prospect->contact_title,
                'industry' => $prospect->industry,
            ], $params['personalization_context'] ?? []),
            'status' => 'draft',
        ]);

        return [
            'success' => true,
            'message' => [
                'id' => $message->id,
                'channel' => $message->channel,
                'subject' => $message->subject,
                'body' => $message->body,
                'recipient' => $contactInfo,
                'status' => $message->status,
            ],
            'prospect' => [
                'id' => $prospect->id,
                'company_name' => $prospect->company_name,
                'contact_name' => $prospect->contact_name,
            ],
            'note' => 'Message drafted. Requires approval before sending.',
        ];
    }
}
