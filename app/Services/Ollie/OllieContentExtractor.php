<?php

namespace App\Services\Ollie;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deep content extraction from HTML pages for Ollie migration.
 *
 * Extracts structured content from page sections including:
 * - Text content (headings, paragraphs, lists)
 * - Images (URLs, alt text, dimensions)
 * - Links (URLs, text, type)
 * - Forms (fields, actions)
 * - Structured data (schema.org, etc.)
 */
class OllieContentExtractor
{
    private string $baseUrl;

    private string $html;

    private string $cleanHtml;

    /**
     * Extract all content from a URL.
     */
    public function extractFromUrl(string $url): array
    {
        $this->baseUrl = $this->getBaseUrl($url);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; OllieBot/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if (! $response->successful()) {
                return ['success' => false, 'error' => 'HTTP '.$response->status()];
            }

            $this->html = $response->body();
            $this->cleanHtml = $this->stripScriptsAndStyles($this->html);

            return $this->extractPageContent();
        } catch (\Exception $e) {
            Log::error('OllieContentExtractor failed', ['url' => $url, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extract content from HTML string.
     */
    public function extractFromHtml(string $html, string $baseUrl): array
    {
        $this->baseUrl = $baseUrl;
        $this->html = $html;
        $this->cleanHtml = $this->stripScriptsAndStyles($html);

        return $this->extractPageContent();
    }

    /**
     * Main extraction method - identifies sections and extracts content.
     */
    private function extractPageContent(): array
    {
        $sections = [];

        // Extract each section type with full content
        $hero = $this->extractHeroContent();
        if ($hero['detected']) {
            $sections[] = $hero;
        }

        $features = $this->extractFeaturesContent();
        if ($features['detected']) {
            $sections[] = $features;
        }

        $testimonials = $this->extractTestimonialsContent();
        if ($testimonials['detected']) {
            $sections[] = $testimonials;
        }

        $pricing = $this->extractPricingContent();
        if ($pricing['detected']) {
            $sections[] = $pricing;
        }

        $team = $this->extractTeamContent();
        if ($team['detected']) {
            $sections[] = $team;
        }

        $faq = $this->extractFaqContent();
        if ($faq['detected']) {
            $sections[] = $faq;
        }

        $cta = $this->extractCtaContent();
        if ($cta['detected']) {
            $sections[] = $cta;
        }

        $contact = $this->extractContactContent();
        if ($contact['detected']) {
            $sections[] = $contact;
        }

        $gallery = $this->extractGalleryContent();
        if ($gallery['detected']) {
            $sections[] = $gallery;
        }

        $blog = $this->extractBlogContent();
        if ($blog['detected']) {
            $sections[] = $blog;
        }

        // Extract page-level metadata
        $metadata = $this->extractPageMetadata();

        return [
            'success' => true,
            'sections' => $sections,
            'metadata' => $metadata,
            'images' => $this->extractAllImages(),
        ];
    }

    /**
     * Extract hero section content.
     */
    private function extractHeroContent(): array
    {
        $result = [
            'detected' => false,
            'type' => 'hero',
            'confidence' => 0,
            'content' => [],
        ];

        // Find hero container
        $heroHtml = $this->findSectionHtml(['hero', 'banner', 'jumbotron', 'masthead', 'splash', 'intro']);

        // If no explicit hero, check first major section
        if (! $heroHtml) {
            $heroHtml = $this->getFirstMajorSection();
        }

        if (! $heroHtml) {
            return $result;
        }

        $content = [];
        $indicators = 0;

        // Extract headline (h1)
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $heroHtml, $match)) {
            $content['headline'] = $this->cleanText($match[1]);
            $indicators += 3;
        }

        // Extract subheadline (h2 or p with lead/subtitle class)
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $heroHtml, $match)) {
            $content['subheadline'] = $this->cleanText($match[1]);
            $indicators += 1;
        } elseif (preg_match('/<p[^>]*class=["\'][^"\']*(?:lead|subtitle|tagline|description)[^"\']*["\'][^>]*>(.*?)<\/p>/is', $heroHtml, $match)) {
            $content['subheadline'] = $this->cleanText($match[1]);
            $indicators += 1;
        }

        // Extract body text (paragraphs)
        $paragraphs = $this->extractParagraphs($heroHtml);
        if (! empty($paragraphs)) {
            $content['body'] = implode("\n\n", array_slice($paragraphs, 0, 3));
            $indicators += 1;
        }

        // Extract CTA buttons
        $buttons = $this->extractButtons($heroHtml);
        if (! empty($buttons)) {
            $content['buttons'] = $buttons;
            $indicators += 2;
        }

        // Extract background image
        $bgImage = $this->extractBackgroundImage($heroHtml);
        if ($bgImage) {
            $content['background_image'] = $bgImage;
            $indicators += 1;
        }

        // Extract featured image
        $featuredImage = $this->extractFirstImage($heroHtml);
        if ($featuredImage) {
            $content['image'] = $featuredImage;
            $indicators += 1;
        }

