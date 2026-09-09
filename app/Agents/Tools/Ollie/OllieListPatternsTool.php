<?php

namespace App\Agents\Tools\Ollie;

use App\Agents\Tools\BaseTool;

/**
 * List available Ollie patterns.
 *
 * Returns the complete pattern library with metadata for pattern selection.
 */
class OllieListPatternsTool extends BaseTool
{
    public function category(): string
    {
        return 'ollie';
    }

    public function name(): string
    {
        return 'List Ollie Patterns';
    }

    public function description(): string
    {
        return 'List all available Ollie block patterns with their categories, descriptions, and use cases. Use this to discover patterns for page building.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'enum' => ['heroes', 'headers', 'footers', 'cards', 'features', 'testimonials', 'pricing', 'ctas', 'pages', 'blog', 'menus', 'templates', 'all'],
                    'description' => 'Filter by pattern category',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search patterns by keyword',
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $category = $params['category'] ?? 'all';
        $search = $params['search'] ?? null;

        $patterns = $this->getPatternLibrary();

        // Filter by category
        if ($category !== 'all') {
            $patterns = array_filter($patterns, fn ($p) => $p['category'] === $category);
        }

        // Filter by search
        if ($search) {
            $search = strtolower($search);
            $patterns = array_filter($patterns, function ($p) use ($search) {
                return str_contains(strtolower($p['name']), $search) ||
                       str_contains(strtolower($p['description']), $search) ||
                       in_array($search, array_map('strtolower', $p['keywords'] ?? []));
            });
        }

        return [
            'count' => count($patterns),
            'patterns' => array_values($patterns),
        ];
    }

