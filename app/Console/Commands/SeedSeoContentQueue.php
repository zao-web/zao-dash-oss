<?php

namespace App\Console\Commands;

use App\Enums\SeoPageStatus;
use App\Models\SeoPage;
use Illuminate\Console\Command;

class SeedSeoContentQueue extends Command
{
    protected $signature = 'seo:seed-queue
                            {--priority=all : Filter by priority (1-10 or "all")}
                            {--playbook= : Filter by playbook type}
                            {--dry-run : Show what would be created without creating}
                            {--limit= : Limit number of pages to seed}';

    protected $description = 'Seed the SEO content queue with planned pages for autonomous generation';

    public function handle(): int
    {
        $plannedPages = $this->getPlannedPages();

        // Apply filters
        if ($this->option('priority') !== 'all') {
            $priority = (int) $this->option('priority');
            $plannedPages = array_filter($plannedPages, fn ($p) => $p['priority'] === $priority);
        }

        if ($playbook = $this->option('playbook')) {
            $plannedPages = array_filter($plannedPages, fn ($p) => $p['playbook'] === $playbook);
        }

        if ($limit = $this->option('limit')) {
            $plannedPages = array_slice($plannedPages, 0, (int) $limit);
        }

        $this->info('Seeding SEO content queue with '.count($plannedPages).' pages...');

        if ($this->option('dry-run')) {
            $this->table(
                ['Priority', 'Playbook', 'Keyword', 'URL'],
                array_map(fn ($p) => [
                    $p['priority'],
                    $p['playbook'],
                    $p['keyword'],
                    $p['url'],
                ], array_slice($plannedPages, 0, 20))
            );
            $this->info('(Showing first 20 of '.count($plannedPages).' pages)');
            $this->warn('Dry run - no pages created');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar(count($plannedPages));
        $bar->start();

        foreach ($plannedPages as $page) {
            // Skip if page already exists
            if (SeoPage::where('page_url', $page['url'])->exists()) {
                $skipped++;
                $bar->advance();

                continue;
            }

            SeoPage::create([
                'page_url' => $page['url'],
                'target_keyword' => $page['keyword'],
                'page_type' => $this->mapPlaybookToPageType($page['playbook']),
                'playbook' => $page['playbook'],
                'meta_title' => $page['title'],
                'meta_description' => "Learn about {$page['keyword']}. Expert insights from Zao's team.",
                'status' => SeoPageStatus::Queued,
                'priority' => $page['priority'],
                'generated_by_agent' => true,
                'total_leads' => 0,
                'total_projects' => 0,
                'total_revenue' => 0,
                'optimization_count' => 0,
            ]);

            $created++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Created {$created} queued pages, skipped {$skipped} existing pages");

        return self::SUCCESS;
    }

    private function getPlannedPages(): array
    {
        $pages = [];

        // Priority 1: Comparisons (30 pages)
        $comparisons = [
            ['Laravel vs Django', 'laravel-vs-django'],
            ['Laravel vs Django for Healthcare', 'laravel-vs-django-healthcare'],
            ['Laravel vs Django for Fintech', 'laravel-vs-django-fintech'],
            ['Laravel vs Django for SaaS', 'laravel-vs-django-saas'],
            ['Laravel vs Django for E-commerce', 'laravel-vs-django-ecommerce'],
            ['Laravel vs Node.js', 'laravel-vs-nodejs'],
            ['Laravel vs Node.js for APIs', 'laravel-vs-nodejs-api-development'],
            ['Laravel vs Node.js for Real-time Apps', 'laravel-vs-nodejs-realtime'],
            ['Laravel vs Node.js for Startups', 'laravel-vs-nodejs-startups'],
            ['Laravel vs Rails', 'laravel-vs-rails'],
            ['Laravel vs Rails for MVPs', 'laravel-vs-rails-mvp'],
            ['Laravel vs Rails for Startups', 'laravel-vs-rails-startups'],
            ['WordPress vs Webflow', 'wordpress-vs-webflow'],
            ['WordPress vs Webflow for Corporate', 'wordpress-vs-webflow-corporate'],
            ['WordPress vs Webflow for Agencies', 'wordpress-vs-webflow-agencies'],
            ['WordPress vs Squarespace', 'wordpress-vs-squarespace'],
            ['WordPress vs Squarespace for Small Business', 'wordpress-vs-squarespace-small-business'],
            ['WordPress vs Squarespace for Portfolios', 'wordpress-vs-squarespace-portfolio'],
            ['WooCommerce vs Shopify', 'woocommerce-vs-shopify'],
            ['WooCommerce vs Shopify for Subscriptions', 'woocommerce-vs-shopify-subscriptions'],
            ['WooCommerce vs Shopify for Custom', 'woocommerce-vs-shopify-custom'],
            ['WooCommerce vs Shopify for Enterprise', 'woocommerce-vs-shopify-enterprise'],
            ['React Native vs Flutter', 'react-native-vs-flutter'],
            ['React Native vs Flutter for Healthcare', 'react-native-vs-flutter-healthcare'],
            ['React Native vs Flutter for Finance', 'react-native-vs-flutter-finance'],
            ['React Native vs Flutter for Consumer Apps', 'react-native-vs-flutter-consumer'],
            ['Toptal vs Zao for Laravel', 'toptal-vs-zao-laravel'],
            ['Toptal vs Zao for WordPress', 'toptal-vs-zao-wordpress'],
            ['Toptal vs Zao for React Native', 'toptal-vs-zao-react-native'],
            ['Agency vs Freelancer for Laravel Projects', 'agency-vs-freelancer-laravel'],
        ];

        foreach ($comparisons as $comp) {
            $pages[] = [
                'title' => $comp[0],
                'keyword' => strtolower($comp[0]),
                'url' => '/'.$comp[1],
                'playbook' => 'Comparisons',
                'priority' => 1,
            ];
        }

        // Priority 2: Verticals (25 pages)
        $verticals = [
            ['Laravel for Healthcare', 'laravel-healthcare'],
            ['Laravel for Fintech', 'laravel-fintech'],
            ['Laravel for SaaS', 'laravel-saas'],
            ['Laravel for Real Estate', 'laravel-real-estate'],
            ['Laravel for Education', 'laravel-education'],
            ['Laravel for Logistics', 'laravel-logistics'],
            ['Laravel for Legal', 'laravel-legal'],
            ['Laravel for Insurance', 'laravel-insurance'],
            ['WordPress for Healthcare', 'wordpress-healthcare'],
            ['WordPress for Nonprofits', 'wordpress-nonprofits'],
            ['WordPress for Higher Education', 'wordpress-higher-education'],
            ['WordPress for Law Firms', 'wordpress-law-firms'],
            ['WordPress for Restaurants', 'wordpress-restaurants'],
            ['WordPress for Real Estate', 'wordpress-real-estate'],
            ['WordPress for Churches', 'wordpress-churches'],
            ['React Native for Healthcare', 'react-native-healthcare'],
            ['React Native for Finance', 'react-native-finance'],
            ['React Native for Retail', 'react-native-retail'],
            ['React Native for Travel', 'react-native-travel'],
            ['React Native for Fitness', 'react-native-fitness'],
            ['Laravel for Startups', 'laravel-startups'],
            ['Laravel for Enterprise', 'laravel-enterprise'],
            ['WordPress for E-commerce', 'wordpress-ecommerce'],
            ['WordPress for Membership Sites', 'wordpress-membership'],
            ['React Native for E-commerce', 'react-native-ecommerce'],
        ];

        foreach ($verticals as $vert) {
            $pages[] = [
                'title' => $vert[0],
                'keyword' => strtolower($vert[0]),
                'url' => '/'.$vert[1],
                'playbook' => 'Vertical',
                'priority' => 2,
            ];
        }

        // Priority 3: Glossary (30 pages)
        $glossary = [
            ['What is Eloquent ORM', 'what-is-eloquent-orm'],
            ['What is Laravel Blade', 'what-is-laravel-blade'],
            ['What is Laravel Artisan', 'what-is-laravel-artisan'],
            ['What is Laravel Middleware', 'what-is-laravel-middleware'],
            ['What is Laravel Facades', 'what-is-laravel-facades'],
            ['What is Laravel Service Container', 'what-is-laravel-service-container'],
            ['What is Laravel Queue', 'what-is-laravel-queue'],
            ['What is Laravel Events', 'what-is-laravel-events'],
            ['What is Laravel Horizon', 'what-is-laravel-horizon'],
            ['What is Laravel Sanctum', 'what-is-laravel-sanctum'],
            ['What are WordPress Hooks', 'what-are-wordpress-hooks'],
            ['What are WordPress Filters', 'what-are-wordpress-filters'],
            ['What are WordPress Actions', 'what-are-wordpress-actions'],
            ['What are Custom Post Types', 'what-are-custom-post-types'],
            ['What is Gutenberg', 'what-is-gutenberg'],
            ['What is WordPress REST API', 'what-is-wordpress-rest-api'],
            ['What is WP-CLI', 'what-is-wp-cli'],
            ['What is WordPress Multisite', 'what-is-wordpress-multisite'],
            ['What is React Native Expo', 'what-is-react-native-expo'],
            ['What is React Native Metro', 'what-is-react-native-metro'],
            ['What is React Native Bridge', 'what-is-react-native-bridge'],
            ['What are Native Modules', 'what-are-native-modules'],
            ['What is Hermes Engine', 'what-is-hermes-engine'],
            ['What is MVC Architecture', 'what-is-mvc-architecture'],
            ['What is REST API', 'what-is-rest-api'],
            ['What is GraphQL', 'what-is-graphql'],
            ['What is CI/CD', 'what-is-ci-cd'],
            ['What is DevOps', 'what-is-devops'],
            ['What is Laravel Livewire', 'what-is-laravel-livewire'],
            ['What is Inertia.js', 'what-is-inertia-js'],
        ];

        foreach ($glossary as $gloss) {
            $pages[] = [
                'title' => $gloss[0],
                'keyword' => strtolower($gloss[0]),
                'url' => '/'.$gloss[1],
                'playbook' => 'Glossary',
                'priority' => 3,
            ];
        }

        // Priority 4: Examples (20 pages)
        $examples = [
            ['Laravel SaaS App Examples', 'laravel-saas-examples'],
            ['Laravel E-commerce Examples', 'laravel-ecommerce-examples'],
            ['Laravel Healthcare Portal Examples', 'laravel-healthcare-examples'],
            ['Laravel Real-time App Examples', 'laravel-realtime-examples'],
            ['Laravel API Backend Examples', 'laravel-api-examples'],
            ['WordPress Corporate Site Examples', 'wordpress-corporate-examples'],
            ['WordPress Membership Site Examples', 'wordpress-membership-examples'],
            ['WordPress E-commerce Examples', 'wordpress-ecommerce-examples'],
            ['WordPress Multilingual Site Examples', 'wordpress-multilingual-examples'],
            ['WordPress Nonprofit Site Examples', 'wordpress-nonprofit-examples'],
            ['React Native Fintech App Examples', 'react-native-fintech-examples'],
            ['React Native Healthcare App Examples', 'react-native-healthcare-examples'],
            ['React Native E-commerce App Examples', 'react-native-ecommerce-examples'],
            ['React Native Social App Examples', 'react-native-social-examples'],
            ['Laravel MVP Examples', 'laravel-mvp-examples'],
            ['Laravel Admin Dashboard Examples', 'laravel-admin-dashboard-examples'],
            ['WordPress Portfolio Examples', 'wordpress-portfolio-examples'],
            ['WordPress Agency Site Examples', 'wordpress-agency-examples'],
            ['React Native Fitness App Examples', 'react-native-fitness-examples'],
            ['React Native Travel App Examples', 'react-native-travel-examples'],
        ];

        foreach ($examples as $ex) {
            $pages[] = [
                'title' => $ex[0],
                'keyword' => strtolower(str_replace(' Examples', '', $ex[0])),
                'url' => '/'.$ex[1],
                'playbook' => 'Examples',
                'priority' => 4,
            ];
        }

        // Priority 5: Case Studies (15 pages - placeholders for actual client work)
        $caseStudies = [
            ['Healthcare Portal Case Study', 'case-study-healthcare-portal'],
            ['Fintech Dashboard Case Study', 'case-study-fintech-dashboard'],
            ['SaaS Platform Case Study', 'case-study-saas-platform'],
            ['E-commerce Migration Case Study', 'case-study-ecommerce-migration'],
            ['Real Estate Platform Case Study', 'case-study-real-estate'],
            ['Nonprofit Website Case Study', 'case-study-nonprofit'],
            ['Law Firm Website Case Study', 'case-study-law-firm'],
            ['Restaurant Chain Case Study', 'case-study-restaurant'],
            ['Mobile Banking App Case Study', 'case-study-mobile-banking'],
            ['Fitness App Case Study', 'case-study-fitness-app'],
            ['Logistics Platform Case Study', 'case-study-logistics'],
            ['Education Platform Case Study', 'case-study-education'],
            ['Insurance Portal Case Study', 'case-study-insurance'],
            ['Travel App Case Study', 'case-study-travel-app'],
            ['Retail App Case Study', 'case-study-retail-app'],
        ];

        foreach ($caseStudies as $cs) {
            $pages[] = [
                'title' => $cs[0],
                'keyword' => strtolower($cs[0]),
                'url' => '/'.$cs[1],
                'playbook' => 'Case Study',
                'priority' => 5,
            ];
        }

        // Priority 6: Integrations (20 pages)
        $integrations = [
            ['Laravel Stripe Integration', 'laravel-stripe-integration'],
            ['Laravel Twilio Integration', 'laravel-twilio-integration'],
            ['Laravel AWS Integration', 'laravel-aws-integration'],
            ['Laravel Salesforce Integration', 'laravel-salesforce-integration'],
            ['Laravel HubSpot Integration', 'laravel-hubspot-integration'],
            ['Laravel SendGrid Integration', 'laravel-sendgrid-integration'],
            ['Laravel Pusher Integration', 'laravel-pusher-integration'],
            ['WordPress Salesforce Integration', 'wordpress-salesforce-integration'],
            ['WordPress HubSpot Integration', 'wordpress-hubspot-integration'],
            ['WordPress Mailchimp Integration', 'wordpress-mailchimp-integration'],
            ['WordPress Zapier Integration', 'wordpress-zapier-integration'],
            ['WordPress GA4 Integration', 'wordpress-ga4-integration'],
            ['WordPress Stripe Integration', 'wordpress-stripe-integration'],
            ['React Native Firebase Integration', 'react-native-firebase-integration'],
            ['React Native AWS Amplify Integration', 'react-native-aws-amplify-integration'],
            ['React Native Stripe Integration', 'react-native-stripe-integration'],
            ['React Native OneSignal Integration', 'react-native-onesignal-integration'],
            ['Laravel Redis Integration', 'laravel-redis-integration'],
            ['Laravel Elasticsearch Integration', 'laravel-elasticsearch-integration'],
            ['WordPress WooCommerce Integration', 'wordpress-woocommerce-integration'],
        ];

        foreach ($integrations as $int) {
            $pages[] = [
                'title' => $int[0],
                'keyword' => strtolower($int[0]),
                'url' => '/'.$int[1],
                'playbook' => 'Integration',
                'priority' => 6,
            ];
        }

        // Priority 7: Curation (15 pages)
        $curation = [
            ['Best Laravel Packages 2026', 'best-laravel-packages-2026'],
            ['Best WordPress Themes for Agencies', 'best-wordpress-themes-agencies'],
            ['Best React Native Libraries', 'best-react-native-libraries'],
            ['Essential Laravel Tools', 'essential-laravel-tools'],
            ['WordPress Security Plugins Guide', 'wordpress-security-plugins'],
            ['Best Laravel Starter Kits', 'best-laravel-starter-kits'],
            ['Best WordPress Page Builders', 'best-wordpress-page-builders'],
            ['Best React Native UI Libraries', 'best-react-native-ui-libraries'],
            ['Laravel Testing Tools', 'laravel-testing-tools'],
            ['WordPress SEO Plugins', 'wordpress-seo-plugins'],
            ['React Native State Management', 'react-native-state-management'],
            ['Laravel Admin Panels Comparison', 'laravel-admin-panels'],
            ['WordPress E-commerce Plugins', 'wordpress-ecommerce-plugins'],
            ['React Native Navigation Libraries', 'react-native-navigation-libraries'],
            ['Laravel API Tools', 'laravel-api-tools'],
        ];

        foreach ($curation as $cur) {
            $pages[] = [
                'title' => $cur[0],
                'keyword' => strtolower($cur[0]),
                'url' => '/'.$cur[1],
                'playbook' => 'Curation',
                'priority' => 7,
            ];
        }

        // Priority 8: Tools (10 pages)
        $tools = [
            ['Laravel Project Cost Calculator', 'laravel-cost-calculator'],
            ['WordPress Site Audit Tool', 'wordpress-audit-tool'],
            ['React Native vs Flutter Comparison Tool', 'react-native-flutter-comparison-tool'],
            ['Tech Stack Selector', 'tech-stack-selector'],
            ['API Performance Calculator', 'api-performance-calculator'],
            ['WordPress Speed Test Tool', 'wordpress-speed-test'],
            ['Laravel Hosting Cost Calculator', 'laravel-hosting-calculator'],
            ['Mobile App Development Cost Estimator', 'mobile-app-cost-estimator'],
            ['Website Redesign ROI Calculator', 'website-redesign-roi-calculator'],
            ['Development Team Size Calculator', 'development-team-calculator'],
        ];

        foreach ($tools as $tool) {
            $pages[] = [
                'title' => $tool[0],
                'keyword' => strtolower($tool[0]),
                'url' => '/'.$tool[1],
                'playbook' => 'Tools',
                'priority' => 8,
            ];
        }

        // Priority 9: Persona (15 pages)
        $personas = [
            ['CTO Guide to Laravel', 'cto-guide-laravel'],
            ['Startup Founder Guide to MVP Development', 'startup-guide-mvp'],
            ['Non-Profit Director Guide to WordPress', 'nonprofit-guide-wordpress'],
            ['Healthcare CIO Guide to HIPAA Compliance', 'healthcare-cio-hipaa'],
            ['Agency Owner Guide to WordPress Maintenance', 'agency-owner-wordpress-maintenance'],
            ['CTO Guide to React Native', 'cto-guide-react-native'],
            ['Startup Founder Guide to Choosing Tech Stack', 'startup-guide-tech-stack'],
            ['Marketing Director Guide to WordPress', 'marketing-director-wordpress'],
            ['Product Manager Guide to Laravel', 'product-manager-laravel'],
            ['CFO Guide to Software Development Costs', 'cfo-guide-development-costs'],
            ['HR Director Guide to Developer Hiring', 'hr-guide-developer-hiring'],
            ['CEO Guide to Digital Transformation', 'ceo-guide-digital-transformation'],
            ['Operations Manager Guide to Business Software', 'ops-manager-business-software'],
            ['IT Director Guide to Legacy Migration', 'it-director-legacy-migration'],
            ['Small Business Owner Guide to Website', 'small-business-website-guide'],
        ];

        foreach ($personas as $persona) {
            $pages[] = [
                'title' => $persona[0],
                'keyword' => strtolower($persona[0]),
                'url' => '/'.$persona[1],
                'playbook' => 'Persona',
                'priority' => 9,
            ];
        }

        // Priority 10: Location (5 pages)
        $locations = [
            ['Laravel Development Portland', 'laravel-development-portland'],
            ['WordPress Development Seattle', 'wordpress-development-seattle'],
            ['React Native Development Denver', 'react-native-development-denver'],
            ['Laravel Development Austin', 'laravel-development-austin'],
            ['WordPress Development San Francisco', 'wordpress-development-san-francisco'],
        ];

        foreach ($locations as $loc) {
            $pages[] = [
                'title' => $loc[0],
                'keyword' => strtolower($loc[0]),
                'url' => '/'.$loc[1],
                'playbook' => 'Location',
                'priority' => 10,
            ];
        }

        return $pages;
    }

    private function mapPlaybookToPageType(string $playbook): string
    {
        return match ($playbook) {
            'Templates', 'Tools' => 'template',
            'Curation', 'Rankings' => 'curation',
            'Converters', 'Calculators' => 'tool',
            'Comparisons' => 'comparison',
            'Examples', 'Galleries' => 'gallery',
            'Location' => 'location',
            'Persona', 'Vertical' => 'service',
            'Integration' => 'integration',
            'Glossary', 'Educational' => 'guide',
            'Directory', 'Listings' => 'directory',
            'Case Study', 'Profile' => 'case-study',
            default => 'landing',
        };
    }
}