        // Extract logo bar (social proof)
        $logos = $this->extractLogoImages($heroHtml);
        if (! empty($logos)) {
            $content['logos'] = $logos;
            $indicators += 1;
        }

        if ($indicators >= 3) {
            $result['detected'] = true;
            $result['confidence'] = min(100, $indicators * 12);
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Extract features section content.
     */
    private function extractFeaturesContent(): array
    {
        $result = [
            'detected' => false,
            'type' => 'features',
            'confidence' => 0,
            'content' => [],
        ];

        $sectionHtml = $this->findSectionHtml(['features', 'services', 'benefits', 'capabilities', 'what-we-do', 'why-choose']);
        $searchHtml = $sectionHtml ?: $this->cleanHtml;

        $content = [];
        $indicators = 0;

        if (preg_match('/class=["\'][^"\']*(?:features?|services?|benefits?|capabilities)[^"\']*["\']/', $this->cleanHtml)) {
            $indicators += 3;
        }

        // Extract section heading
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $searchHtml, $match)) {
            $content['section_title'] = $this->cleanText($match[1]);
            $indicators += 1;
        }

        // Extract section intro
        if (preg_match('/<h2[^>]*>.*?<\/h2>\s*<p[^>]*>(.*?)<\/p>/is', $searchHtml, $match)) {
            $content['section_intro'] = $this->cleanText($match[1]);
        }

        // Extract feature items
        $features = [];

        // Pattern 1: Cards with icons/images + headings + text
        preg_match_all('/<(?:div|article|li)[^>]*class=["\'][^"\']*(?:feature|service|benefit|card|item)[^"\']*["\'][^>]*>(.*?)<\/(?:div|article|li)>/is', $searchHtml, $cardMatches);

        foreach ($cardMatches[1] as $cardHtml) {
            $feature = $this->extractFeatureItem($cardHtml);
            if ($feature) {
                $features[] = $feature;
            }
        }

        // Pattern 2: Grid of items with icons + headings
        if (empty($features)) {
            preg_match_all('/<(?:div|article|li)[^>]*>.*?(?:<svg|<i[^>]*class|<img[^>]*icon).*?<h[2-4][^>]*>([^<]+)/is', $searchHtml, $iconMatches);
            if (count($iconMatches[1]) >= 3) {
                $indicators += 2;
                foreach ($iconMatches[1] as $title) {
                    $features[] = ['title' => $this->cleanText($title)];
                }
            }
        }

        // Pattern 3: Multiple h3/h4 headings (feature grid)
        if (empty($features)) {
            preg_match_all('/<h[3-4][^>]*>(.*?)<\/h[3-4]>\s*(?:<p[^>]*>(.*?)<\/p>)?/is', $searchHtml, $simpleMatches, PREG_SET_ORDER);
            if (count($simpleMatches) >= 3) {
                $indicators += 2;
                foreach ($simpleMatches as $match) {
                    $features[] = [
                        'title' => $this->cleanText($match[1]),
                        'description' => isset($match[2]) ? $this->cleanText($match[2]) : '',
                    ];
                }
            }
        }

        if (! empty($features)) {
            $content['features'] = array_slice($features, 0, 12);
            $indicators += count($features) >= 3 ? 2 : 1;
        }

        if ($indicators >= 2) {
            $result['detected'] = true;
            $result['confidence'] = min(100, $indicators * 15);
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Extract a single feature item from HTML.
     */
    private function extractFeatureItem(string $html): ?array
    {
        $item = [];

        // Get icon (SVG, icon class, or emoji)
        if (preg_match('/<(?:svg|i)[^>]*>.*?<\/(?:svg|i)>/is', $html)) {
            $item['has_icon'] = true;
        }
        if (preg_match('/(?:[\x{1F300}-\x{1F9FF}])/u', $html, $emoji)) {
            $item['icon'] = $emoji[0];
        }

        // Get image
        $image = $this->extractFirstImage($html);
        if ($image) {
            $item['image'] = $image;
        }

        // Get title (h3, h4, or strong)
        if (preg_match('/<h[3-5][^>]*>(.*?)<\/h[3-5]>/is', $html, $match)) {
            $item['title'] = $this->cleanText($match[1]);
        } elseif (preg_match('/<strong[^>]*>(.*?)<\/strong>/is', $html, $match)) {
            $item['title'] = $this->cleanText($match[1]);
        }

        // Get description
        $paragraphs = $this->extractParagraphs($html);
        if (! empty($paragraphs)) {
            $item['description'] = $paragraphs[0];
        }

        // Get link
        if (preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $linkMatch)) {
            $item['link'] = [
                'url' => $this->resolveUrl($linkMatch[1]),
                'text' => $this->cleanText($linkMatch[2]),
            ];
        }

        return ! empty($item['title']) ? $item : null;
    }

