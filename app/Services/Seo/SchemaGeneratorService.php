<?php

namespace App\Services\Seo;

use App\Models\SeoPage;

class SchemaGeneratorService
{
    /**
     * Generate complete JSON-LD schema for a page.
     */
    public function generateForPage(SeoPage $page): array
    {
        $baseSchema = $this->getOrganizationSchema();
        $pageSchema = $this->getSchemaForPlaybook($page);

        return [
            '@context' => 'https://schema.org',
            '@graph' => array_filter([$baseSchema, $pageSchema]),
        ];
    }

    /**
     * Get schema type based on playbook.
     */
    public function getSchemaForPlaybook(SeoPage $page): ?array
    {
        return match ($page->playbook) {
            'Comparisons' => $this->comparisonSchema($page),
            'Location' => $this->localBusinessSchema($page),
            'Case Study' => $this->articleSchema($page),
            'Glossary', 'Educational' => $this->faqSchema($page),
            'Vertical', 'Persona' => $this->serviceSchema($page),
            'Integration', 'Tools' => $this->softwareSchema($page),
            'Templates', 'Examples', 'Galleries' => $this->creativeWorkSchema($page),
            'Rankings', 'Curation', 'Listings', 'Directory' => $this->itemListSchema($page),
            'Calculators', 'Converters' => $this->webApplicationSchema($page),
            'Profile' => $this->personSchema($page),
            'Translations' => $this->articleSchema($page),
            default => $this->serviceSchema($page),
        };
    }

    /**
     * Generate ItemList schema for comparison pages.
     */
    protected function comparisonSchema(SeoPage $page): array
    {
        $items = $this->extractComparisonItems($page->target_keyword);

        return [
            '@type' => 'ItemList',
            'name' => $page->meta_title,
            'description' => $page->meta_description,
            'url' => $page->page_url,
            'numberOfItems' => count($items),
            'itemListElement' => array_map(fn ($item, $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item,
            ], $items, array_keys($items)),
        ];
    }

