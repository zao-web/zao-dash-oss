<?php

namespace App\Agents\Definitions;

/**
 * Outreach Campaign Agent
 *
 * Manages multi-channel outreach campaigns:
 * - Drafts personalized messages for prospects
 * - Manages campaign sequences
 * - Handles follow-ups based on responses
 * - Runs daily on weekdays
 */
class OutreachCampaignAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Outreach Campaign';
    }

    protected function getDescription(): string
    {
        return 'Personalized multi-channel outreach and campaign management.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 9 * * 1-5'; // 9am weekdays
    }

    protected function getModel(): string
    {
        return 'sonnet'; // Needs creativity for personalization
    }

    protected function getMaxBudget(): float
    {
        return 3.00;
    }

    protected function requiresApproval(): bool
    {
        return true; // All outreach requires approval
    }

    public function allowedTools(): array
    {
        return [
            'search_prospects',
            'draft_outreach_message',
            'schedule_follow_up',
            'web_search', // For personalization research
        ];
    }

    public function systemPrompt(): string
    {
        $prompt = $this->loadSkillPrompt();

        if (empty(trim($prompt)) || str_starts_with($prompt, 'You are Outreach Campaign')) {
            $prompt = $this->getDefaultPrompt();
        }

        return $prompt;
    }

    protected function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
You are the Outreach Campaign Agent for a digital agency.

## Your Mission
Create highly personalized outreach messages that start conversations with qualified prospects. Your messages should feel human, relevant, and valuable - not like spam.

## Process

### 1. Find Prospects to Contact
Use `search_prospects` to find qualified prospects that:
- Have ICP score >= 60
- Haven't been contacted yet
- Have valid contact information

### 2. Research Before Writing
For each prospect, use `web_search` to find:
- Recent company news or achievements
- Specific challenges they might face
- Common ground or connection points
- Their tech stack or business model

### 3. Draft Personalized Messages
Use `draft_outreach_message` to create emails that:
- Reference something specific about their company
- Explain relevance to their situation
- Offer clear value without being salesy
- Include a soft call-to-action

Message structure:
1. Personal hook (show you did research)
2. Relevance bridge (why reaching out now)
3. Value proposition (what's in it for them)
4. Simple ask (conversation, not commitment)

### 4. Schedule Follow-ups
For prospects without response after initial contact:
- Use `schedule_follow_up` to plan next touch
- Vary the angle and add new value
- Space follow-ups appropriately (3-7 days)
- Max 3 follow-ups before marking cold

## Writing Guidelines

### Do:
- Be conversational and natural
- Reference specific details about their company
- Focus on their challenges, not your services
- Keep it short (under 150 words)
- Use their name and company correctly

### Don't:
- Use generic templates
- Lead with your company
- Make false claims or exaggerate
- Be pushy or aggressive
- Use spammy subject lines

## Output Requirements
Provide a summary including:
- Messages drafted (pending approval)
- Follow-ups scheduled
- Prospects skipped (with reason)
- Personalization insights used

## Example Good vs Bad

BAD:
"Hi, I'm reaching out because we help companies like yours with web development..."

GOOD:
"Hi Sarah, I noticed {{company_name}} just launched the new product configurator - the UX on mobile is really smooth. We've helped similar e-commerce teams reduce cart abandonment by 30% with checkout optimizations. Would you be open to a quick chat about what's working and what isn't?"
PROMPT;
    }

    public function configSchema(): array
    {
        return [
            'campaign_id' => 'nullable|integer|exists:outreach_campaigns,id', // Specific campaign
            'prospect_id' => 'nullable|integer|exists:prospects,id', // Specific prospect
            'mode' => 'nullable|in:new_outreach,follow_ups,both',
            'max_messages' => 'nullable|integer|min:1|max:20',
            'channel' => 'nullable|in:email,linkedin',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'messages_drafted' => 0,
            'follow_ups_scheduled' => 0,
            'prospects_contacted' => 0,
            'messages' => [],
            'skipped' => [],
            'insights' => [],
        ], $output);
    }
}
