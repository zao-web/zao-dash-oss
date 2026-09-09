<?php

namespace App\Services\Seo;

use App\Models\SeoPage;

/**
 * Generates structured CTAs for SEO pages based on playbook type.
 *
 * All CTAs drive to the contact page with appropriate UTM parameters
 * for attribution tracking.
 */
class SeoCTAService
{
    /**
     * Base URL for all CTAs.
     */
    protected string $baseUrl = 'https://example.com/contact';

    /**
     * CTA configurations by playbook type.
     *
     * @var array<string, array{headline: string, button_text: string, type: string, description: string}>
     */
    protected array $ctaTemplates = [
        'location' => [
            'headline' => 'Ready to Build Something Great?',
            'button_text' => 'Schedule a Local Consultation',
            'type' => 'location',
            'description' => 'Connect with our local team to discuss your project.',
        ],
        'comparison' => [
            'headline' => 'Not Sure Which Technology is Right?',
            'button_text' => 'Get Expert Technology Advice',
            'type' => 'comparison',
            'description' => 'Our experts can help you choose the right stack for your needs.',
        ],
        'case-study' => [
            'headline' => 'Want Similar Results?',
            'button_text' => 'Discuss Your Project',
            'type' => 'case-study',
            'description' => 'Let us help you achieve results like these.',
        ],
        'persona' => [
            'headline' => 'Need Industry-Specific Expertise?',
            'button_text' => 'Get Industry-Focused Help',
            'type' => 'industry',
            'description' => 'We understand your industry and can build solutions that fit.',
        ],
        'vertical' => [
            'headline' => 'Need Industry-Specific Expertise?',
            'button_text' => 'Get Industry-Focused Help',
            'type' => 'industry',
            'description' => 'We understand your industry and can build solutions that fit.',
        ],
        'template' => [
            'headline' => 'Need Something More Custom?',
            'button_text' => 'Request a Custom Solution',
            'type' => 'template',
            'description' => 'Templates are a start, but we can build exactly what you need.',
        ],
        'tool' => [
            'headline' => 'Need Something More Custom?',
            'button_text' => 'Request a Custom Solution',
            'type' => 'template',
            'description' => 'Tools are helpful, but custom solutions deliver more value.',
        ],
        'integration' => [
            'headline' => 'Need Help With Your Integration?',
            'button_text' => 'Get Integration Support',
            'type' => 'integration',
            'description' => 'We can help you connect systems and automate workflows.',
        ],
        'calculator' => [
            'headline' => 'Ready to Get Started?',
            'button_text' => 'Get a Project Estimate',
            'type' => 'calculator',
            'description' => 'Get a personalized estimate for your project.',
        ],
        'glossary' => [
            'headline' => 'Want to Learn More?',
            'button_text' => 'Schedule a Discovery Call',
            'type' => 'educational',
            'description' => 'Let us walk you through how this applies to your project.',
        ],
        'educational' => [
            'headline' => 'Want to Learn More?',
            'button_text' => 'Schedule a Discovery Call',
            'type' => 'educational',
            'description' => 'We can help you understand how this applies to your needs.',
        ],
        'curation' => [
            'headline' => 'Need Help Choosing?',
            'button_text' => 'Get Selection Guidance',
            'type' => 'curation',
            'description' => 'Our experts can help you pick the right solution.',
        ],
        'ranking' => [
            'headline' => 'Need Help Choosing?',
            'button_text' => 'Get Selection Guidance',
            'type' => 'curation',
            'description' => 'Let us help you navigate your options.',
        ],
    ];

    /**
     * Default CTA for unknown playbook types.
     */
    protected array $defaultCta = [
        'headline' => 'Ready to Get Started?',
        'button_text' => 'Contact Us Today',
        'type' => 'general',
        'description' => 'Let us help you build something amazing.',
    ];

    /**
     * Get CTA configuration for an SEO page.
     *
     * @return array{headline: string, button_text: string, url: string, type: string, description: string}
     */
    public function getCtaForPage(SeoPage $page): array
    {
        $playbook = strtolower($page->playbook ?? 'general');
        $template = $this->ctaTemplates[$playbook] ?? $this->defaultCta;

        // Build URL with UTM parameters
        $url = $this->buildCtaUrl($template['type'], $page);

        return [
            'headline' => $template['headline'],
            'button_text' => $template['button_text'],
            'url' => $url,
            'type' => $template['type'],
            'description' => $template['description'],
        ];
    }

    /**
     * Generate WordPress Blocks HTML for the CTA section.
     */
    public function generateCtaHtml(array $cta): string
    {
        $headline = esc_html($cta['headline']);
        $buttonText = esc_html($cta['button_text']);
        $url = esc_url($cta['url']);
        $description = esc_html($cta['description']);

        return <<<HTML
<!-- wp:group {"className":"seo-cta-section","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|40","right":"var:preset|spacing|40"}},"border":{"radius":"8px"}},"backgroundColor":"primary","textColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group seo-cta-section has-base-color has-primary-background-color has-text-color has-background" style="border-radius:8px;padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--40)">

<!-- wp:heading {"textAlign":"center","level":2,"style":{"typography":{"fontWeight":"700"}}} -->
<h2 class="wp-block-heading has-text-align-center" style="font-weight:700">{$headline}</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"1.125rem"}}} -->
<p class="has-text-align-center" style="font-size:1.125rem">{$description}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons">
<!-- wp:button {"backgroundColor":"base","textColor":"primary","style":{"typography":{"fontWeight":"600"},"border":{"radius":"4px"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-primary-color has-base-background-color has-text-color has-background wp-element-button" href="{$url}" style="border-radius:4px;font-weight:600">{$buttonText}</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
HTML;
    }

    /**
     * Generate a simple inline CTA (for use within content).
     */
    public function generateInlineCtaHtml(array $cta): string
    {
        $buttonText = esc_html($cta['button_text']);
        $url = esc_url($cta['url']);

        return <<<HTML
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"left"}} -->
<div class="wp-block-buttons">
<!-- wp:button {"backgroundColor":"primary","textColor":"base"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-primary-background-color has-text-color has-background wp-element-button" href="{$url}">{$buttonText}</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
HTML;
    }

    /**
     * Apply CTA to an SEO page (update the record).
     */
    public function applyToPage(SeoPage $page): array
    {
        $cta = $this->getCtaForPage($page);

        $page->update([
            'cta_type' => $cta['type'],
            'cta_text' => $cta['button_text'],
            'cta_url' => $cta['url'],
        ]);

        return $cta;
    }

    /**
     * Build the CTA URL with UTM parameters for tracking.
     */
    protected function buildCtaUrl(string $type, SeoPage $page): string
    {
        $params = [
            'source' => 'seo',
            'type' => $type,
        ];

        // Add page slug for more specific tracking if available
        if ($page->url_slug) {
            $params['page'] = $page->url_slug;
        }

        return $this->baseUrl.'?'.http_build_query($params);
    }

    /**
     * Get all available CTA templates.
     *
     * @return array<string, array>
     */
    public function getAvailableTemplates(): array
    {
        return $this->ctaTemplates;
    }

    /**
     * Get the CTA template for a specific playbook.
     */
    public function getTemplateForPlaybook(string $playbook): array
    {
        return $this->ctaTemplates[strtolower($playbook)] ?? $this->defaultCta;
    }
}

/**
 * Helper function for escaping HTML (if not already defined).
 */
if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Helper function for escaping URLs (if not already defined).
 */
if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}