    private function getPatternLibrary(): array
    {
        return [
            // Heroes
            ['slug' => 'ollie/hero-dark', 'name' => 'Hero Dark', 'category' => 'heroes', 'description' => 'Dark hero with overlay image and bold statement', 'keywords' => ['hero', 'dark', 'cta', 'homepage']],
            ['slug' => 'ollie/hero-light', 'name' => 'Hero Light', 'category' => 'heroes', 'description' => 'Light, clean hero for professional sites', 'keywords' => ['hero', 'light', 'clean', 'professional']],
            ['slug' => 'ollie/hero-call-to-action-buttons', 'name' => 'Hero CTA Buttons', 'category' => 'heroes', 'description' => 'CTA-focused hero with dual buttons', 'keywords' => ['hero', 'cta', 'buttons', 'conversion']],
            ['slug' => 'ollie/hero-call-to-action-buttons-light', 'name' => 'Hero CTA Buttons Light', 'category' => 'heroes', 'description' => 'Light version of CTA hero', 'keywords' => ['hero', 'cta', 'light']],
            ['slug' => 'ollie/hero-text-image-and-logos', 'name' => 'Hero Text Image Logos', 'category' => 'heroes', 'description' => 'Hero with social proof logos', 'keywords' => ['hero', 'logos', 'social proof', 'trust']],

            // Headers
            ['slug' => 'ollie/header-dark', 'name' => 'Header Dark', 'category' => 'headers', 'description' => 'Dark navigation bar', 'keywords' => ['header', 'nav', 'dark']],
            ['slug' => 'ollie/header-light', 'name' => 'Header Light', 'category' => 'headers', 'description' => 'Light navigation bar', 'keywords' => ['header', 'nav', 'light']],
            ['slug' => 'ollie/header-dark-with-banner', 'name' => 'Header Dark Banner', 'category' => 'headers', 'description' => 'Dark header with announcement banner', 'keywords' => ['header', 'banner', 'announcement']],
            ['slug' => 'ollie/header-light-with-banner', 'name' => 'Header Light Banner', 'category' => 'headers', 'description' => 'Light header with announcement banner', 'keywords' => ['header', 'banner', 'light']],
            ['slug' => 'ollie/header-dark-with-buttons', 'name' => 'Header Dark Buttons', 'category' => 'headers', 'description' => 'Dark header with CTA buttons', 'keywords' => ['header', 'buttons', 'cta']],
            ['slug' => 'ollie/header-light-with-buttons', 'name' => 'Header Light Buttons', 'category' => 'headers', 'description' => 'Light header with CTA buttons', 'keywords' => ['header', 'buttons', 'light']],

            // Footers
            ['slug' => 'ollie/footer-dark', 'name' => 'Footer Dark', 'category' => 'footers', 'description' => 'Full dark footer with columns', 'keywords' => ['footer', 'dark', 'columns']],
            ['slug' => 'ollie/footer-light', 'name' => 'Footer Light', 'category' => 'footers', 'description' => 'Full light footer with columns', 'keywords' => ['footer', 'light', 'columns']],
            ['slug' => 'ollie/footer-dark-centered', 'name' => 'Footer Dark Centered', 'category' => 'footers', 'description' => 'Centered dark footer', 'keywords' => ['footer', 'centered', 'dark']],
            ['slug' => 'ollie/footer-light-centered', 'name' => 'Footer Light Centered', 'category' => 'footers', 'description' => 'Centered light footer', 'keywords' => ['footer', 'centered', 'light']],
            ['slug' => 'ollie/footer-dark-minimal', 'name' => 'Footer Dark Minimal', 'category' => 'footers', 'description' => 'Minimal dark footer', 'keywords' => ['footer', 'minimal', 'dark']],
            ['slug' => 'ollie/footer-light-minimal', 'name' => 'Footer Light Minimal', 'category' => 'footers', 'description' => 'Minimal light footer', 'keywords' => ['footer', 'minimal', 'light']],

            // Cards
            ['slug' => 'ollie/card-pricing-table', 'name' => 'Pricing Card', 'category' => 'cards', 'description' => 'Single pricing tier card', 'keywords' => ['card', 'pricing', 'tier']],
            ['slug' => 'ollie/card-pricing-table-dark', 'name' => 'Pricing Card Dark', 'category' => 'cards', 'description' => 'Dark pricing tier card', 'keywords' => ['card', 'pricing', 'dark']],
            ['slug' => 'ollie/card-testimonial', 'name' => 'Testimonial Card', 'category' => 'cards', 'description' => 'Single testimonial card', 'keywords' => ['card', 'testimonial', 'quote']],
            ['slug' => 'ollie/card-call-to-action', 'name' => 'CTA Card', 'category' => 'cards', 'description' => 'Call to action card', 'keywords' => ['card', 'cta', 'action']],
            ['slug' => 'ollie/card-call-to-action-with-buttons', 'name' => 'CTA Card Buttons', 'category' => 'cards', 'description' => 'CTA card with multiple buttons', 'keywords' => ['card', 'cta', 'buttons']],
            ['slug' => 'ollie/card-contact', 'name' => 'Contact Card', 'category' => 'cards', 'description' => 'Contact information card', 'keywords' => ['card', 'contact', 'info']],
            ['slug' => 'ollie/card-blog-post', 'name' => 'Blog Post Card', 'category' => 'cards', 'description' => 'Blog post preview card', 'keywords' => ['card', 'blog', 'post']],
            ['slug' => 'ollie/card-image-and-text', 'name' => 'Image Text Card', 'category' => 'cards', 'description' => 'Image with text card', 'keywords' => ['card', 'image', 'text']],
            ['slug' => 'ollie/card-lead-magnet', 'name' => 'Lead Magnet Card', 'category' => 'cards', 'description' => 'Lead capture card', 'keywords' => ['card', 'lead', 'capture', 'email']],
            ['slug' => 'ollie/card-social-profile', 'name' => 'Social Profile Card', 'category' => 'cards', 'description' => 'Social profile card', 'keywords' => ['card', 'social', 'profile']],
            ['slug' => 'ollie/card-details', 'name' => 'Details Card', 'category' => 'cards', 'description' => 'Details/specs card', 'keywords' => ['card', 'details', 'specs']],
            ['slug' => 'ollie/card-text-box-with-link', 'name' => 'Text Box Link Card', 'category' => 'cards', 'description' => 'Text box with link', 'keywords' => ['card', 'text', 'link']],

            // Features
            ['slug' => 'ollie/feature-boxes-with-button', 'name' => 'Feature Boxes Button', 'category' => 'features', 'description' => 'Feature grid with CTA button', 'keywords' => ['features', 'grid', 'button']],
            ['slug' => 'ollie/feature-boxes-with-icon-dark', 'name' => 'Feature Boxes Icons Dark', 'category' => 'features', 'description' => 'Dark feature boxes with icons', 'keywords' => ['features', 'icons', 'dark']],
            ['slug' => 'ollie/features-with-emojis', 'name' => 'Features Emojis', 'category' => 'features', 'description' => 'Playful feature list with emojis', 'keywords' => ['features', 'emojis', 'fun']],
            ['slug' => 'ollie/image-and-numbered-features', 'name' => 'Numbered Features', 'category' => 'features', 'description' => 'Numbered feature list with image', 'keywords' => ['features', 'numbered', 'steps']],

            // Testimonials
            ['slug' => 'ollie/testimonials-and-logos', 'name' => 'Testimonials Logos', 'category' => 'testimonials', 'description' => 'Testimonials with client logos', 'keywords' => ['testimonials', 'logos', 'social proof']],
            ['slug' => 'ollie/testimonial-highlight', 'name' => 'Testimonial Highlight', 'category' => 'testimonials', 'description' => 'Single featured testimonial', 'keywords' => ['testimonial', 'featured', 'highlight']],
            ['slug' => 'ollie/testimonials-with-big-text', 'name' => 'Testimonials Big Text', 'category' => 'testimonials', 'description' => 'Large quote testimonials', 'keywords' => ['testimonials', 'big', 'quote']],
            ['slug' => 'ollie/testimonials-with-social-links', 'name' => 'Testimonials Social', 'category' => 'testimonials', 'description' => 'Testimonials with social links', 'keywords' => ['testimonials', 'social', 'links']],
            ['slug' => 'ollie/single-testimonial', 'name' => 'Single Testimonial', 'category' => 'testimonials', 'description' => 'Individual testimonial block', 'keywords' => ['testimonial', 'single', 'quote']],

            // Pricing
            ['slug' => 'ollie/pricing-table', 'name' => 'Pricing Table', 'category' => 'pricing', 'description' => 'Standard pricing comparison', 'keywords' => ['pricing', 'table', 'comparison']],
            ['slug' => 'ollie/pricing-table-3-column', 'name' => 'Pricing 3 Column', 'category' => 'pricing', 'description' => 'Three-tier pricing table', 'keywords' => ['pricing', 'three', 'tiers']],
            ['slug' => 'ollie/pricing-table-with-testimonials', 'name' => 'Pricing Testimonials', 'category' => 'pricing', 'description' => 'Pricing with social proof', 'keywords' => ['pricing', 'testimonials', 'trust']],

            // CTAs
            ['slug' => 'ollie/text-call-to-action', 'name' => 'Text CTA', 'category' => 'ctas', 'description' => 'Simple text call to action', 'keywords' => ['cta', 'text', 'simple']],
            ['slug' => 'ollie/text-call-to-action-buttons', 'name' => 'Text CTA Buttons', 'category' => 'ctas', 'description' => 'CTA with button options', 'keywords' => ['cta', 'buttons', 'action']],
            ['slug' => 'ollie/card-big-text-call-to-action', 'name' => 'Big Text CTA', 'category' => 'ctas', 'description' => 'Bold text CTA section', 'keywords' => ['cta', 'big', 'bold']],

            // Full Pages
            ['slug' => 'ollie/page-home', 'name' => 'Homepage', 'category' => 'pages', 'description' => 'Complete homepage layout', 'keywords' => ['page', 'home', 'complete']],
            ['slug' => 'ollie/page-about', 'name' => 'About Page', 'category' => 'pages', 'description' => 'About page layout', 'keywords' => ['page', 'about', 'company']],
            ['slug' => 'ollie/page-pricing', 'name' => 'Pricing Page', 'category' => 'pages', 'description' => 'Pricing page layout', 'keywords' => ['page', 'pricing', 'plans']],
            ['slug' => 'ollie/page-features', 'name' => 'Features Page', 'category' => 'pages', 'description' => 'Features page layout', 'keywords' => ['page', 'features', 'product']],
            ['slug' => 'ollie/page-blog', 'name' => 'Blog Page', 'category' => 'pages', 'description' => 'Blog listing layout', 'keywords' => ['page', 'blog', 'posts']],
            ['slug' => 'ollie/page-download', 'name' => 'Download Page', 'category' => 'pages', 'description' => 'Download/product page', 'keywords' => ['page', 'download', 'product']],
            ['slug' => 'ollie/page-profile', 'name' => 'Profile Page', 'category' => 'pages', 'description' => 'Profile/bio page', 'keywords' => ['page', 'profile', 'bio']],

            // Blog
            ['slug' => 'ollie/blog-post-columns', 'name' => 'Blog Post Columns', 'category' => 'blog', 'description' => 'Blog posts in columns', 'keywords' => ['blog', 'columns', 'grid']],
            ['slug' => 'ollie/blog-post-columns-single', 'name' => 'Blog Single Column', 'category' => 'blog', 'description' => 'Single column blog layout', 'keywords' => ['blog', 'single', 'column']],
            ['slug' => 'ollie/post-loop-grid-default', 'name' => 'Post Loop Grid', 'category' => 'blog', 'description' => 'Default post grid loop', 'keywords' => ['blog', 'loop', 'grid']],
            ['slug' => 'ollie/post-loop-grid-custom', 'name' => 'Post Loop Custom', 'category' => 'blog', 'description' => 'Custom post grid loop', 'keywords' => ['blog', 'loop', 'custom']],
            ['slug' => 'ollie/post-loop-list', 'name' => 'Post Loop List', 'category' => 'blog', 'description' => 'Post list loop', 'keywords' => ['blog', 'loop', 'list']],

            // Other
            ['slug' => 'ollie/team-members', 'name' => 'Team Members', 'category' => 'cards', 'description' => 'Team member grid', 'keywords' => ['team', 'members', 'people']],
            ['slug' => 'ollie/faq', 'name' => 'FAQ', 'category' => 'cards', 'description' => 'FAQ accordion section', 'keywords' => ['faq', 'questions', 'accordion']],
            ['slug' => 'ollie/numbers', 'name' => 'Numbers', 'category' => 'features', 'description' => 'Statistics/numbers display', 'keywords' => ['numbers', 'stats', 'metrics']],
            ['slug' => 'ollie/numbers-stacked', 'name' => 'Numbers Stacked', 'category' => 'features', 'description' => 'Stacked statistics display', 'keywords' => ['numbers', 'stacked', 'stats']],
            ['slug' => 'ollie/contact-details', 'name' => 'Contact Details', 'category' => 'cards', 'description' => 'Contact information section', 'keywords' => ['contact', 'details', 'info']],
            ['slug' => 'ollie/job-openings', 'name' => 'Job Openings', 'category' => 'cards', 'description' => 'Job listing section', 'keywords' => ['jobs', 'careers', 'openings']],
            ['slug' => 'ollie/author-box', 'name' => 'Author Box', 'category' => 'blog', 'description' => 'Author bio box', 'keywords' => ['author', 'bio', 'blog']],
        ];
    }
}
