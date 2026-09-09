<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Services\AI\AnthropicService;
use Illuminate\Support\Facades\Log;

class LeadDetectionService
{
    public function __construct(
        protected AnthropicService $ai
    ) {}

    public function detectFromText(string $content, array $context = []): ?array
    {
        if (strlen($content) < 30) {
            return null;
        }

        $prompt = $this->buildDetectionPrompt($content, $context);

        try {
            $response = $this->ai->message(
                $prompt,
                $this->getSystemPrompt(),
                [],
                'claude-3-5-haiku-20241022'
            );

            $analysis = $this->parseResponse($response);

            if (! ($analysis['is_lead'] ?? false)) {
                return null;
            }

            return $analysis;
        } catch (\Exception $e) {
            Log::error('Lead detection failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function createLead(array $data, string $source, ?string $sourceId = null): Lead
    {
        $email = $data['email'] ?? $data['contact_email'] ?? null;
        $company = $data['company'] ?? $data['company_name'] ?? null;

        $existing = $this->findExisting($email, $company);
        if ($existing) {
            Log::info('Lead already exists, skipping duplicate', [
                'existing_id' => $existing->id,
                'source' => $source,
                'email' => $email,
            ]);

            return $existing;
        }

        $lead = Lead::create([
            'company_name' => $company ?? 'Unknown Company',
            'contact_name' => $data['name'] ?? $data['contact_name'] ?? null,
            'contact_email' => $email,
            'contact_phone' => $data['phone'] ?? $data['contact_phone'] ?? null,
            'website' => $data['website'] ?? null,
            'description' => $data['description'] ?? $data['message'] ?? null,
            'source' => $this->mapSource($source),
            'stage' => 'new',
            'deal_value' => $data['estimated_value'] ?? $data['deal_value'] ?? null,
            'probability' => 10,
            'notes' => $this->buildNotes($data, $source, $sourceId),
        ]);

        Log::info('Lead created', [
            'lead_id' => $lead->id,
            'source' => $source,
            'company' => $lead->company_name,
        ]);

        return $lead;
    }

    public function findExisting(?string $email, ?string $company): ?Lead
    {
        if (! $email && ! $company) {
            return null;
        }

        $query = Lead::query();

        if ($email) {
            $query->where('contact_email', $email);
        } elseif ($company) {
            $query->where('company_name', 'like', $company);
        }

        return $query->whereNotIn('stage', ['won', 'lost'])->first();
    }

    protected function buildDetectionPrompt(string $content, array $context): string
    {
        $contextStr = '';
        if (! empty($context['source'])) {
            $contextStr .= "Source: {$context['source']}\n";
        }
        if (! empty($context['from_name'])) {
            $contextStr .= "From: {$context['from_name']}\n";
        }
        if (! empty($context['from_email'])) {
            $contextStr .= "Email: {$context['from_email']}\n";
        }
        if (! empty($context['subject'])) {
            $contextStr .= "Subject: {$context['subject']}\n";
        }

        return <<<PROMPT
Analyze this message to determine if it represents a potential sales lead/prospect.

{$contextStr}
Content:
{$content}

Return JSON:
{
  "is_lead": <boolean - is this a potential sales inquiry or project request?>,
  "confidence": <0-1 float>,
  "company": <string or null - company name if mentioned>,
  "name": <string or null - contact name if identifiable>,
  "email": <string or null - email if found in content>,
  "phone": <string or null - phone if found>,
  "website": <string or null - website/URL if mentioned>,
  "project_type": <string or null - type of work they're asking about>,
  "estimated_value": <number or null - rough estimate if scope is clear>,
  "urgency": <"high"|"medium"|"low">,
  "description": <string - brief summary of what they need>
}

A message IS a lead if:
- Asking about services, pricing, or availability
- Describing a project they need help with
- Requesting a quote or proposal
- Expressing interest in working together

A message is NOT a lead if:
- From an existing client (check context)
- Support request for existing project
- General question not about hiring
- Spam or marketing
- Internal communication
PROMPT;
    }

    protected function getSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are a lead qualification expert for a web development/digital agency.
Your job is to identify potential sales opportunities from incoming communications.
Be selective - only flag genuine business inquiries, not support requests or spam.
Return valid JSON only.
SYSTEM;
    }

    protected function parseResponse(array $response): array
    {
        $content = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content = $block['text'] ?? '';
                break;
            }
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
            $content = $matches[1];
        }

        $data = json_decode(trim($content), true);

        return is_array($data) ? $data : ['is_lead' => false];
    }

    protected function mapSource(string $source): string
    {
        return match ($source) {
            'gravity_forms', 'gf', 'form' => 'website',
            'email', 'gmail' => 'other',
            'slack', 'slack_dm' => 'other',
            'linkedin' => 'linkedin',
            'referral' => 'referral',
            'conference' => 'conference',
            default => 'other',
        };
    }

    protected function buildNotes(array $data, string $source, ?string $sourceId): string
    {
        $notes = "Source: {$source}";

        if ($sourceId) {
            $notes .= "\nSource ID: {$sourceId}";
        }

        if (! empty($data['project_type'])) {
            $notes .= "\nProject Type: {$data['project_type']}";
        }

        if (! empty($data['urgency'])) {
            $notes .= "\nUrgency: {$data['urgency']}";
        }

        if (! empty($data['original_content'])) {
            $notes .= "\n\n---\nOriginal Message:\n{$data['original_content']}";
        }

        return $notes;
    }
}
