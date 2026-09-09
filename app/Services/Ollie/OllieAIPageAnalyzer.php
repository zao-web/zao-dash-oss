<?php

namespace App\Services\Ollie;

use App\Services\AI\AnthropicService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllieAIPageAnalyzer
{
    private AnthropicService $anthropic;

    private array $olliePatterns;

    public function __construct()
    {
        $this->anthropic = new AnthropicService;
        $this->olliePatterns = $this->getOlliePatternCatalog();
    }

    public function analyzePage(string $url, array $options = []): array
    {
        $cacheKey = 'ollie_ai_analysis:'.md5($url);

        if (! ($options['force'] ?? false)) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }
        }

        try {
            $html = $this->fetchPage($url);
            if (! $html) {
                return ['success' => false, 'error' => 'Failed to fetch page'];
            }

            $cleanHtml = $this->prepareHtmlForAnalysis($html);
            $analysis = $this->analyzeWithAI($cleanHtml, $url, $options);

            if ($analysis['success']) {
                Cache::put($cacheKey, $analysis, now()->addHours(24));
            }

            return $analysis;
        } catch (\Exception $e) {
            Log::error('OllieAIPageAnalyzer failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function analyzeWithAI(string $html, string $url, array $options = []): array
    {
        $prompt = $this->buildAnalysisPrompt($html, $url);
        $systemPrompt = $this->getSystemPrompt();

        try {
            $response = $this->anthropic->message(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                model: 'claude-sonnet-4-20250514',
                maxTokens: 8192  // Page analysis needs large output for detailed JSON
            );

            $content = $response['content'][0]['text'] ?? '';
            $parsed = $this->parseAIResponse($content);

            if (! $parsed) {
                return ['success' => false, 'error' => 'Failed to parse AI response'];
            }

            return [
                'success' => true,
                'url' => $url,
                'sections' => $parsed['sections'] ?? [],
                'page_summary' => $parsed['page_summary'] ?? '',
                'migration_notes' => $parsed['migration_notes'] ?? '',
                'suggested_page_template' => $parsed['suggested_page_template'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('AI analysis failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'AI analysis failed: '.$e->getMessage()];
        }
    }

    private function getSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are an expert web designer and WordPress developer specializing in site migrations to the Ollie WordPress theme. You have deep expertise in:

1. **Visual Design**: Understanding layout, hierarchy, whitespace, and visual intent from HTML structure
2. **Content Strategy**: Identifying the PURPOSE of each section (conversion, trust-building, education, etc.)
3. **WordPress Block Patterns**: Deep knowledge of Ollie's 60+ patterns and when to use each
4. **Migration Best Practices**: Preserving content fidelity while improving design

## Your Mission
Analyze webpage HTML to:
1. Identify ALL distinct content sections (header, hero, features, testimonials, CTA, footer, etc.)
2. Extract EVERY piece of meaningful content from each section
3. Map each section to the BEST Ollie pattern with reasoning
4. Provide migration-ready structured data

## Pattern Selection Philosophy
- **Hero sections**: Match based on presence of CTA buttons, background images, logo displays
- **Features**: Look for grid/list layouts with icons, numbers, or emojis
- **Testimonials**: Identify quotes, author info, company logos, star ratings
- **Pricing**: Detect tier comparisons, feature lists, CTA buttons per plan
- **CTAs**: Simple conversion blocks with headlines and buttons
- **Stats/Numbers**: Metrics, counters, achievement displays
- **Team**: People grids with photos, names, roles
- **Blog**: Post previews, excerpts, thumbnails
- **Contact**: Forms, addresses, phone numbers, maps
- **FAQ**: Question/answer pairs, accordions

## Critical Rules
1. Extract ACTUAL content from HTML - never use placeholders
2. Resolve relative URLs to absolute URLs
3. Include ALL images with their src and alt text
4. Capture button text AND href attributes
5. Identify nested sections within containers
6. Order sections as they appear visually (top to bottom)
7. Flag any content that doesn't fit standard patterns as "custom"
8. Return ONLY valid JSON - no markdown, no explanations outside JSON
SYSTEM;
    }

    private function buildAnalysisPrompt(string $html, string $url): string
    {
        $patternCatalog = json_encode($this->olliePatterns, JSON_PRETTY_PRINT);

        return <<<PROMPT
Analyze this webpage and identify all content sections for migration to WordPress using the Ollie theme.

URL: {$url}

## Available Ollie Patterns:
{$patternCatalog}

## Page HTML:
```html
{$html}
```

## Your Task:
Analyze the page and return a JSON object with this exact structure:

```json
{
  "page_summary": "Brief description of what this page is about",
  "suggested_page_template": "ollie/page-home or ollie/page-about etc",
  "migration_notes": "Any important notes for the migration",
  "sections": [
    {
      "order": 1,
      "type": "hero|features|testimonials|pricing|cta|team|blog|contact|gallery|faq|content|stats|logos",
      "confidence": 0.95,
      "detected_from": "Brief explanation of why you identified this section",
      "suggested_pattern": "ollie/pattern-slug",
      "pattern_reasoning": "Why this pattern is the best fit",
      "content": {
        // Extracted content varies by type - see examples below
      }
    }
  ]
}
```

## Content Structure by Section Type:

**hero**: { "headline": "", "subheadline": "", "body": "", "buttons": [{"text": "", "url": "", "style": "primary|secondary"}], "background_image": "", "featured_image": "" }

**features**: { "section_title": "", "section_intro": "", "features": [{"icon": "", "title": "", "description": "", "link": {"text": "", "url": ""}}] }

**testimonials**: { "section_title": "", "testimonials": [{"quote": "", "author": "", "role": "", "company": "", "avatar": "", "rating": 5}] }

**pricing**: { "section_title": "", "plans": [{"name": "", "price": "", "currency": "$", "period": "mo", "description": "", "features": [""], "cta": {"text": "", "url": ""}, "featured": false}] }

**cta**: { "headline": "", "body": "", "buttons": [{"text": "", "url": "", "style": "primary|secondary"}], "background_color": "" }

**team**: { "section_title": "", "section_intro": "", "members": [{"name": "", "role": "", "bio": "", "photo": "", "social": {"twitter": "", "linkedin": ""}}] }

**blog**: { "section_title": "", "posts": [{"title": "", "excerpt": "", "image": "", "url": "", "date": "", "author": ""}] }

**contact**: { "section_title": "", "intro": "", "email": "", "phone": "", "address": "", "has_form": true, "form_fields": ["name", "email", "message"], "social_links": {} }

**gallery**: { "section_title": "", "images": [{"url": "", "alt": "", "caption": ""}] }

**faq**: { "section_title": "", "faqs": [{"question": "", "answer": ""}] }

**stats/numbers**: { "section_title": "", "stats": [{"value": "", "label": "", "prefix": "", "suffix": ""}] }

**logos**: { "section_title": "", "logos": [{"image": "", "alt": "", "url": ""}] }

**content**: { "heading": "", "body": "", "images": [] } // Generic content block

IMPORTANT:
- Extract ACTUAL content from the HTML, not placeholders
- Resolve relative image URLs to absolute URLs using the base URL
- Identify ALL sections, even small ones
- Order sections as they appear on the page
- If you're uncertain about a section type, use "content" as fallback
- Return ONLY the JSON, no markdown code blocks or explanations
PROMPT;
    }

    private function parseAIResponse(string $response): ?array
    {
        $response = trim($response);

        if (str_starts_with($response, '```json')) {
            $response = preg_replace('/^```json\s*/', '', $response);
            $response = preg_replace('/\s*```$/', '', $response);
        } elseif (str_starts_with($response, '```')) {
            $response = preg_replace('/^```\s*/', '', $response);
            $response = preg_replace('/\s*```$/', '', $response);
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to parse AI response as JSON', [
                'error' => json_last_error_msg(),
                'response_preview' => substr($response, 0, 500),
            ]);

            return null;
        }

        return $decoded;
    }

    private function fetchPage(string $url): ?string
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Exception $e) {
            Log::error('Failed to fetch page', ['url' => $url, 'error' => $e->getMessage()]);
        }

        return null;
    }

    private function prepareHtmlForAnalysis(string $html): string
    {
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/<svg[^>]*>.*?<\/svg>/is', '[SVG ICON]', $html);
        $html = preg_replace('/\s+/', ' ', $html);

        if (strlen($html) > 100000) {
            if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $html, $match)) {
                $html = $match[0];
            } elseif (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $match)) {
                $html = $match[0];
            }
        }

        if (strlen($html) > 80000) {
            $html = substr($html, 0, 80000)."\n<!-- TRUNCATED -->";
        }

        return trim($html);
    }

    private function getOlliePatternCatalog(): array
    {
        return [
            'heroes' => [
                [
                    'slug' => 'ollie/hero-dark',
                    'name' => 'Hero Dark',
                    'best_for' => 'Bold statements with dark overlay, dramatic impact, full-width background images',
                    'content_fields' => ['headline', 'subheadline', 'body', 'buttons', 'background_image'],
                    'signals' => ['dark background', 'overlay image', 'large headline', 'centered text'],
                ],
                [
                    'slug' => 'ollie/hero-light',
                    'name' => 'Hero Light',
                    'best_for' => 'Clean, professional look, corporate sites, white/light backgrounds',
                    'content_fields' => ['headline', 'subheadline', 'body', 'buttons', 'featured_image'],
                    'signals' => ['light background', 'professional', 'corporate', 'clean design'],
                ],
                [
                    'slug' => 'ollie/hero-call-to-action-buttons',
                    'name' => 'Hero CTA Buttons',
                    'best_for' => 'Conversion-focused with dual CTAs, primary + secondary buttons',
                    'content_fields' => ['headline', 'subheadline', 'primary_button', 'secondary_button'],
                    'signals' => ['two buttons', 'primary/secondary CTA', 'conversion focused'],
                ],
                [
                    'slug' => 'ollie/hero-call-to-action-buttons-light',
                    'name' => 'Hero CTA Buttons Light',
                    'best_for' => 'Light version of CTA hero, clean with dual buttons',
                    'content_fields' => ['headline', 'subheadline', 'primary_button', 'secondary_button'],
                    'signals' => ['light background', 'two buttons', 'clean design'],
                ],
                [
                    'slug' => 'ollie/hero-text-image-and-logos',
                    'name' => 'Hero with Logos',
                    'best_for' => 'Social proof with client/partner logos below hero content',
                    'content_fields' => ['headline', 'subheadline', 'buttons', 'featured_image', 'logos'],
                    'signals' => ['partner logos', 'client logos', 'trust badges', 'as seen in'],
                ],
            ],
            'headers' => [
                [
                    'slug' => 'ollie/header-dark',
                    'name' => 'Header Dark',
                    'best_for' => 'Dark navigation bar, modern sites',
                    'content_fields' => ['logo', 'nav_items', 'cta_button'],
                    'signals' => ['dark nav', 'main navigation'],
                ],
                [
                    'slug' => 'ollie/header-light',
                    'name' => 'Header Light',
                    'best_for' => 'Light navigation bar, clean sites',
                    'content_fields' => ['logo', 'nav_items', 'cta_button'],
                    'signals' => ['light nav', 'white header'],
                ],
                [
                    'slug' => 'ollie/header-dark-with-banner',
                    'name' => 'Header Dark Banner',
                    'best_for' => 'Dark header with announcement banner above',
                    'content_fields' => ['banner_text', 'banner_link', 'logo', 'nav_items'],
                    'signals' => ['announcement', 'promo banner', 'sale notice'],
                ],
                [
                    'slug' => 'ollie/header-light-with-banner',
                    'name' => 'Header Light Banner',
                    'best_for' => 'Light header with announcement banner',
                    'content_fields' => ['banner_text', 'banner_link', 'logo', 'nav_items'],
                    'signals' => ['announcement', 'light theme', 'promo'],
                ],
                [
                    'slug' => 'ollie/header-dark-with-buttons',
                    'name' => 'Header Dark Buttons',
                    'best_for' => 'Dark header with prominent CTA buttons',
                    'content_fields' => ['logo', 'nav_items', 'primary_button', 'secondary_button'],
                    'signals' => ['login/signup', 'multiple CTAs in nav'],
                ],
                [
                    'slug' => 'ollie/header-light-with-buttons',
                    'name' => 'Header Light Buttons',
                    'best_for' => 'Light header with CTA buttons',
                    'content_fields' => ['logo', 'nav_items', 'primary_button', 'secondary_button'],
                    'signals' => ['clean nav', 'action buttons'],
                ],
            ],
            'footers' => [
                [
                    'slug' => 'ollie/footer-dark',
                    'name' => 'Footer Dark',
                    'best_for' => 'Full dark footer with multiple columns of links',
                    'content_fields' => ['logo', 'description', 'link_columns', 'social_links', 'copyright'],
                    'signals' => ['dark footer', 'multi-column', 'sitemap links'],
                ],
                [
                    'slug' => 'ollie/footer-light',
                    'name' => 'Footer Light',
                    'best_for' => 'Full light footer with columns',
                    'content_fields' => ['logo', 'description', 'link_columns', 'social_links', 'copyright'],
                    'signals' => ['light footer', 'columns'],
                ],
                [
                    'slug' => 'ollie/footer-dark-centered',
                    'name' => 'Footer Dark Centered',
                    'best_for' => 'Centered dark footer, simpler layout',
                    'content_fields' => ['logo', 'nav_links', 'social_links', 'copyright'],
                    'signals' => ['centered footer', 'minimal'],
                ],
                [
                    'slug' => 'ollie/footer-light-centered',
                    'name' => 'Footer Light Centered',
                    'best_for' => 'Centered light footer',
                    'content_fields' => ['logo', 'nav_links', 'social_links', 'copyright'],
                    'signals' => ['simple footer', 'centered'],
                ],
                [
                    'slug' => 'ollie/footer-dark-minimal',
                    'name' => 'Footer Dark Minimal',
                    'best_for' => 'Minimal dark footer, just essentials',
                    'content_fields' => ['copyright', 'links'],
                    'signals' => ['minimal', 'copyright only'],
                ],
                [
                    'slug' => 'ollie/footer-light-minimal',
                    'name' => 'Footer Light Minimal',
                    'best_for' => 'Minimal light footer',
                    'content_fields' => ['copyright', 'links'],
                    'signals' => ['simple', 'minimal'],
                ],
            ],
            'features' => [
                [
                    'slug' => 'ollie/feature-boxes-with-button',
                    'name' => 'Feature Boxes',
                    'best_for' => 'Service/feature grid with section CTA button, 3-4 items',
                    'content_fields' => ['section_title', 'section_intro', 'features', 'cta_button'],
                    'signals' => ['3-4 boxes', 'service grid', 'icon boxes', 'what we do'],
                ],
                [
                    'slug' => 'ollie/feature-boxes-with-icon-dark',
                    'name' => 'Feature Icons Dark',
                    'best_for' => 'Icon-based features on dark background, visual impact',
                    'content_fields' => ['section_title', 'features_with_icons'],
                    'signals' => ['dark section', 'icons', 'capabilities'],
                ],
                [
                    'slug' => 'ollie/features-with-emojis',
                    'name' => 'Features Emojis',
                    'best_for' => 'Playful, approachable feature list with emoji icons',
                    'content_fields' => ['section_title', 'features_with_emojis'],
                    'signals' => ['emojis', 'fun', 'casual', 'startup'],
                ],
                [
                    'slug' => 'ollie/image-and-numbered-features',
                    'name' => 'Numbered Features',
                    'best_for' => 'Step-by-step process or numbered feature list with side image',
                    'content_fields' => ['section_title', 'image', 'numbered_features'],
                    'signals' => ['steps', 'process', 'how it works', '1, 2, 3'],
                ],
            ],
            'testimonials' => [
                [
                    'slug' => 'ollie/testimonials-and-logos',
                    'name' => 'Testimonials + Logos',
                    'best_for' => 'Multiple testimonials with company logos for social proof',
                    'content_fields' => ['section_title', 'testimonials', 'company_logos'],
                    'signals' => ['client logos', 'multiple quotes', 'trusted by'],
                ],
                [
                    'slug' => 'ollie/testimonial-highlight',
                    'name' => 'Single Testimonial',
                    'best_for' => 'Featured single testimonial for maximum impact',
                    'content_fields' => ['quote', 'author_name', 'author_role', 'author_photo', 'company'],
                    'signals' => ['large quote', 'featured review', 'hero testimonial'],
                ],
                [
                    'slug' => 'ollie/testimonials-with-big-text',
                    'name' => 'Big Quote',
                    'best_for' => 'Large, impactful quote display, dramatic effect',
                    'content_fields' => ['quote', 'author_name', 'author_role'],
                    'signals' => ['big typography', 'statement', 'pull quote'],
                ],
                [
                    'slug' => 'ollie/testimonials-with-social-links',
                    'name' => 'Testimonials Social',
                    'best_for' => 'Testimonials with social media links to authors',
                    'content_fields' => ['testimonials_with_social'],
                    'signals' => ['twitter handles', 'social proof', 'real people'],
                ],
                [
                    'slug' => 'ollie/single-testimonial',
                    'name' => 'Testimonial Card',
                    'best_for' => 'Individual testimonial block/card',
                    'content_fields' => ['quote', 'author_name', 'author_role', 'author_photo'],
                    'signals' => ['card layout', 'single review'],
                ],
            ],
            'pricing' => [
                [
                    'slug' => 'ollie/pricing-table',
                    'name' => 'Pricing Table',
                    'best_for' => '2-column pricing comparison, side by side plans',
                    'content_fields' => ['section_title', 'plans'],
                    'signals' => ['two plans', 'pricing tiers', 'compare'],
                ],
                [
                    'slug' => 'ollie/pricing-table-3-column',
                    'name' => 'Pricing 3 Column',
                    'best_for' => 'Three-tier pricing (good/better/best), highlighted middle',
                    'content_fields' => ['section_title', 'plans'],
                    'signals' => ['three plans', 'recommended plan', 'popular choice'],
                ],
                [
                    'slug' => 'ollie/pricing-table-with-testimonials',
                    'name' => 'Pricing + Social Proof',
                    'best_for' => 'Pricing with testimonial reinforcement below',
                    'content_fields' => ['section_title', 'plans', 'testimonials'],
                    'signals' => ['pricing + reviews', 'trust signals'],
                ],
            ],
            'ctas' => [
                [
                    'slug' => 'ollie/text-call-to-action',
                    'name' => 'Simple CTA',
                    'best_for' => 'Minimal text-based call to action, single button',
                    'content_fields' => ['headline', 'body', 'button'],
                    'signals' => ['simple CTA', 'one button', 'get started'],
                ],
                [
                    'slug' => 'ollie/text-call-to-action-buttons',
                    'name' => 'CTA with Buttons',
                    'best_for' => 'CTA section with primary + secondary button options',
                    'content_fields' => ['headline', 'body', 'primary_button', 'secondary_button'],
                    'signals' => ['two buttons', 'try free / learn more'],
                ],
                [
                    'slug' => 'ollie/card-big-text-call-to-action',
                    'name' => 'Bold CTA',
                    'best_for' => 'Large, attention-grabbing CTA block with bold typography',
                    'content_fields' => ['headline', 'body', 'button'],
                    'signals' => ['big text', 'dramatic', 'final CTA'],
                ],
            ],
            'cards' => [
                [
                    'slug' => 'ollie/card-pricing-table',
                    'name' => 'Pricing Card',
                    'best_for' => 'Single pricing tier card',
                    'content_fields' => ['plan_name', 'price', 'period', 'features', 'cta_button'],
                    'signals' => ['individual plan', 'price card'],
                ],
                [
                    'slug' => 'ollie/card-testimonial',
                    'name' => 'Testimonial Card',
                    'best_for' => 'Single testimonial in card format',
                    'content_fields' => ['quote', 'author_name', 'author_role', 'author_photo'],
                    'signals' => ['quote card', 'review card'],
                ],
                [
                    'slug' => 'ollie/card-call-to-action',
                    'name' => 'CTA Card',
                    'best_for' => 'Call to action in card format',
                    'content_fields' => ['headline', 'body', 'button'],
                    'signals' => ['action card', 'signup card'],
                ],
                [
                    'slug' => 'ollie/card-contact',
                    'name' => 'Contact Card',
                    'best_for' => 'Compact contact information card',
                    'content_fields' => ['title', 'email', 'phone', 'address'],
                    'signals' => ['contact info', 'get in touch'],
                ],
                [
                    'slug' => 'ollie/card-blog-post',
                    'name' => 'Blog Post Card',
                    'best_for' => 'Blog post preview card with image',
                    'content_fields' => ['title', 'excerpt', 'image', 'date', 'author', 'url'],
                    'signals' => ['post preview', 'article card'],
                ],
                [
                    'slug' => 'ollie/card-image-and-text',
                    'name' => 'Image Text Card',
                    'best_for' => 'Generic image with text card',
                    'content_fields' => ['image', 'title', 'description', 'link'],
                    'signals' => ['image card', 'media card'],
                ],
                [
                    'slug' => 'ollie/card-lead-magnet',
                    'name' => 'Lead Magnet Card',
                    'best_for' => 'Lead capture card with email form',
                    'content_fields' => ['title', 'description', 'form_fields', 'button'],
                    'signals' => ['download', 'free guide', 'newsletter'],
                ],
                [
                    'slug' => 'ollie/card-social-profile',
                    'name' => 'Social Profile Card',
                    'best_for' => 'Social media profile card',
                    'content_fields' => ['name', 'handle', 'photo', 'social_links'],
                    'signals' => ['profile card', 'social card'],
                ],
            ],
            'team' => [
                [
                    'slug' => 'ollie/team-members',
                    'name' => 'Team Grid',
                    'best_for' => 'Team member cards with photos, names, roles, bios',
                    'content_fields' => ['section_title', 'section_intro', 'members'],
                    'signals' => ['team photos', 'our team', 'meet the team', 'people grid'],
                ],
            ],
            'blog' => [
                [
                    'slug' => 'ollie/blog-post-columns',
                    'name' => 'Blog Columns',
                    'best_for' => 'Multi-column blog post grid, 2-3 columns',
                    'content_fields' => ['section_title', 'posts'],
                    'signals' => ['blog grid', 'latest posts', 'news'],
                ],
                [
                    'slug' => 'ollie/blog-post-columns-single',
                    'name' => 'Blog Single Column',
                    'best_for' => 'Single column blog layout, list format',
                    'content_fields' => ['section_title', 'posts'],
                    'signals' => ['blog list', 'article list'],
                ],
                [
                    'slug' => 'ollie/post-loop-grid-default',
                    'name' => 'Post Grid',
                    'best_for' => 'Dynamic WordPress post loop grid',
                    'content_fields' => ['section_title', 'posts_per_page', 'category'],
                    'signals' => ['dynamic posts', 'latest news'],
                ],
                [
                    'slug' => 'ollie/post-loop-grid-custom',
                    'name' => 'Post Loop Custom',
                    'best_for' => 'Custom styled post grid loop',
                    'content_fields' => ['section_title', 'posts_per_page', 'category'],
                    'signals' => ['custom grid', 'featured posts'],
                ],
                [
                    'slug' => 'ollie/post-loop-list',
                    'name' => 'Post Loop List',
                    'best_for' => 'Post list format loop',
                    'content_fields' => ['section_title', 'posts_per_page'],
                    'signals' => ['list layout', 'recent posts'],
                ],
                [
                    'slug' => 'ollie/author-box',
                    'name' => 'Author Box',
                    'best_for' => 'Author bio box for blog posts',
                    'content_fields' => ['name', 'bio', 'photo', 'social_links'],
                    'signals' => ['author info', 'written by'],
                ],
            ],
            'contact' => [
                [
                    'slug' => 'ollie/contact-details',
                    'name' => 'Contact Details',
                    'best_for' => 'Contact information section with address, phone, email',
                    'content_fields' => ['section_title', 'intro', 'email', 'phone', 'address', 'hours'],
                    'signals' => ['contact us', 'get in touch', 'location'],
                ],
                [
                    'slug' => 'ollie/card-contact',
                    'name' => 'Contact Card',
                    'best_for' => 'Compact contact info block',
                    'content_fields' => ['email', 'phone', 'address'],
                    'signals' => ['contact card', 'info card'],
                ],
            ],
            'faq' => [
                [
                    'slug' => 'ollie/faq',
                    'name' => 'FAQ Accordion',
                    'best_for' => 'Expandable FAQ section with questions and answers',
                    'content_fields' => ['section_title', 'faqs'],
                    'signals' => ['questions', 'FAQ', 'help', 'accordion'],
                ],
            ],
            'stats' => [
                [
                    'slug' => 'ollie/numbers',
                    'name' => 'Stats/Numbers',
                    'best_for' => 'Key metrics and statistics display in row',
                    'content_fields' => ['stats'],
                    'signals' => ['numbers', 'metrics', 'achievements', 'by the numbers'],
                ],
                [
                    'slug' => 'ollie/numbers-stacked',
                    'name' => 'Stacked Stats',
                    'best_for' => 'Vertical/stacked statistics layout',
                    'content_fields' => ['stats'],
                    'signals' => ['vertical stats', 'stacked numbers'],
                ],
            ],
            'logos' => [
                [
                    'slug' => 'ollie/logo-cloud',
                    'name' => 'Logo Cloud',
                    'best_for' => 'Client/partner logo display row, social proof',
                    'content_fields' => ['section_title', 'logos'],
                    'signals' => ['partner logos', 'trusted by', 'as seen in', 'clients'],
                ],
            ],
            'jobs' => [
                [
                    'slug' => 'ollie/job-openings',
                    'name' => 'Job Openings',
                    'best_for' => 'Job/career listing section',
                    'content_fields' => ['section_title', 'jobs'],
                    'signals' => ['careers', 'jobs', 'we are hiring', 'openings'],
                ],
            ],
            'pages' => [
                [
                    'slug' => 'ollie/page-home',
                    'name' => 'Homepage',
                    'best_for' => 'Complete homepage layout template',
                    'content_fields' => ['full_page_sections'],
                    'signals' => ['homepage', 'landing page'],
                ],
                [
                    'slug' => 'ollie/page-about',
                    'name' => 'About Page',
                    'best_for' => 'About us page layout template',
                    'content_fields' => ['full_page_sections'],
                    'signals' => ['about us', 'our story'],
                ],
                [
                    'slug' => 'ollie/page-pricing',
                    'name' => 'Pricing Page',
                    'best_for' => 'Pricing page layout template',
                    'content_fields' => ['full_page_sections'],
                    'signals' => ['pricing', 'plans'],
                ],
                [
                    'slug' => 'ollie/page-features',
                    'name' => 'Features Page',
                    'best_for' => 'Features/product page layout template',
                    'content_fields' => ['full_page_sections'],
                    'signals' => ['features', 'product'],
                ],
                [
                    'slug' => 'ollie/page-blog',
                    'name' => 'Blog Page',
                    'best_for' => 'Blog listing page layout',
                    'content_fields' => ['full_page_sections'],
                    'signals' => ['blog', 'news', 'articles'],
                ],
                [
                    'slug' => 'ollie/page-download',
                    'name' => 'Download Page',
                    'best_for' => 'Download/product landing page',
                    'content_fields' => ['full_page_sections'],
                    'signals' => ['download', 'get app'],
                ],
                [
                    'slug' => 'ollie/page-profile',
                    'name' => 'Profile Page',
                    'best_for' => 'Profile/bio page for individuals',
                    'content_fields' => ['full_page_sections'],
                    'signals' => ['profile', 'bio', 'personal'],
                ],
            ],
        ];
    }

    public function generateBlockMarkup(array $section, array $colors = []): string
    {
        $generator = new OllieBlockGenerator;
        if (! empty($colors)) {
            $generator->setColors($colors);
        }

        return $generator->generateFromSection($section);
    }

    public function analyzeMultiplePages(array $pages, string $baseUrl): array
    {
        $results = [];

        foreach ($pages as $page) {
            $url = $page['url'] ?? ($baseUrl.($page['path'] ?? '/'));
            $name = $page['name'] ?? $this->inferPageName($url);

            $analysis = $this->analyzePage($url);
            $results[$name] = $analysis;
        }

        return $results;
    }

    private function inferPageName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        if ($path === '/' || $path === '') {
            return 'home';
        }
        $path = trim($path, '/');
        $segments = explode('/', $path);

        return strtolower(str_replace(['-', '_'], ' ', end($segments))) ?: 'page';
    }
}
