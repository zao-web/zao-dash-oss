<?php

namespace App\Agents\Definitions;

/**
 * Ad Creative Agent
 *
 * Generates ad creatives (copy + images) for campaigns:
 * - AI-powered copywriting
 * - Image generation via Nano Banana Pro
 * - Brand guideline compliance
 * - A/B test variant creation
 */
class AdCreativeAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Ad Creative Director';
    }

    protected function getDescription(): string
    {
        return 'Generate high-performing ad creatives (copy + images) aligned with brand guidelines.';
    }

    protected function getTrigger(): string
    {
        return 'chained'; // Triggered after AdCampaignAgent
    }

    protected function getChainFrom(): ?string
    {
        return 'ad-campaign'; // Chains from AdCampaignAgent
    }

    protected function requiresApproval(): bool
    {
        return true; // Creative content requires approval
    }

    protected function getMaxBudget(): float
    {
        return 3.00; // Sonnet for copy + Nano Banana Pro for images
    }

    public function allowedTools(): array
    {
        return [
            'get-client',
            'get-brand-guidelines',
        ];
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are the Creative Director for Zao's AI ad platform.

        **Your Role:** Generate high-performing ad creatives (copy + images) that align with brand guidelines and campaign objectives.

        **Process:**
        1. Read brand guidelines (colors, fonts, voice, tone)
        2. Analyze campaign objective to determine messaging angle
        3. Generate primary creative (headline + primary text + description + image)
        4. Generate 2-3 test variants for A/B testing
        5. Validate compliance with Meta ad policies

        **Creative Best Practices:**
        - **Headlines:** 5 words max, outcome-focused, specific numbers (e.g., "Cut Admin Time 90%")
        - **Primary Text:** 2-3 sentences, problem → solution, include social proof
        - **Description:** Reinforce CTA, add urgency/scarcity
        - **Images:** Clean, professional, minimal text (<20% overlay)
        - **CTAs:** Be specific (not "Learn More" → "Book Free Analysis")

        **Character Limits:**
        - Headline: 40 characters max
        - Primary Text: 125 characters max
        - Description: 30 characters max

        **Lead Gen Messaging Angles:**
        - **Problem/Solution:** "Cut Admin Time 90% With AI Automation"
        - **Social Proof:** "20+ Local Businesses Trust Zao"
        - **Authority:** "20 Years Building Custom Solutions"
        - **Risk Reversal:** "Save 10+ Hours/Week or Get Paid"

        **Awareness Messaging Angles:**
        - **Education:** "What Is Workflow Transformation?"
        - **Case Study:** "How [Client] Saved $50k/Year"
        - **Behind-the-Scenes:** "How We Build AI Agents"

        **Image Generation:**
        - Use Nano Banana Pro (Gemini 3 Pro Image) for all images
        - Style should match brand guidelines
        - Avoid faces (licensing issues)
        - Use abstract/symbolic representations
        - Ensure mobile-friendly composition

        **A/B Testing Strategy:**
        Generate at least 3 variants per campaign:
        1. Control (primary creative)
        2. Variant A (test different headline)
        3. Variant B (test different image)

        **Output:** Provide complete creative specs ready for approval:
        - Headline, Primary Text, Description
        - Image generation prompt
        - Variants with test angles
        - Compliance notes
        PROMPT;
    }

    public function configSchema(): array
    {
        return [
            'campaign_id' => 'required|integer|exists:ad_campaigns,id',
            'creative_type' => 'required|in:single_image,carousel',
            'variant_count' => 'nullable|integer|min:1|max:5',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'headline' => '',
            'primary_text' => '',
            'description' => '',
            'image_prompt' => '',
            'variants' => [],
            'compliance_notes' => '',
        ], $output);
    }
}
