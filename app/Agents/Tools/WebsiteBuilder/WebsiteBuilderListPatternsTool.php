<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;

class WebsiteBuilderListPatternsTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
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
                    'enum' => ['heroes', 'headers', 'footers', 'cards', 'features', 'testimonials', 'pricing', 'ctas', 'pages', 'blog', 'all'],
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

        if ($category !== 'all') {
            $patterns = array_filter($patterns, fn ($p) => $p['category'] === $category);
        }

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
            // ============ HEROES ============
            [
                'slug' => 'ollie/hero-dark',
                'name' => 'Hero Dark',
                'category' => 'heroes',
                'description' => 'Dark hero with overlay image and bold statement. Great for impactful first impressions.',
                'keywords' => ['hero', 'dark', 'cta', 'homepage'],
                'best_for' => ['homepage', 'landing pages', 'agencies', 'creative businesses'],
                'pairs_well_with' => ['feature-boxes-with-icon-dark', 'testimonials-and-logos', 'footer-dark'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/hero-dark"} /-->',
            ],
            [
                'slug' => 'ollie/hero-light',
                'name' => 'Hero Light',
                'category' => 'heroes',
                'description' => 'Light, clean hero for professional sites. Ideal for corporate or healthcare.',
                'keywords' => ['hero', 'light', 'clean', 'professional'],
                'best_for' => ['corporate', 'healthcare', 'professional services', 'consulting'],
                'pairs_well_with' => ['feature-boxes-with-button', 'testimonial-highlight', 'footer-light'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/hero-light"} /-->',
            ],
            [
                'slug' => 'ollie/hero-call-to-action-buttons',
                'name' => 'Hero CTA Buttons',
                'category' => 'heroes',
                'description' => 'CTA-focused hero with dual buttons (primary + secondary). Maximum conversion focus.',
                'keywords' => ['hero', 'cta', 'buttons', 'conversion'],
                'best_for' => ['saas', 'software', 'startups', 'product launches'],
                'pairs_well_with' => ['image-and-numbered-features', 'pricing-table', 'testimonials-with-big-text'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/hero-call-to-action-buttons"} /-->',
            ],
            [
                'slug' => 'ollie/hero-text-image-and-logos',
                'name' => 'Hero Text Image Logos',
                'category' => 'heroes',
                'description' => 'Hero with social proof logos below. Builds trust immediately.',
                'keywords' => ['hero', 'logos', 'social proof', 'trust'],
                'best_for' => ['b2b', 'enterprise', 'agencies with notable clients'],
                'pairs_well_with' => ['testimonials-and-logos', 'feature-boxes-with-button', 'text-call-to-action'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/hero-text-image-and-logos"} /-->',
            ],

            // ============ HEADERS ============
            [
                'slug' => 'ollie/header-dark',
                'name' => 'Header Dark',
                'category' => 'headers',
                'description' => 'Dark navigation bar. Use with dark hero or for bold contrast.',
                'keywords' => ['header', 'nav', 'dark'],
                'best_for' => ['creative', 'agencies', 'tech', 'modern brands'],
                'pairs_well_with' => ['hero-dark', 'footer-dark'],
                'is_template_part' => true,
                'block_markup_example' => '<!-- wp:template-part {"slug":"header-dark","tagName":"header"} /-->',
            ],
            [
                'slug' => 'ollie/header-light',
                'name' => 'Header Light',
                'category' => 'headers',
                'description' => 'Light navigation bar. Classic and professional.',
                'keywords' => ['header', 'nav', 'light'],
                'best_for' => ['corporate', 'healthcare', 'professional services'],
                'pairs_well_with' => ['hero-light', 'footer-light'],
                'is_template_part' => true,
                'block_markup_example' => '<!-- wp:template-part {"slug":"header-light","tagName":"header"} /-->',
            ],
            [
                'slug' => 'ollie/header-dark-with-banner',
                'name' => 'Header Dark Banner',
                'category' => 'headers',
                'description' => 'Dark header with announcement banner. Great for promotions.',
                'keywords' => ['header', 'banner', 'announcement'],
                'best_for' => ['e-commerce', 'launches', 'promotions', 'events'],
                'pairs_well_with' => ['hero-dark', 'hero-call-to-action-buttons'],
                'is_template_part' => true,
                'block_markup_example' => '<!-- wp:template-part {"slug":"header-dark-with-banner","tagName":"header"} /-->',
            ],
            [
                'slug' => 'ollie/header-light-with-buttons',
                'name' => 'Header Light Buttons',
                'category' => 'headers',
                'description' => 'Light header with CTA buttons. Encourages conversion from nav.',
                'keywords' => ['header', 'buttons', 'light'],
                'best_for' => ['saas', 'software', 'signup-focused sites'],
                'pairs_well_with' => ['hero-light', 'hero-call-to-action-buttons'],
                'is_template_part' => true,
                'block_markup_example' => '<!-- wp:template-part {"slug":"header-light-with-buttons","tagName":"header"} /-->',
            ],

            // ============ FOOTERS ============
            [
                'slug' => 'ollie/footer-dark',
                'name' => 'Footer Dark',
                'category' => 'footers',
                'description' => 'Full dark footer with columns for links, contact, newsletter.',
                'keywords' => ['footer', 'dark', 'columns'],
                'best_for' => ['full websites', 'many pages', 'content-rich sites'],
                'pairs_well_with' => ['header-dark', 'hero-dark'],
                'is_template_part' => true,
                'block_markup_example' => '<!-- wp:template-part {"slug":"footer-dark","tagName":"footer"} /-->',
            ],
            [
                'slug' => 'ollie/footer-light',
                'name' => 'Footer Light',
                'category' => 'footers',
                'description' => 'Full light footer with columns. Professional and clean.',
                'keywords' => ['footer', 'light', 'columns'],
                'best_for' => ['corporate', 'professional services', 'healthcare'],
                'pairs_well_with' => ['header-light', 'hero-light'],
                'is_template_part' => true,
                'block_markup_example' => '<!-- wp:template-part {"slug":"footer-light","tagName":"footer"} /-->',
            ],
            [
                'slug' => 'ollie/footer-dark-minimal',
                'name' => 'Footer Dark Minimal',
                'category' => 'footers',
                'description' => 'Minimal dark footer. Copyright and essential links only.',
                'keywords' => ['footer', 'minimal', 'dark'],
                'best_for' => ['landing pages', 'simple sites', 'focused conversions'],
                'pairs_well_with' => ['header-dark', 'text-call-to-action'],
                'is_template_part' => true,
                'block_markup_example' => '<!-- wp:template-part {"slug":"footer-dark-minimal","tagName":"footer"} /-->',
            ],
            [
                'slug' => 'ollie/footer-light-minimal',
                'name' => 'Footer Light Minimal',
                'category' => 'footers',
                'description' => 'Minimal light footer. Clean and unobtrusive.',
                'keywords' => ['footer', 'minimal', 'light'],
                'best_for' => ['landing pages', 'portfolios', 'minimalist designs'],
                'pairs_well_with' => ['header-light', 'text-call-to-action'],
                'is_template_part' => true,
                'block_markup_example' => '<!-- wp:template-part {"slug":"footer-light-minimal","tagName":"footer"} /-->',
            ],

            // ============ FEATURES ============
            [
                'slug' => 'ollie/feature-boxes-with-button',
                'name' => 'Feature Boxes Button',
                'category' => 'features',
                'description' => 'Feature grid (3 columns) with CTA button. Shows key benefits.',
                'keywords' => ['features', 'grid', 'button'],
                'best_for' => ['services overview', 'product features', 'benefits section'],
                'pairs_well_with' => ['hero-light', 'testimonials-and-logos', 'text-call-to-action'],
                'content_slots' => ['heading', 'subheading', '3 feature titles', '3 feature descriptions', 'button text'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/feature-boxes-with-button"} /-->',
            ],
            [
                'slug' => 'ollie/feature-boxes-with-icon-dark',
                'name' => 'Feature Boxes Icons Dark',
                'category' => 'features',
                'description' => 'Dark feature boxes with icons. Bold and modern.',
                'keywords' => ['features', 'icons', 'dark'],
                'best_for' => ['tech companies', 'modern brands', 'feature highlights'],
                'pairs_well_with' => ['hero-dark', 'testimonials-with-big-text', 'footer-dark'],
                'content_slots' => ['section heading', '3-4 feature icons', '3-4 feature titles', '3-4 descriptions'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/feature-boxes-with-icon-dark"} /-->',
            ],
            [
                'slug' => 'ollie/features-with-emojis',
                'name' => 'Features Emojis',
                'category' => 'features',
                'description' => 'Playful feature list with emojis. Friendly and approachable.',
                'keywords' => ['features', 'emojis', 'fun'],
                'best_for' => ['consumer products', 'creative agencies', 'youth-focused brands'],
                'pairs_well_with' => ['hero-call-to-action-buttons', 'testimonials-with-big-text'],
                'content_slots' => ['heading', '4-6 emoji feature pairs'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/features-with-emojis"} /-->',
            ],
            [
                'slug' => 'ollie/image-and-numbered-features',
                'name' => 'Numbered Features',
                'category' => 'features',
                'description' => 'Numbered feature list with image. Shows process or steps.',
                'keywords' => ['features', 'numbered', 'steps'],
                'best_for' => ['how it works', 'process explanation', 'onboarding'],
                'pairs_well_with' => ['hero-call-to-action-buttons', 'text-call-to-action-buttons'],
                'content_slots' => ['heading', 'image', '3-4 numbered steps with titles and descriptions'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/image-and-numbered-features"} /-->',
            ],

            // ============ TESTIMONIALS ============
            [
                'slug' => 'ollie/testimonials-and-logos',
                'name' => 'Testimonials Logos',
                'category' => 'testimonials',
                'description' => 'Testimonials with client logos. Maximum social proof impact.',
                'keywords' => ['testimonials', 'logos', 'social proof'],
                'best_for' => ['b2b', 'agencies', 'enterprise sales'],
                'pairs_well_with' => ['hero-text-image-and-logos', 'pricing-table', 'text-call-to-action'],
                'content_slots' => ['2-3 testimonial quotes', '2-3 author names/companies', '4-6 client logos'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/testimonials-and-logos"} /-->',
            ],
            [
                'slug' => 'ollie/testimonial-highlight',
                'name' => 'Testimonial Highlight',
                'category' => 'testimonials',
                'description' => 'Single featured testimonial. Large quote format.',
                'keywords' => ['testimonial', 'featured', 'highlight'],
                'best_for' => ['key customer story', 'case study teaser', 'above pricing'],
                'pairs_well_with' => ['hero-light', 'pricing-table', 'feature-boxes-with-button'],
                'content_slots' => ['large quote', 'author name', 'author title/company', 'optional photo'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/testimonial-highlight"} /-->',
            ],
            [
                'slug' => 'ollie/testimonials-with-big-text',
                'name' => 'Testimonials Big Text',
                'category' => 'testimonials',
                'description' => 'Large quote testimonials. Impactful and bold.',
                'keywords' => ['testimonials', 'big', 'quote'],
                'best_for' => ['creative agencies', 'high-end services', 'emotional impact'],
                'pairs_well_with' => ['hero-dark', 'feature-boxes-with-icon-dark'],
                'content_slots' => ['large quote text', 'author attribution'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/testimonials-with-big-text"} /-->',
            ],
            [
                'slug' => 'ollie/single-testimonial',
                'name' => 'Single Testimonial',
                'category' => 'testimonials',
                'description' => 'Individual testimonial block. Compact and flexible.',
                'keywords' => ['testimonial', 'single', 'quote'],
                'best_for' => ['inline placement', 'near CTAs', 'service pages'],
                'pairs_well_with' => ['text-call-to-action', 'card-call-to-action'],
                'content_slots' => ['quote', 'author name', 'optional role'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/single-testimonial"} /-->',
            ],

            // ============ PRICING ============
            [
                'slug' => 'ollie/pricing-table',
                'name' => 'Pricing Table',
                'category' => 'pricing',
                'description' => 'Standard pricing comparison. 2 tiers side by side.',
                'keywords' => ['pricing', 'table', 'comparison'],
                'best_for' => ['simple pricing', 'two options', 'saas'],
                'pairs_well_with' => ['testimonial-highlight', 'faq', 'text-call-to-action'],
                'content_slots' => ['2 plan names', '2 prices', '2 feature lists', '2 CTA buttons'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/pricing-table"} /-->',
            ],
            [
                'slug' => 'ollie/pricing-table-3-column',
                'name' => 'Pricing 3 Column',
                'category' => 'pricing',
                'description' => 'Three-tier pricing table. Good/better/best model.',
                'keywords' => ['pricing', 'three', 'tiers'],
                'best_for' => ['saas', 'tiered services', 'subscription products'],
                'pairs_well_with' => ['testimonials-and-logos', 'faq', 'text-call-to-action-buttons'],
                'content_slots' => ['3 plan names', '3 prices', '3 feature lists', '3 CTA buttons', 'optional "popular" badge'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/pricing-table-3-column"} /-->',
            ],
            [
                'slug' => 'ollie/pricing-table-with-testimonials',
                'name' => 'Pricing Testimonials',
                'category' => 'pricing',
                'description' => 'Pricing with social proof. Reduces purchase anxiety.',
                'keywords' => ['pricing', 'testimonials', 'trust'],
                'best_for' => ['high-value products', 'trust-sensitive purchases'],
                'pairs_well_with' => ['faq', 'text-call-to-action'],
                'content_slots' => ['pricing tiers', 'testimonial quotes below pricing'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/pricing-table-with-testimonials"} /-->',
            ],

            // ============ CTAs ============
            [
                'slug' => 'ollie/text-call-to-action',
                'name' => 'Text CTA',
                'category' => 'ctas',
                'description' => 'Simple text call to action. Clean closing section.',
                'keywords' => ['cta', 'text', 'simple'],
                'best_for' => ['page endings', 'between sections', 'subtle conversion'],
                'pairs_well_with' => ['any pattern - use at end of page flow'],
                'content_slots' => ['heading', 'subheading', 'single button'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/text-call-to-action"} /-->',
            ],
            [
                'slug' => 'ollie/text-call-to-action-buttons',
                'name' => 'Text CTA Buttons',
                'category' => 'ctas',
                'description' => 'CTA with button options. Primary + secondary action.',
                'keywords' => ['cta', 'buttons', 'action'],
                'best_for' => ['dual actions', 'sign up + learn more', 'buy + demo'],
                'pairs_well_with' => ['pricing-table', 'testimonials-and-logos'],
                'content_slots' => ['heading', 'description', 'primary button', 'secondary button'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/text-call-to-action-buttons"} /-->',
            ],
            [
                'slug' => 'ollie/card-big-text-call-to-action',
                'name' => 'Big Text CTA',
                'category' => 'ctas',
                'description' => 'Bold text CTA section. Maximum impact closing.',
                'keywords' => ['cta', 'big', 'bold'],
                'best_for' => ['homepage endings', 'landing pages', 'high-conversion pages'],
                'pairs_well_with' => ['testimonials-with-big-text', 'pricing-table'],
                'content_slots' => ['large heading', 'CTA button'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/card-big-text-call-to-action"} /-->',
            ],

            // ============ CARDS ============
            [
                'slug' => 'ollie/card-pricing-table',
                'name' => 'Pricing Card',
                'category' => 'cards',
                'description' => 'Single pricing tier card. Use individually or compose multiples.',
                'keywords' => ['card', 'pricing', 'tier'],
                'best_for' => ['custom pricing layouts', 'single plan highlight'],
                'content_slots' => ['plan name', 'price', 'features list', 'CTA button'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/card-pricing-table"} /-->',
            ],
            [
                'slug' => 'ollie/card-testimonial',
                'name' => 'Testimonial Card',
                'category' => 'cards',
                'description' => 'Single testimonial card. Compose grids of these.',
                'keywords' => ['card', 'testimonial', 'quote'],
                'best_for' => ['testimonial grids', 'carousel items'],
                'content_slots' => ['quote', 'author', 'optional photo'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/card-testimonial"} /-->',
            ],
            [
                'slug' => 'ollie/card-call-to-action',
                'name' => 'CTA Card',
                'category' => 'cards',
                'description' => 'Call to action card. Contained CTA for sidebars or grids.',
                'keywords' => ['card', 'cta', 'action'],
                'best_for' => ['sidebar widgets', 'inline CTAs', 'grid layouts'],
                'content_slots' => ['heading', 'description', 'button'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/card-call-to-action"} /-->',
            ],
            [
                'slug' => 'ollie/card-contact',
                'name' => 'Contact Card',
                'category' => 'cards',
                'description' => 'Contact information card. Phone, email, address.',
                'keywords' => ['card', 'contact', 'info'],
                'best_for' => ['contact page', 'footer', 'sidebar'],
                'content_slots' => ['phone', 'email', 'address', 'optional hours'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/card-contact"} /-->',
            ],
            [
                'slug' => 'ollie/card-blog-post',
                'name' => 'Blog Post Card',
                'category' => 'cards',
                'description' => 'Blog post preview card. Image, title, excerpt.',
                'keywords' => ['card', 'blog', 'post'],
                'best_for' => ['blog grids', 'related posts', 'news sections'],
                'content_slots' => ['featured image', 'title', 'excerpt', 'date', 'read more link'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/card-blog-post"} /-->',
            ],
            [
                'slug' => 'ollie/card-image-and-text',
                'name' => 'Image Text Card',
                'category' => 'cards',
                'description' => 'Image with text card. Versatile content block.',
                'keywords' => ['card', 'image', 'text'],
                'best_for' => ['service cards', 'portfolio items', 'feature highlights'],
                'content_slots' => ['image', 'title', 'description', 'optional link'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/card-image-and-text"} /-->',
            ],
            [
                'slug' => 'ollie/team-members',
                'name' => 'Team Members',
                'category' => 'cards',
                'description' => 'Team member grid. Photos, names, roles.',
                'keywords' => ['team', 'members', 'people'],
                'best_for' => ['about page', 'team page', 'company culture'],
                'pairs_well_with' => ['hero-light', 'text-call-to-action'],
                'content_slots' => ['3-4 photos', '3-4 names', '3-4 titles', 'optional bios'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/team-members"} /-->',
            ],
            [
                'slug' => 'ollie/faq',
                'name' => 'FAQ',
                'category' => 'cards',
                'description' => 'FAQ accordion section. Expandable Q&A format.',
                'keywords' => ['faq', 'questions', 'accordion'],
                'best_for' => ['pricing pages', 'product pages', 'support'],
                'pairs_well_with' => ['pricing-table', 'text-call-to-action'],
                'content_slots' => ['section heading', '4-8 question/answer pairs'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/faq"} /-->',
            ],
            [
                'slug' => 'ollie/contact-details',
                'name' => 'Contact Details',
                'category' => 'cards',
                'description' => 'Contact information section. Full contact block.',
                'keywords' => ['contact', 'details', 'info'],
                'best_for' => ['contact page', 'about page footer'],
                'pairs_well_with' => ['hero-light', 'footer-light'],
                'content_slots' => ['heading', 'address', 'phone', 'email', 'hours', 'optional map'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/contact-details"} /-->',
            ],

            // ============ PAGES ============
            [
                'slug' => 'ollie/page-home',
                'name' => 'Homepage',
                'category' => 'pages',
                'description' => 'Complete homepage layout. All essential sections included.',
                'keywords' => ['page', 'home', 'complete'],
                'best_for' => ['quick start', 'full homepage', 'demo sites'],
                'included_patterns' => ['hero', 'features', 'testimonials', 'cta'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/page-home"} /-->',
            ],
            [
                'slug' => 'ollie/page-about',
                'name' => 'About Page',
                'category' => 'pages',
                'description' => 'About page layout. Mission, team, story sections.',
                'keywords' => ['page', 'about', 'company'],
                'best_for' => ['company about', 'team showcase'],
                'included_patterns' => ['hero-light', 'team-members', 'testimonial-highlight'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/page-about"} /-->',
            ],
            [
                'slug' => 'ollie/page-pricing',
                'name' => 'Pricing Page',
                'category' => 'pages',
                'description' => 'Pricing page layout. Plans, comparison, FAQ.',
                'keywords' => ['page', 'pricing', 'plans'],
                'best_for' => ['saas pricing', 'service tiers'],
                'included_patterns' => ['pricing-table-3-column', 'faq', 'testimonials-and-logos'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/page-pricing"} /-->',
            ],
            [
                'slug' => 'ollie/page-features',
                'name' => 'Features Page',
                'category' => 'pages',
                'description' => 'Features page layout. Product capabilities showcase.',
                'keywords' => ['page', 'features', 'product'],
                'best_for' => ['product features', 'capabilities overview'],
                'included_patterns' => ['hero-light', 'feature-boxes-with-button', 'image-and-numbered-features'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/page-features"} /-->',
            ],
            [
                'slug' => 'ollie/page-blog',
                'name' => 'Blog Page',
                'category' => 'pages',
                'description' => 'Blog listing layout. Post grid with sidebar.',
                'keywords' => ['page', 'blog', 'posts'],
                'best_for' => ['blog index', 'news section'],
                'included_patterns' => ['post-loop-grid-default'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/page-blog"} /-->',
            ],

            // ============ BLOG ============
            [
                'slug' => 'ollie/blog-post-columns',
                'name' => 'Blog Post Columns',
                'category' => 'blog',
                'description' => 'Blog posts in columns. Grid layout for post archives.',
                'keywords' => ['blog', 'columns', 'grid'],
                'best_for' => ['blog archives', 'category pages'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/blog-post-columns"} /-->',
            ],
            [
                'slug' => 'ollie/post-loop-grid-default',
                'name' => 'Post Loop Grid',
                'category' => 'blog',
                'description' => 'Default post grid loop. Dynamic query block.',
                'keywords' => ['blog', 'loop', 'grid'],
                'best_for' => ['blog page', 'latest posts'],
                'is_dynamic' => true,
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/post-loop-grid-default"} /-->',
            ],
            [
                'slug' => 'ollie/post-loop-list',
                'name' => 'Post Loop List',
                'category' => 'blog',
                'description' => 'Post list loop. Vertical list format.',
                'keywords' => ['blog', 'loop', 'list'],
                'best_for' => ['news feeds', 'compact blog layouts'],
                'is_dynamic' => true,
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/post-loop-list"} /-->',
            ],
            [
                'slug' => 'ollie/author-box',
                'name' => 'Author Box',
                'category' => 'blog',
                'description' => 'Author bio box. Photo, name, bio, social links.',
                'keywords' => ['author', 'bio', 'blog'],
                'best_for' => ['blog posts', 'article footers'],
                'content_slots' => ['author photo', 'name', 'bio', 'social links'],
                'block_markup_example' => '<!-- wp:pattern {"slug":"ollie/author-box"} /-->',
            ],
        ];
    }

    /**
     * Get recommended page compositions for common page types.
     *
     * @return array<string, array>
     */
    public function getPageCompositions(): array
    {
        return [
            'homepage' => [
                'description' => 'Standard homepage for most businesses',
                'patterns' => [
                    'ollie/hero-dark',
                    'ollie/feature-boxes-with-button',
                    'ollie/testimonials-and-logos',
                    'ollie/text-call-to-action',
                ],
                'notes' => 'Alternate dark/light sections for visual rhythm',
            ],
            'homepage_saas' => [
                'description' => 'Homepage optimized for SaaS/software products',
                'patterns' => [
                    'ollie/hero-call-to-action-buttons',
                    'ollie/image-and-numbered-features',
                    'ollie/testimonials-with-big-text',
                    'ollie/pricing-table-3-column',
                    'ollie/faq',
                    'ollie/text-call-to-action-buttons',
                ],
                'notes' => 'Focus on conversion with pricing visible',
            ],
            'homepage_local_business' => [
                'description' => 'Homepage for local service businesses',
                'patterns' => [
                    'ollie/hero-text-image-and-logos',
                    'ollie/feature-boxes-with-icon-dark',
                    'ollie/testimonials-and-logos',
                    'ollie/contact-details',
                    'ollie/text-call-to-action',
                ],
                'notes' => 'Trust-focused with contact info prominent',
            ],
            'about' => [
                'description' => 'Standard about page',
                'patterns' => [
                    'ollie/hero-light',
                    'ollie/card-image-and-text', // Story section
                    'ollie/team-members',
                    'ollie/testimonial-highlight',
                    'ollie/text-call-to-action',
                ],
                'notes' => 'Build connection and trust',
            ],
            'services' => [
                'description' => 'Services overview page',
                'patterns' => [
                    'ollie/hero-light',
                    'ollie/feature-boxes-with-button',
                    'ollie/image-and-numbered-features',
                    'ollie/testimonials-and-logos',
                    'ollie/text-call-to-action-buttons',
                ],
                'notes' => 'Showcase services with social proof',
            ],
            'pricing' => [
                'description' => 'Pricing page',
                'patterns' => [
                    'ollie/hero-light',
                    'ollie/pricing-table-3-column',
                    'ollie/testimonials-and-logos',
                    'ollie/faq',
                    'ollie/text-call-to-action',
                ],
                'notes' => 'Reduce friction with FAQ and social proof',
            ],
            'contact' => [
                'description' => 'Contact page',
                'patterns' => [
                    'ollie/hero-light',
                    'ollie/contact-details',
                ],
                'notes' => 'Keep simple - contact form added separately',
            ],
        ];
    }
}
