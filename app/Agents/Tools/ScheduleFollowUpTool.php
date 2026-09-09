<?php

namespace App\Agents\Tools;

use App\Models\OutreachMessage;
use App\Models\Prospect;
use Carbon\Carbon;

/**
 * Schedule a follow-up message.
 * Requires approval for external communications.
 */
class ScheduleFollowUpTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Schedule Follow-Up';
    }

    public function description(): string
    {
        return 'Schedule a follow-up outreach message. Requires approval before messages are sent.';
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
                'message_id' => [
                    'type' => 'integer',
                    'description' => 'ID of existing draft message to schedule',
                ],
                'prospect_id' => [
                    'type' => 'integer',
                    'description' => 'ID of prospect (if creating new message)',
                ],
                'channel' => [
                    'type' => 'string',
                    'enum' => ['email', 'linkedin', 'phone'],
                    'description' => 'Communication channel',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Message subject (for new messages)',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Message body (for new messages)',
                ],
                'send_at' => [
                    'type' => 'string',
                    'description' => 'ISO datetime to send (default: next business day 9am)',
                ],
                'delay_days' => [
                    'type' => 'integer',
                    'description' => 'Days to delay from now (alternative to send_at)',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'message_id' => 'nullable|integer|exists:outreach_messages,id',
            'prospect_id' => 'nullable|integer|exists:prospects,id',
            'channel' => 'nullable|in:email,linkedin,phone',
            'subject' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:5000',
            'send_at' => 'nullable|date',
            'delay_days' => 'nullable|integer|min:0|max:365',
        ];
    }

    public function execute(array $params): array
    {
        // Calculate send time
        $sendAt = $this->calculateSendTime($params);

        // Either schedule existing message or create new one
        if (! empty($params['message_id'])) {
            $message = OutreachMessage::find($params['message_id']);

            if (! $message) {
                return [
                    'success' => false,
                    'error' => 'message_not_found',
                    'message' => 'Message not found',
                ];
            }

            $message->update([
                'scheduled_for' => $sendAt,
                'status' => 'scheduled',
            ]);

            $prospect = $message->prospect;
        } else {
            // Create new message
            if (empty($params['prospect_id'])) {
                return [
                    'success' => false,
                    'error' => 'missing_target',
                    'message' => 'Either message_id or prospect_id is required',
                ];
            }

            if (empty($params['body'])) {
                return [
                    'success' => false,
                    'error' => 'missing_content',
                    'message' => 'Message body is required for new messages',
                ];
            }

            $prospect = Prospect::find($params['prospect_id']);

            if (! $prospect) {
                return [
                    'success' => false,
                    'error' => 'prospect_not_found',
                    'message' => 'Prospect not found',
                ];
            }

            $message = OutreachMessage::create([
                'prospect_id' => $prospect->id,
                'channel' => $params['channel'] ?? 'email',
                'subject' => $params['subject'],
                'body' => $params['body'],
                'personalization_context' => [
                    'company_name' => $prospect->company_name,
                    'contact_name' => $prospect->contact_name,
                ],
                'scheduled_for' => $sendAt,
                'status' => 'scheduled',
            ]);
        }

        return [
            'success' => true,
            'message' => [
                'id' => $message->id,
                'channel' => $message->channel,
                'subject' => $message->subject,
                'body_preview' => substr($message->body, 0, 200).'...',
                'scheduled_for' => $sendAt->toDateTimeString(),
                'status' => $message->status,
            ],
            'prospect' => [
                'id' => $prospect->id,
                'company_name' => $prospect->company_name,
                'contact_name' => $prospect->contact_name,
                'recipient' => $message->recipient,
            ],
            'note' => "Message scheduled for {$sendAt->format('M d, Y g:i A')}. Requires approval.",
        ];
    }

    protected function calculateSendTime(array $params): Carbon
    {
        if (! empty($params['send_at'])) {
            return Carbon::parse($params['send_at']);
        }

        if (! empty($params['delay_days'])) {
            $target = now()->addDays($params['delay_days']);
        } else {
            $target = now()->addDay();
        }

        // Set to 9am
        $target->setTime(9, 0);

        // Skip weekends
        while ($target->isWeekend()) {
            $target->addDay();
        }

        return $target;
    }
}