    /**
     * Generate LocalBusiness/ProfessionalService schema for location pages.
     */
    protected function localBusinessSchema(SeoPage $page): array
    {
        $location = $this->extractLocation($page->target_keyword);

        return [
            '@type' => 'ProfessionalService',
            '@id' => $page->page_url.'#service',
            'name' => 'Zao - '.ucwords($this->extractService($page->target_keyword)),
            'description' => $page->meta_description,
            'url' => $page->page_url,
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $location,
                'addressRegion' => $this->getStateForCity($location),
                'addressCountry' => 'US',
            ],
            'geo' => $this->getGeoForLocation($location),
            'areaServed' => [
                '@type' => 'City',
                'name' => $location,
            ],
            'serviceType' => $this->extractService($page->target_keyword),
            'provider' => ['@id' => 'https://example.com/#organization'],
            'priceRange' => '$$$$',
        ];
    }

    /**
     * Generate Service schema for vertical/persona pages.
     */
    protected function serviceSchema(SeoPage $page): array
    {
        return [
            '@type' => 'Service',
            '@id' => $page->page_url.'#service',
            'name' => $page->meta_title,
            'description' => $page->meta_description,
            'url' => $page->page_url,
            'provider' => ['@id' => 'https://example.com/#organization'],
            'serviceType' => $this->extractService($page->target_keyword),
            'areaServed' => [
                '@type' => 'Country',
                'name' => 'United States',
            ],
        ];
    }

    /**
     * Generate Article schema for case studies and content pages.
     */
    protected function articleSchema(SeoPage $page): array
    {
        return [
            '@type' => 'Article',
            '@id' => $page->page_url.'#article',
            'headline' => $page->meta_title,
            'description' => $page->meta_description,
            'url' => $page->page_url,
            'author' => ['@id' => 'https://example.com/#organization'],
            'publisher' => ['@id' => 'https://example.com/#organization'],
            'datePublished' => $page->published_at?->toIso8601String(),
            'dateModified' => $page->updated_at->toIso8601String(),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $page->page_url,
            ],
        ];
    }

    /**
     * Generate FAQPage schema for glossary/educational pages.
     */
    protected function faqSchema(SeoPage $page): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $page->page_url.'#faq',
            'name' => $page->meta_title,
            'description' => $page->meta_description,
            'url' => $page->page_url,
            'mainEntity' => [], // Populated by content extractor when content is parsed
        ];
    }

    /**
     * Generate SoftwareApplication schema for integrations/tools.
     */
    protected function softwareSchema(SeoPage $page): array
    {
        return [
            '@type' => 'SoftwareApplication',
            '@id' => $page->page_url.'#software',
            'name' => $page->meta_title,
            'description' => $page->meta_description,
            'url' => $page->page_url,
            'applicationCategory' => 'WebApplication',
            'operatingSystem' => 'Web',
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'USD',
            ],
            'author' => ['@id' => 'https://example.com/#organization'],
        ];
    }

    /**
     * Generate WebApplication schema for calculators/converters.
     */
    protected function webApplicationSchema(SeoPage $page): array
    {
        return [
            '@type' => 'WebApplication',
            '@id' => $page->page_url.'#webapp',
            'name' => $page->meta_title,
            'description' => $page->meta_description,
            'url' => $page->page_url,
            'applicationCategory' => 'UtilityApplication',
            'operatingSystem' => 'Web',
            'browserRequirements' => 'Requires JavaScript',
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'USD',
            ],
            'author' => ['@id' => 'https://example.com/#organization'],
        ];
    }

    /**
     * Generate CreativeWork schema for templates/examples/galleries.
     */
    protected function creativeWorkSchema(SeoPage $page): array
    {
        return [
            '@type' => 'CreativeWork',
            '@id' => $page->page_url.'#work',
            'name' => $page->meta_title,
            'description' => $page->meta_description,
            'url' => $page->page_url,
            'author' => ['@id' => 'https://example.com/#organization'],
            'datePublished' => $page->published_at?->toIso8601String(),
            'dateModified' => $page->updated_at->toIso8601String(),
        ];
    }

    /**
     * Generate ItemList schema for rankings/curation/directory pages.
     */
    protected function itemListSchema(SeoPage $page): array
    {
        return [
            '@type' => 'ItemList',
            '@id' => $page->page_url.'#list',
            'name' => $page->meta_title,
            'description' => $page->meta_description,
            'url' => $page->page_url,
            'numberOfItems' => 10, // Default, updated when content parsed
            'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
            'itemListElement' => [], // Populated when content parsed
        ];
    }

    /**
     * Generate Person schema for profile pages.
     */
    protected function personSchema(SeoPage $page): array
    {
        return [
            '@type' => 'Person',
            '@id' => $page->page_url.'#person',
            'name' => $this->extractPersonName($page->meta_title),
            'description' => $page->meta_description,
            'url' => $page->page_url,
            'worksFor' => ['@id' => 'https://example.com/#organization'],
        ];
    }

    /**
     * Generate the standard Organization schema.
     */
    public function getOrganizationSchema(): array
    {
        return [
            '@type' => 'Organization',
            '@id' => 'https://example.com/#organization',
            'name' => 'Zao',
            'url' => 'https://example.com',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => 'https://example.com/images/zao-logo.png',
                'width' => 400,
                'height' => 100,
            ],
            'sameAs' => [
                'https://github.com/example',
                'https://linkedin.com/company/zao',
                'https://twitter.com/zaoinc',
            ],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'sales',
                'email' => 'billing@example.com',
            ],
        ];
    }

    /**
     * Convert schema array to JSON-LD script tag.
     */
    public function toScriptTag(array $schema): string
    {
        return '<script type="application/ld+json">'.
            json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).
            '</script>';
    }

    /**
     * Extract comparison items from keyword.
     */
    protected function extractComparisonItems(string $keyword): array
    {
        // Extract items from patterns like "A vs B", "A or B", "A compared to B"
        if (preg_match('/(.+?)\s+(?:vs\.?|versus|compared to|or)\s+(.+)/i', $keyword, $matches)) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return ['Option A', 'Option B'];
    }

    /**
     * Extract person name from title.
     */
    protected function extractPersonName(string $title): string
    {
        // Try to extract name before common separators
        if (preg_match('/^([^|\-–—:]+)/', $title, $matches)) {
            return trim($matches[1]);
        }

        return $title;
    }

    /**
     * Extract location from keyword.
     */
    protected function extractLocation(string $keyword): string
    {
        $cities = array_keys(config('seo.locations', []));

        foreach ($cities as $city) {
            if (stripos($keyword, $city) !== false) {
                return $city;
            }
        }

        return config('seo.default_location', 'Portland');
    }

    /**
     * Get state abbreviation for city.
     */
    protected function getStateForCity(string $city): string
    {
        return config("seo.locations.{$city}.state", 'OR');
    }

    /**
     * Get geo coordinates for location.
     */
    protected function getGeoForLocation(string $location): array
    {
        $locationData = config("seo.locations.{$location}");
        $defaultLocation = config('seo.default_location', 'Portland');
        $defaultData = config("seo.locations.{$defaultLocation}");

        $lat = $locationData['lat'] ?? $defaultData['lat'] ?? '45.5155';
        $lng = $locationData['lng'] ?? $defaultData['lng'] ?? '-122.6789';

        return [
            '@type' => 'GeoCoordinates',
            'latitude' => $lat,
            'longitude' => $lng,
        ];
    }

    /**
     * Extract service type from keyword.
     */
    protected function extractService(string $keyword): string
    {
        $services = [
            'Laravel' => 'Laravel Development',
            'WordPress' => 'WordPress Development',
            'WooCommerce' => 'WooCommerce Development',
            'React Native' => 'React Native Development',
            'React' => 'React Development',
            'Vue' => 'Vue.js Development',
            'API' => 'API Development',
            'Mobile' => 'Mobile App Development',
            'E-commerce' => 'E-commerce Development',
            'SaaS' => 'SaaS Development',
        ];

        foreach ($services as $term => $service) {
            if (stripos($keyword, $term) !== false) {
                return $service;
            }
        }

        return 'Custom Software Development';
    }
}