    /**
     * Extract testimonials content.
     */
    private function extractTestimonialsContent(): array
    {
        $result = [
            'detected' => false,
            'type' => 'testimonials',
            'confidence' => 0,
            'content' => [],
        ];

        $sectionHtml = $this->findSectionHtml(['testimonial', 'review', 'quote', 'feedback', 'what-clients-say', 'success-stories']);
        $searchHtml = $sectionHtml ?: $this->cleanHtml;

        $content = [];
        $indicators = 0;
        $testimonials = [];

        if (preg_match('/class=["\'][^"\']*(?:testimonial|review|quote|client|feedback)[^"\']*["\']/', $this->cleanHtml)) {
            $indicators += 3;
        }

        // Extract section heading
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $searchHtml, $match)) {
            $content['section_title'] = $this->cleanText($match[1]);
        }

        // Pattern 1: Blockquotes
        preg_match_all('/<blockquote[^>]*>(.*?)<\/blockquote>/is', $searchHtml, $blockquotes);
        foreach ($blockquotes[1] as $quote) {
            $testimonial = $this->extractTestimonialItem($quote);
            if ($testimonial) {
                $testimonials[] = $testimonial;
                $indicators += 2;
            }
        }

        // Quote marks or cite elements
        if (preg_match('/["""]|<cite|class=["\'][^"\']*(?:author|name|attribution)/', $searchHtml)) {
            $indicators += 1;
        }

        // Star ratings
        if (preg_match('/(?:★|star|rating|⭐)/i', $searchHtml)) {
            $indicators += 1;
        }

        // Pattern 2: Testimonial cards
        if (empty($testimonials)) {
            preg_match_all('/<(?:div|article)[^>]*class=["\'][^"\']*(?:testimonial|review|quote)[^"\']*["\'][^>]*>(.*?)<\/(?:div|article)>/is', $searchHtml, $cardMatches);
            foreach ($cardMatches[1] as $cardHtml) {
                $testimonial = $this->extractTestimonialItem($cardHtml);
                if ($testimonial) {
                    $testimonials[] = $testimonial;
                }
            }
        }

        if (! empty($testimonials)) {
            $content['testimonials'] = array_slice($testimonials, 0, 10);
            $indicators += count($testimonials) >= 2 ? 4 : 2;
        }

        // Extract client logos
        $logos = $this->extractLogoImages($sectionHtml);
        if (! empty($logos)) {
            $content['client_logos'] = $logos;
            $indicators += 1;
        }

        if ($indicators >= 2) {
            $result['detected'] = true;
            $result['confidence'] = min(100, $indicators * 15);
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Extract a single testimonial item.
     */
    private function extractTestimonialItem(string $html): ?array
    {
        $item = [];

        // Extract quote text
        $text = $this->cleanText($html);
        // Remove author name from end if present
        $text = preg_replace('/[-–—]\s*[A-Z][a-z]+\s+[A-Z][a-z]+.*$/s', '', $text);
        $item['quote'] = trim($text);

        // Extract author info
        if (preg_match('/<(?:cite|span|p)[^>]*class=["\'][^"\']*(?:author|name|attribution|cite)[^"\']*["\'][^>]*>(.*?)<\/(?:cite|span|p)>/is', $html, $match)) {
            $item['author'] = $this->cleanText($match[1]);
        } elseif (preg_match('/[-–—]\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/s', $html, $match)) {
            $item['author'] = trim($match[1]);
        }

        // Extract title/company
        if (preg_match('/<(?:span|p)[^>]*class=["\'][^"\']*(?:title|role|company|position)[^"\']*["\'][^>]*>(.*?)<\/(?:span|p)>/is', $html, $match)) {
            $item['title'] = $this->cleanText($match[1]);
        }

        // Extract avatar/photo
        $image = $this->extractFirstImage($html);
        if ($image && $this->looksLikeAvatar($image)) {
            $item['avatar'] = $image;
        }

        // Extract rating
        if (preg_match_all('/★|⭐/u', $html, $stars)) {
            $item['rating'] = count($stars[0]);
        }

        return ! empty($item['quote']) ? $item : null;
    }

    /**
     * Extract pricing section content.
     */
    private function extractPricingContent(): array
    {
        $result = [
            'detected' => false,
            'type' => 'pricing',
            'confidence' => 0,
            'content' => [],
        ];

        $sectionHtml = $this->findSectionHtml(['pricing', 'plans', 'packages', 'tiers', 'membership']);
        $searchHtml = $sectionHtml ?: $this->cleanHtml;

        $content = [];
        $indicators = 0;
        $plans = [];

        if (preg_match('/class=["\'][^"\']*(?:pricing|plans?|packages?|tiers?)[^"\']*["\']/', $this->cleanHtml)) {
            $indicators += 3;
        }

        // Price patterns ($XX, €XX, £XX)
        if (preg_match_all('/[\$€£]\s*\d+(?:\.\d{2})?(?:\s*\/\s*(?:mo|month|year|yr))?/i', $searchHtml, $priceMatches)) {
            $indicators += 3;
        }

        // Plan names (Basic, Pro, Enterprise, etc.)
        if (preg_match('/(?:Basic|Starter|Pro|Professional|Enterprise|Premium|Business|Free|Standard)/i', $searchHtml)) {
            $indicators += 1;
        }

        // Extract section heading
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $searchHtml, $match)) {
            $content['section_title'] = $this->cleanText($match[1]);
        }

        // Find pricing cards
        preg_match_all('/<(?:div|article)[^>]*class=["\'][^"\']*(?:pricing|plan|package|tier|card)[^"\']*["\'][^>]*>(.*?)<\/(?:div|article)>/is', $searchHtml, $cardMatches);

        foreach ($cardMatches[1] as $cardHtml) {
            $plan = $this->extractPricingPlan($cardHtml);
            if ($plan) {
                $plans[] = $plan;
            }
        }

        if (! empty($plans)) {
            $content['plans'] = array_slice($plans, 0, 5);
            $indicators += 4;
        }

        // Check for pricing toggle (monthly/yearly)
        if (preg_match('/(?:monthly|yearly|annual|billed)/i', $sectionHtml)) {
            $content['has_toggle'] = true;
            $indicators += 1;
        }

        if ($indicators >= 2) {
            $result['detected'] = true;
            $result['confidence'] = min(100, $indicators * 15);
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Extract a single pricing plan.
     */
    private function extractPricingPlan(string $html): ?array
    {
        $plan = [];

        // Plan name
        if (preg_match('/<h[2-4][^>]*>(.*?)<\/h[2-4]>/is', $html, $match)) {
            $plan['name'] = $this->cleanText($match[1]);
        }

        // Price
        if (preg_match('/([\$€£])\s*(\d+(?:[.,]\d{2})?)/i', $html, $priceMatch)) {
            $plan['currency'] = $priceMatch[1];
            $plan['price'] = $priceMatch[2];
        }

        // Period
        if (preg_match('/\/\s*(mo(?:nth)?|yr|year|week)/i', $html, $periodMatch)) {
            $plan['period'] = $periodMatch[1];
        }

        // Features list
        $features = [];
        preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $listItems);
        foreach ($listItems[1] as $item) {
            $text = $this->cleanText($item);
            if (! empty($text) && strlen($text) < 100) {
                $features[] = $text;
            }
        }
        if (! empty($features)) {
            $plan['features'] = array_slice($features, 0, 15);
        }

        // CTA button
        $buttons = $this->extractButtons($html);
        if (! empty($buttons)) {
            $plan['cta'] = $buttons[0];
        }

        // Featured/popular flag
        if (preg_match('/(?:popular|recommended|best|featured)/i', $html)) {
            $plan['featured'] = true;
        }

        return ! empty($plan['name']) ? $plan : null;
    }

    /**
     * Extract team section content.
     */
    private function extractTeamContent(): array
    {
        $result = [
            'detected' => false,
            'type' => 'team',
            'confidence' => 0,
            'content' => [],
        ];

        $sectionHtml = $this->findSectionHtml(['team', 'staff', 'people', 'leadership', 'about-us', 'meet-the-team']);
        $searchHtml = $sectionHtml ?: $this->cleanHtml;

        $content = [];
        $indicators = 0;
        $members = [];

        if (preg_match('/class=["\'][^"\']*(?:team|staff|members?|about-us|leadership|people)[^"\']*["\']/', $this->cleanHtml)) {
            $indicators += 3;
        }

        // Job titles
        if (preg_match_all('/(?:CEO|CTO|Founder|Director|Manager|Designer|Developer|Engineer|Lead|Head of)/i', $searchHtml, $titleMatches)) {
            if (count($titleMatches[0]) >= 2) {
                $indicators += 2;
            }
        }

        // Extract section heading
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $searchHtml, $match)) {
            $content['section_title'] = $this->cleanText($match[1]);
        }

        // Find team member cards
        preg_match_all('/<(?:div|article|li)[^>]*class=["\'][^"\']*(?:team|member|staff|person|card)[^"\']*["\'][^>]*>(.*?)<\/(?:div|article|li)>/is', $searchHtml, $cardMatches);

        // Also look for person-like structures (image + name + title)
        if (empty($cardMatches[1])) {
            preg_match_all('/<img[^>]*(?:avatar|profile|team|headshot)[^>]*>.*?<(?:h[3-5]|p|span)[^>]*>([^<]+)/is', $searchHtml, $personMatches);
            if (count($personMatches[1]) >= 2) {
                $indicators += 2;
                foreach ($personMatches[1] as $name) {
                    $members[] = ['name' => $this->cleanText($name)];
                }
            }
        }

        foreach ($cardMatches[1] as $cardHtml) {
            $member = $this->extractTeamMember($cardHtml);
            if ($member) {
                $members[] = $member;
            }
        }

        if (! empty($members)) {
            $content['members'] = array_slice($members, 0, 20);
            $indicators += count($members) >= 3 ? 4 : 2;
        }

        if ($indicators >= 2) {
            $result['detected'] = true;
            $result['confidence'] = min(100, $indicators * 15);
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Extract a single team member.
     */
    private function extractTeamMember(string $html): ?array
    {
        $member = [];

        // Name
        if (preg_match('/<h[3-5][^>]*>(.*?)<\/h[3-5]>/is', $html, $match)) {
            $member['name'] = $this->cleanText($match[1]);
        }

        // Title/Role
        if (preg_match('/<(?:p|span)[^>]*class=["\'][^"\']*(?:title|role|position|job)[^"\']*["\'][^>]*>(.*?)<\/(?:p|span)>/is', $html, $match)) {
            $member['title'] = $this->cleanText($match[1]);
        }

        // Photo
        $image = $this->extractFirstImage($html);
        if ($image) {
            $member['photo'] = $image;
        }

        // Bio
        $paragraphs = $this->extractParagraphs($html);
        if (! empty($paragraphs)) {
            $member['bio'] = $paragraphs[0];
        }

        // Social links
        $socials = $this->extractSocialLinks($html);
        if (! empty($socials)) {
            $member['social_links'] = $socials;
        }

        return ! empty($member['name']) ? $member : null;
    }

    /**
     * Extract FAQ section content.
     */
    private function extractFaqContent(): array
    {
        $result = [
            'detected' => false,
            'type' => 'faq',
            'confidence' => 0,
            'content' => [],
        ];

        $sectionHtml = $this->findSectionHtml(['faq', 'questions', 'accordion', 'help']);
        if (! $sectionHtml && ! preg_match('/frequently\s*asked|FAQ/i', $this->cleanHtml)) {
            return $result;
        }

        if (! $sectionHtml) {
            $sectionHtml = $this->cleanHtml;
        }

        $content = [];
        $indicators = 0;
        $faqs = [];

        // Extract section heading
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $sectionHtml, $match)) {
            $content['section_title'] = $this->cleanText($match[1]);
        }

        // Pattern 1: Definition lists
        preg_match_all('/<dt[^>]*>(.*?)<\/dt>\s*<dd[^>]*>(.*?)<\/dd>/is', $sectionHtml, $dtMatches, PREG_SET_ORDER);
        foreach ($dtMatches as $match) {
            $faqs[] = [
                'question' => $this->cleanText($match[1]),
                'answer' => $this->cleanText($match[2]),
            ];
        }

        // Pattern 2: Details/summary
        if (empty($faqs)) {
            preg_match_all('/<summary[^>]*>(.*?)<\/summary>(.*?)(?=<\/details>)/is', $sectionHtml, $detailsMatches, PREG_SET_ORDER);
            foreach ($detailsMatches as $match) {
                $faqs[] = [
                    'question' => $this->cleanText($match[1]),
                    'answer' => $this->cleanText($match[2]),
                ];
            }
        }

        // Pattern 3: Heading + content pairs with question marks
        if (empty($faqs)) {
            preg_match_all('/<h[3-5][^>]*>([^<]*\?)<\/h[3-5]>\s*(?:<(?:p|div)[^>]*>(.*?)<\/(?:p|div)>)?/is', $sectionHtml, $hMatches, PREG_SET_ORDER);
            foreach ($hMatches as $match) {
                if (isset($match[2])) {
                    $faqs[] = [
                        'question' => $this->cleanText($match[1]),
                        'answer' => $this->cleanText($match[2]),
                    ];
                }
            }
        }

        if (! empty($faqs)) {
            $content['faqs'] = array_slice($faqs, 0, 20);
            $indicators += count($faqs) >= 3 ? 4 : 2;
        }

        if ($indicators >= 2) {
            $result['detected'] = true;
            $result['confidence'] = min(100, $indicators * 15);
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Extract CTA section content.
     */
    private function extractCtaContent(): array
    {
        $result = [
            'detected' => false,
            'type' => 'cta',
            'confidence' => 0,
            'content' => [],
        ];

        $sectionHtml = $this->findSectionHtml(['cta', 'call-to-action', 'action', 'signup', 'subscribe', 'newsletter', 'get-started']);
        $searchHtml = $sectionHtml ?: $this->cleanHtml;

        $content = [];
        $indicators = 0;

        if (preg_match('/class=["\'][^"\']*(?:cta|call-to-action|action-section|signup)[^"\']*["\']/', $this->cleanHtml)) {
            $indicators += 3;
        }

        // Strong action text
        if (preg_match('/(?:Get Started|Sign Up|Try|Start|Join|Subscribe|Contact Us|Learn More|Free Trial)/i', $searchHtml, $actionMatch)) {
            $indicators += 2;
            $content['action_text'] = $actionMatch[0];
        }

        // Email input + button combo (newsletter)
        if (preg_match('/type=["\']email["\'].*?(?:button|submit)/is', $searchHtml)) {
            $indicators += 2;
        }

        // Headline
        if (preg_match('/<h[2-3][^>]*>(.*?)<\/h[2-3]>/is', $searchHtml, $match)) {
            $content['headline'] = $this->cleanText($match[1]);
            $indicators += 1;
        }

        // Subheadline/body
        $paragraphs = $this->extractParagraphs($searchHtml);
        if (! empty($paragraphs)) {
            $content['body'] = $paragraphs[0];
            $indicators += 1;
        }

        // Buttons
        $buttons = $this->extractButtons($searchHtml);
        if (! empty($buttons)) {
            $content['buttons'] = $buttons;
            $indicators += 2;
        }

        // Form (newsletter signup)
        if (preg_match('/<form[^>]*>(.*?)<\/form>/is', $searchHtml, $formMatch)) {
            $form = $this->extractForm($formMatch[1]);
            if ($form) {
                $content['form'] = $form;
                $indicators += 2;
            }
        }

        if ($indicators >= 3) {
            $result['detected'] = true;
            $result['confidence'] = min(100, $indicators * 12);
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Extract contact section content.
     */
    private function extractContactContent(): array
    {
        $result = [
            'detected' => false,
            'type' => 'contact',
            'confidence' => 0,
            'content' => [],
        ];

        $sectionHtml = $this->findSectionHtml(['contact', 'get-in-touch', 'reach-us', 'connect']);
        if (! $sectionHtml) {
            return $result;
        }

        $content = [];
        $indicators = 0;

        // Section heading
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $sectionHtml, $match)) {
            $content['section_title'] = $this->cleanText($match[1]);
        }

        // Contact form
        if (preg_match('/<form[^>]*>(.*?)<\/form>/is', $sectionHtml, $formMatch)) {
            $form = $this->extractForm($formMatch[1]);
            if ($form) {
                $content['form'] = $form;
                $indicators += 3;
            }
        }

        // Contact info
        $contactInfo = [];

        // Email
        if (preg_match('/mailto:([^\s"\']+)/i', $sectionHtml, $match)) {
            $contactInfo['email'] = $match[1];
            $indicators += 1;
        }

        // Phone
        if (preg_match('/tel:([^\s"\']+)/i', $sectionHtml, $match)) {
            $contactInfo['phone'] = $match[1];
            $indicators += 1;
        } elseif (preg_match('/(?:\+1|1)?[\s.-]?\(?[0-9]{3}\)?[\s.-]?[0-9]{3}[\s.-]?[0-9]{4}/', $sectionHtml, $match)) {
            $contactInfo['phone'] = $match[0];
            $indicators += 1;
        }

        // Address
        if (preg_match('/<address[^>]*>(.*?)<\/address>/is', $sectionHtml, $match)) {
            $contactInfo['address'] = $this->cleanText($match[1]);
            $indicators += 1;
        }

        if (! empty($contactInfo)) {
            $content['contact_info'] = $contactInfo;
        }

        // Social links
        $socials = $this->extractSocialLinks($sectionHtml);
        if (! empty($socials)) {
            $content['social_links'] = $socials;
            $indicators += 1;
        }

        // Map embed
        if (preg_match('/(?:maps\.google|google\.com\/maps|goo\.gl\/maps)/i', $sectionHtml)) {
            $content['has_map'] = true;
            $indicators += 1;
        }

        if ($indicators >= 2) {
            $result['detected'] = true;
            $result['confidence'] = min(100, $indicators * 12);
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Extract gallery section content.
     */
    private function extractGalleryContent(): array
    {
        $result = [
            'detected' => false,
            'type' => 'gallery',
            'confidence' => 0,
            'content' => [],
        ];

        $sectionHtml = $this->findSectionHtml(['gallery', 'portfolio', 'showcase', 'work', 'projects', 'photos']);
        if (! $sectionHtml) {
            return $result;
        }

        $content = [];
        $indicators = 0;

        // Section heading
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $sectionHtml, $match)) {
            $content['section_title'] = $this->cleanText($match[1]);
        }

        // Extract images
        $images = $this->extractAllImagesFromHtml($sectionHtml);
        if (count($images) >= 4) {
            $content['images'] = array_slice($images, 0, 30);
            $indicators += 4;
        }

        // Check for lightbox
        if (preg_match('/(?:lightbox|fancybox|modal|zoom)/i', $sectionHtml)) {
            $content['has_lightbox'] = true;
            $indicators += 1;
        }

        // Check for categories/filters
        if (preg_match('/class=["\'][^"\']*(?:filter|category|tab)/i', $sectionHtml)) {
            $content['has_filters'] = true;
            $indicators += 1;
        }

        if ($indicators >= 3) {
            $result['detected'] = true;
            $result['confidence'] = min(100, $indicators * 12);
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Extract blog section content.
     */
    private function extractBlogContent(): array
    {
        $result = [
            'detected' => false,
            'type' => 'blog',
            'confidence' => 0,
            'content' => [],
        ];

        $sectionHtml = $this->findSectionHtml(['blog', 'posts', 'articles', 'news', 'stories', 'updates']);
        if (! $sectionHtml) {
            return $result;
        }

        $content = [];
        $indicators = 0;
        $posts = [];

        // Section heading
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $sectionHtml, $match)) {
            $content['section_title'] = $this->cleanText($match[1]);
        }

        // Find post cards
        preg_match_all('/<article[^>]*>(.*?)<\/article>/is', $sectionHtml, $articleMatches);
        foreach ($articleMatches[1] as $articleHtml) {
            $post = $this->extractBlogPost($articleHtml);
            if ($post) {
                $posts[] = $post;
            }
        }

        // Fallback: div-based cards
        if (empty($posts)) {
            preg_match_all('/<(?:div|li)[^>]*class=["\'][^"\']*(?:post|article|card|blog)[^"\']*["\'][^>]*>(.*?)<\/(?:div|li)>/is', $sectionHtml, $cardMatches);
            foreach ($cardMatches[1] as $cardHtml) {
                $post = $this->extractBlogPost($cardHtml);
                if ($post) {
                    $posts[] = $post;
                }
            }
        }

        if (! empty($posts)) {
            $content['posts'] = array_slice($posts, 0, 10);
            $indicators += count($posts) >= 3 ? 4 : 2;
        }

        if ($indicators >= 2) {
            $result['detected'] = true;
            $result['confidence'] = min(100, $indicators * 15);
            $result['content'] = $content;
        }

        return $result;
    }

    /**
     * Extract a single blog post preview.
     */
    private function extractBlogPost(string $html): ?array
    {
        $post = [];

        // Title
        if (preg_match('/<h[2-4][^>]*>(.*?)<\/h[2-4]>/is', $html, $match)) {
            $post['title'] = $this->cleanText($match[1]);
        }

        // Excerpt
        $paragraphs = $this->extractParagraphs($html);
        if (! empty($paragraphs)) {
            $post['excerpt'] = Str::limit($paragraphs[0], 200);
        }

        // Featured image
        $image = $this->extractFirstImage($html);
        if ($image) {
            $post['image'] = $image;
        }

        // Date
        if (preg_match('/<time[^>]*datetime=["\']([^"\']+)["\']/', $html, $match)) {
            $post['date'] = $match[1];
        }

        // Author
        if (preg_match('/<(?:span|a)[^>]*class=["\'][^"\']*author[^"\']*["\'][^>]*>(.*?)<\/(?:span|a)>/is', $html, $match)) {
            $post['author'] = $this->cleanText($match[1]);
        }

        // Link
        if (preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>.*?(?:read|more|continue)/is', $html, $match)) {
            $post['url'] = $this->resolveUrl($match[1]);
        } elseif (preg_match('/<h[2-4][^>]*>\s*<a[^>]+href=["\']([^"\']+)["\']/', $html, $match)) {
            $post['url'] = $this->resolveUrl($match[1]);
        }

        // Categories/tags
        preg_match_all('/<a[^>]+class=["\'][^"\']*(?:category|tag)[^"\']*["\'][^>]*>(.*?)<\/a>/is', $html, $catMatches);
        if (! empty($catMatches[1])) {
            $post['categories'] = array_map([$this, 'cleanText'], $catMatches[1]);
        }

        return ! empty($post['title']) ? $post : null;
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Find a section by class names.
     */
    private function findSectionHtml(array $keywords): ?string
    {
        $pattern = implode('|', array_map('preg_quote', $keywords));

        // Try section/div with matching class or id
        if (preg_match('/<(?:section|div|aside)[^>]*(?:class|id)=["\'][^"\']*(?:'.$pattern.')[^"\']*["\'][^>]*>(.*?)<\/(?:section|div|aside)>/is', $this->cleanHtml, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Get the first major section (likely hero).
     */
    private function getFirstMajorSection(): ?string
    {
        // Get content after header
        $html = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $this->cleanHtml);

        // Get first section or main content div
        if (preg_match('/<(?:section|main)[^>]*>(.*?)<\/(?:section|main)>/is', $html, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Strip scripts and styles.
     */
    private function stripScriptsAndStyles(string $html): string
    {
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $html);

        return $html;
    }

    /**
     * Clean text content.
     */
    private function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Extract paragraphs.
     */
    private function extractParagraphs(string $html): array
    {
        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $matches);
        $paragraphs = [];
        foreach ($matches[1] as $p) {
            $text = $this->cleanText($p);
            if (strlen($text) > 20) {
                $paragraphs[] = $text;
            }
        }

        return $paragraphs;
    }

    /**
     * Extract buttons/CTAs.
     */
    private function extractButtons(string $html): array
    {
        $buttons = [];

        preg_match_all('/<a[^>]+class=["\'][^"\']*(?:btn|button|cta)[^"\']*["\'][^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $buttons[] = [
                'url' => $this->resolveUrl($match[1]),
                'text' => $this->cleanText($match[2]),
                'type' => $this->detectButtonType($match[0]),
            ];
        }

        // Also check button elements
        preg_match_all('/<button[^>]*>(.*?)<\/button>/is', $html, $buttonMatches);
        foreach ($buttonMatches[1] as $buttonText) {
            $text = $this->cleanText($buttonText);
            if (! empty($text)) {
                $buttons[] = [
                    'text' => $text,
                    'type' => 'submit',
                ];
            }
        }

        return array_slice($buttons, 0, 4);
    }

    /**
     * Detect button type (primary, secondary, etc).
     */
    private function detectButtonType(string $html): string
    {
        if (preg_match('/(?:primary|main|cta)/i', $html)) {
            return 'primary';
        }
        if (preg_match('/(?:secondary|outline|ghost)/i', $html)) {
            return 'secondary';
        }

        return 'default';
    }

    /**
     * Extract first image.
     */
    private function extractFirstImage(string $html): ?array
    {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*(?:alt=["\']([^"\']*)["\'])?/i', $html, $match)) {
            return [
                'url' => $this->resolveUrl($match[1]),
                'alt' => $match[2] ?? '',
            ];
        }

        return null;
    }

    /**
     * Extract all images from HTML segment.
     */
    private function extractAllImagesFromHtml(string $html): array
    {
        $images = [];
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*(?:alt=["\']([^"\']*)["\'])?/i', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $url = $this->resolveUrl($match[1]);
            if (! $this->isDecorativeImage($url)) {
                $images[] = [
                    'url' => $url,
                    'alt' => $match[2] ?? '',
                ];
            }
        }

        return $images;
    }

    /**
     * Extract all images from page.
     */
    private function extractAllImages(): array
    {
        return $this->extractAllImagesFromHtml($this->cleanHtml);
    }

    /**
     * Extract logo images.
     */
    private function extractLogoImages(string $html): array
    {
        $logos = [];

        // Look for images in logo containers
        if (preg_match('/<(?:div|ul)[^>]*class=["\'][^"\']*(?:logo|client|partner|brand)[^"\']*["\'][^>]*>(.*?)<\/(?:div|ul)>/is', $html, $match)) {
            $logos = $this->extractAllImagesFromHtml($match[1]);
        }

        return array_slice($logos, 0, 10);
    }

    /**
     * Extract background image.
     */
    private function extractBackgroundImage(string $html): ?array
    {
        if (preg_match('/(?:background-image|background)\s*:\s*url\(["\']?([^"\')\s]+)["\']?\)/i', $html, $match)) {
            return [
                'url' => $this->resolveUrl($match[1]),
                'type' => 'background',
            ];
        }

        return null;
    }

    /**
     * Extract form fields.
     */
    private function extractForm(string $html): ?array
    {
        $form = ['fields' => []];

        // Extract form action
        if (preg_match('/action=["\']([^"\']+)["\']/', $html, $match)) {
            $form['action'] = $this->resolveUrl($match[1]);
        }

        // Extract input fields
        preg_match_all('/<input[^>]+>/i', $html, $inputMatches);
        foreach ($inputMatches[0] as $input) {
            $field = [];
            if (preg_match('/type=["\']([^"\']+)["\']/', $input, $m)) {
                $field['type'] = $m[1];
            }
            if (preg_match('/name=["\']([^"\']+)["\']/', $input, $m)) {
                $field['name'] = $m[1];
            }
            if (preg_match('/placeholder=["\']([^"\']+)["\']/', $input, $m)) {
                $field['placeholder'] = $m[1];
            }
            if (preg_match('/required/', $input)) {
                $field['required'] = true;
            }

            if (! empty($field['name']) && ! in_array($field['type'] ?? '', ['hidden', 'submit'])) {
                $form['fields'][] = $field;
            }
        }

        // Extract textareas
        if (preg_match_all('/<textarea[^>]*name=["\']([^"\']+)["\'][^>]*>/i', $html, $textareaMatches)) {
            foreach ($textareaMatches[1] as $name) {
                $form['fields'][] = ['type' => 'textarea', 'name' => $name];
            }
        }

        return ! empty($form['fields']) ? $form : null;
    }

    /**
     * Extract social links.
     */
    private function extractSocialLinks(string $html): array
    {
        $socials = [];
        $platforms = ['facebook', 'twitter', 'x.com', 'linkedin', 'instagram', 'youtube', 'tiktok', 'github'];

        foreach ($platforms as $platform) {
            if (preg_match('/href=["\']([^"\']*'.preg_quote($platform).'[^"\']*)["\']/', $html, $match)) {
                $socials[$platform] = $match[1];
            }
        }

        return $socials;
    }

    /**
     * Extract page metadata.
     */
    private function extractPageMetadata(): array
    {
        $metadata = [];

        // Title
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $this->html, $match)) {
            $metadata['title'] = $this->cleanText($match[1]);
        }

        // Meta description
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/', $this->html, $match)) {
            $metadata['description'] = $match[1];
        }

        // OG image
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $this->html, $match)) {
            $metadata['og_image'] = $match[1];
        }

        return $metadata;
    }

    /**
     * Check if image is likely an avatar.
     */
    private function looksLikeAvatar(array $image): bool
    {
        $url = $image['url'] ?? '';

        return preg_match('/(?:avatar|profile|headshot|photo|face|team|author)/i', $url) ||
               preg_match('/(?:gravatar|wp-content\/uploads)/i', $url);
    }

    /**
     * Check if image is decorative (spacer, etc).
     */
    private function isDecorativeImage(string $url): bool
    {
        return preg_match('/(?:spacer|pixel|blank|transparent|1x1)/i', $url) ||
               preg_match('/\.gif$/i', $url);
    }

    /**
     * Resolve relative URL.
     */
    private function resolveUrl(string $url): string
    {
        if (str_starts_with($url, 'http') || str_starts_with($url, '//')) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return $this->baseUrl.$url;
        }

        return $this->baseUrl.'/'.$url;
    }

    /**
     * Get base URL from full URL.
     */
    private function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);

        return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');
    }
}
