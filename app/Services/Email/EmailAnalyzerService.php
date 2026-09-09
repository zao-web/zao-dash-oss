<?php

namespace App\Services\Email;

use App\Models\Email;
use App\Models\Project;
use App\Models\Task;
use App\Services\AI\AnthropicService;
use App\Services\Leads\LeadDetectionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EmailAnalyzerService
{
    private const ACTION_CONFIDENCE_THRESHOLD = 0.7;

    public function __construct(
        protected AnthropicService $ai,
        protected LeadDetectionService $leadDetection
    ) {}

    public function analyzeEmail(Email $email): array
    {
        if (strlen($email->body_text ?? '') < 20) {
            return $this->markAnalyzed($email, [
                'action_required' => false,
                'skipped' => true,
                'reason' => 'too_short',
            ]);
        }

        $prompt = $this->buildAnalysisPrompt($email);

        try {
            $response = $this->ai->message(
                $prompt,
                $this->getSystemPrompt(),
                [],
                'claude-3-5-haiku-20241022'
            );

            $analysis = $this->parseResponse($this->extractTextContent($response));

            $email->update([
                'action_required' => $analysis['action_required'] ?? false,
                'action_summary' => $analysis['action_summary'] ?? null,
                'urgency' => $analysis['urgency'] ?? null,
                'sentiment_label' => $analysis['sentiment'] ?? null,
                'ai_analyzed_at' => now(),
            ]);

            if (! $email->project_id && $email->client_id) {
                $this->matchProject($email, $analysis);
            }

            // Check for lead potential if NOT from existing client
            if (! $email->client_id && ! $email->contractor_id) {
                $this->detectAndCreateLead($email);
            }

            return $analysis;
        } catch (\Exception $e) {
            Log::error('Email analysis failed', [
                'email_id' => $email->id,
                'error' => $e->getMessage(),
            ]);

            $email->update(['ai_analyzed_at' => now()]);

            return [
                'action_required' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function analyzeEmails(Collection $emails): array
    {
        $results = [];

        foreach ($emails as $email) {
            $results[$email->id] = $this->analyzeEmail($email);
        }

        return $results;
    }

    public function createTaskFromEmail(Email $email, array $analysis): ?Task
    {
        if (! ($analysis['action_required'] ?? false)) {
            return null;
        }

        if (($analysis['confidence'] ?? 0) < self::ACTION_CONFIDENCE_THRESHOLD) {
            return null;
        }

        $title = $analysis['action_summary'] ?? "Follow up on: {$email->subject}";
        if (strlen($title) > 100) {
            $title = substr($title, 0, 97).'...';
        }

        $task = Task::create([
            'title' => $title,
            'description' => "Email from: {$email->from_name} <{$email->from_address}>\n\nSubject: {$email->subject}\n\nOriginal email ID: {$email->id}",
            'status' => 'pending',
            'priority' => $this->mapUrgencyToPriority($analysis['urgency'] ?? 'normal'),
            'client_id' => $email->client_id,
            'project_id' => $email->project_id,
            'due_date' => $this->calculateDueDate($analysis['urgency'] ?? 'normal'),
            'source' => 'email',
            'source_id' => $email->id,
        ]);

        Log::info('Task created from email', [
            'email_id' => $email->id,
            'task_id' => $task->id,
        ]);

        return $task;
    }

    protected function buildAnalysisPrompt(Email $email): string
    {
        $clientName = $email->client?->name ?? 'Unknown';

        return <<<PROMPT
Analyze this client email and determine if action is required.

From: {$email->from_name} <{$email->from_address}>
Client: {$clientName}
Subject: {$email->subject}
Received: {$email->received_at?->format('M j, Y g:i A')}

Email Content:
{$email->body_text}

Return JSON with:
{
  "action_required": <boolean - does this email need a response or action?>,
  "action_summary": <string or null - what specific action is needed, max 100 chars>,
  "confidence": <0-1 float - how confident are you this needs action?>,
  "urgency": <"high"|"medium"|"low" - how urgent is the response needed?>,
  "sentiment": <"positive"|"neutral"|"negative"|"urgent">,
  "topic_keywords": <array of 2-4 keywords about the email topic>,
  "is_question": <boolean - is the client asking a question?>,
  "is_request": <boolean - is the client requesting something?>,
  "is_complaint": <boolean - is this a complaint or concern?>
}

Return ONLY valid JSON.
PROMPT;
    }

    protected function getSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are an email analysis assistant for a web development agency. Your job is to analyze client emails and determine if action is required.

Action is required if the email:
- Asks a direct question that needs an answer
- Requests work, changes, or deliverables
- Reports a bug or issue
- Expresses urgency or deadline concerns
- Raises a concern that needs addressing

Action is NOT required for:
- Simple thank you messages
- FYI/informational emails with no questions
- Automated notifications
- Newsletter or marketing emails
- Already-answered threads

Be conservative - only mark action_required=true when there's a clear need for response.
SYSTEM;
    }

    protected function parseResponse(string $response): array
    {
        $json = $response;

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $matches)) {
            $json = $matches[1];
        }

        $data = json_decode(trim($json), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Failed to parse email analysis response', [
                'response' => substr($response, 0, 500),
            ]);

            return ['action_required' => false];
        }

        return $data;
    }

    protected function markAnalyzed(Email $email, array $result): array
    {
        $email->update(['ai_analyzed_at' => now()]);

        return $result;
    }

    protected function detectAndCreateLead(Email $email): void
    {
        $content = $email->body_text ?? '';
        if (strlen($content) < 50) {
            return;
        }

        $leadData = $this->leadDetection->detectFromText($content, [
            'source' => 'email',
            'from_name' => $email->from_name,
            'from_email' => $email->from_address,
            'subject' => $email->subject,
        ]);

        if (! $leadData) {
            return;
        }

        $leadData['contact_email'] = $leadData['email'] ?? $email->from_address;
        $leadData['contact_name'] = $leadData['name'] ?? $email->from_name;

        $lead = $this->leadDetection->createLead(
            data: array_merge($leadData, [
                'original_content' => "Subject: {$email->subject}\n\n{$content}",
            ]),
            source: 'email',
            sourceId: "email:{$email->id}"
        );

        Log::info('Lead created from email', [
            'email_id' => $email->id,
            'lead_id' => $lead->id,
        ]);
    }

    protected function matchProject(Email $email, array $analysis): void
    {
        if (empty($analysis['topic_keywords'])) {
            return;
        }

        $keywords = implode(' ', $analysis['topic_keywords']);

        $project = Project::where('client_id', $email->client_id)
            ->where('status', 'active')
            ->where(function ($q) use ($keywords, $email) {
                $q->where('name', 'like', "%{$keywords}%")
                    ->orWhere('name', 'like', "%{$email->subject}%");
            })
            ->first();

        if (! $project) {
            $project = Project::where('client_id', $email->client_id)
                ->where('status', 'active')
                ->orderBy('updated_at', 'desc')
                ->first();
        }

        if ($project) {
            $email->update(['project_id' => $project->id]);
        }
    }

    protected function mapUrgencyToPriority(string $urgency): string
    {
        return match ($urgency) {
            'high' => 'high',
            'medium' => 'medium',
            default => 'low',
        };
    }

    protected function calculateDueDate(string $urgency): \Carbon\Carbon
    {
        return match ($urgency) {
            'high' => now()->addDay(),
            'medium' => now()->addDays(3),
            default => now()->addWeek(),
        };
    }

    /**
     * Extract text content from Anthropic response array.
     */
    protected function extractTextContent(array $response): string
    {
        $content = $response['content'] ?? [];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                return $block['text'] ?? '';
            }
        }

        return '';
    }
}
