<?php

namespace App\Services\Rfp;

use App\Jobs\EvaluateRfpJob;
use App\Models\RfpLearningInsight;
use App\Models\RfpOpportunity;
use App\Services\AI\ClaudeCliService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RfpDiscoveryService
{
    public function __construct(
        protected ClaudeCliService $claude,
        protected RfpPreFilter $preFilter,
    ) {}

    /**
     * Parse a multi-opportunity teaser email into individual opportunities.
     *
     * @param  array{sender_name?: string, sender_email?: string, rfp_source_id?: int}  $sourceConfig
     * @return array<int, array{title: string, organization: string, description: string, budget_min: ?float, budget_max: ?float, url: ?string, tech_keywords: array}>
     */
    public function parseEmailTeasers(string $emailBody, array $sourceConfig = []): array
    {
        if (strlen(trim($emailBody)) < 30) {
            Log::debug('RfpDiscoveryService: email body too short to parse', [
                'length' => strlen($emailBody),
            ]);

            return [];
        }

        $systemPrompt = <<<'PROMPT'
You are an RFP teaser parser for a web development agency. Extract individual bid/RFP opportunities from this email.

Return a JSON array of opportunities. Each opportunity should have:
- "title": A descriptive title combining the organization and project name
- "organization": The issuing company, government agency, or entity
- "description": What they need (brief summary of the work)
- "budget_min": Minimum budget as a number (parse ranges like "$200K-$350K" into 200000), or null if not mentioned
- "budget_max": Maximum budget as a number, or null if not mentioned
- "url": Any URL/link associated with this opportunity, or null
- "tech_keywords": Array of mentioned technologies (WordPress, CMS, CRM, SEO, AI, Drupal, Laravel, React, etc.)
- "submission_deadline": Deadline date in YYYY-MM-DD format if mentioned, or null
- "contact_name": Point of contact name if mentioned, or null
- "contact_email": Point of contact email if mentioned, or null
- "extraction_confidence": Float 0.0 to 1.0 representing how confident you are that this is a genuine RFP/bid opportunity (1.0 = clearly an RFP with budget/deadline/scope; 0.5 = mentions interest but vague; 0.2 = newsletter-style mention with no concrete project)

Rules:
- Parse budget ranges like "$200K-$350K" into budget_min: 200000, budget_max: 350000
- Parse single budgets like "$500K" into budget_max only
- Parse "up to $1M" as budget_max: 1000000
- If the email contains no RFP/bid opportunities (e.g., it's a newsletter, unsubscribe notice, etc.), return an empty array []
- Each opportunity should be a separate entry even if from the same organization
- Extract tech keywords from context clues (e.g., "website redesign" implies web tech)

Return ONLY a JSON array, even if there is just one opportunity.
PROMPT;

        try {
            $result = $this->claude->messageJson($emailBody, $systemPrompt, 'haiku', 120);

            if ($result === null) {
                Log::warning('RfpDiscoveryService: AI returned null for email parsing');

                return [];
            }

            // Ensure we have an array of opportunities
            if (isset($result['title'])) {
                // Single opportunity returned as object instead of array
                $result = [$result];
            }

            if (! is_array($result)) {
                Log::warning('RfpDiscoveryService: unexpected AI response format', [
                    'result_type' => gettype($result),
                ]);

                return [];
            }

            Log::info('RfpDiscoveryService: parsed email teasers', [
                'opportunities_found' => count($result),
                'source_config' => $sourceConfig,
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('RfpDiscoveryService: email parsing failed', [
                'error' => $e->getMessage(),
                'email_length' => strlen($emailBody),
            ]);

            return [];
        }
    }

    /**
     * Check if an opportunity already exists (by URL, title + org exact, or fuzzy word match).
     */
    public function isDuplicate(string $title, string $organization, ?string $sourceUrl = null): bool
    {
        // Fast path: exact source_url match (same URL = same RFP)
        if ($sourceUrl) {
            $urlMatch = RfpOpportunity::query()
                ->where('source_url', $sourceUrl)
                ->exists();

            if ($urlMatch) {
                return true;
            }
        }

        // Exact match on organization + title (case-insensitive)
        $exactMatch = RfpOpportunity::query()
            ->whereRaw('LOWER(issuing_organization) = ?', [strtolower(trim($organization))])
            ->whereRaw('LOWER(title) = ?', [strtolower(trim($title))])
            ->exists();

        if ($exactMatch) {
            return true;
        }

        // Fuzzy match: check if significant words from both org and title overlap
        $orgWords = $this->extractSignificantWords($organization);
        $titleWords = $this->extractSignificantWords($title);

        if (empty($orgWords) || empty($titleWords)) {
            return false;
        }

        // Build query requiring all org words to appear in existing org name
        $query = RfpOpportunity::query();
        foreach ($orgWords as $word) {
            $query->whereRaw('LOWER(issuing_organization) LIKE ?', ["%{$word}%"]);
        }

        // Check candidates for title word overlap (60%+ of words match)
        $candidates = $query->get(['id', 'title']);

        foreach ($candidates as $candidate) {
            $existingTitleWords = $this->extractSignificantWords($candidate->title);
            if (empty($existingTitleWords)) {
                continue;
            }

            $overlap = count(array_intersect($titleWords, $existingTitleWords));
            $maxWords = max(count($titleWords), count($existingTitleWords));
            $similarity = $overlap / $maxWords;

            if ($similarity >= 0.6) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract significant words (3+ chars) from a string for fuzzy comparison.
     *
     * @return array<int, string>
     */
    protected function extractSignificantWords(string $value): array
    {
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $value) ?? '');
        $words = array_filter(explode(' ', $normalized), fn ($w) => strlen($w) > 2);

        return array_values($words);
    }

    /**
     * Create an RfpOpportunity from parsed teaser data.
     *
     * @param  array{title: string, organization: string, description?: string, budget_min?: ?float, budget_max?: ?float, url?: ?string, tech_keywords?: array, submission_deadline?: ?string, contact_name?: ?string, contact_email?: ?string}  $data
     */
    public function createFromTeaser(array $data, string $sourceType, ?int $sourceId = null): ?RfpOpportunity
    {
        $title = $data['title'] ?? 'Untitled Opportunity';
        $organization = $data['organization'] ?? 'Unknown';

        // Skip opportunities with deadlines that have already passed
        if (! empty($data['submission_deadline'])) {
            try {
                $deadline = \Carbon\Carbon::parse($data['submission_deadline']);
                if ($deadline->isPast()) {
                    Log::debug('RfpDiscoveryService: skipping expired opportunity', [
                        'title' => $title,
                        'organization' => $organization,
                        'deadline' => $deadline->toDateString(),
                    ]);

                    return null;
                }
            } catch (\Exception) {
                // If we can't parse the deadline, let it through
            }
        }

        if ($this->isDuplicate($title, $organization, $data['url'] ?? null)) {
            Log::debug('RfpDiscoveryService: skipping duplicate opportunity', [
                'title' => $title,
                'organization' => $organization,
            ]);

            return null;
        }

        $preFilterResult = $this->preFilter->evaluate([
            'title' => $title,
            'organization' => $organization,
            'description' => $data['description'] ?? null,
            'source_type' => $sourceType,
            'extraction_confidence' => $data['extraction_confidence'] ?? null,
            'url' => $data['url'] ?? null,
        ]);

        if (! $preFilterResult['passed']) {
            Log::info('RfpDiscoveryService: opportunity rejected by pre-filter', [
                'title' => $title,
                'organization' => $organization,
                'reason' => $preFilterResult['reason'],
                'detail' => $preFilterResult['detail'],
            ]);

            return null;
        }

        $slug = Str::slug($title.'-'.Str::random(6));

        // Ensure slug uniqueness
        while (RfpOpportunity::where('slug', $slug)->exists()) {
            $slug = Str::slug($title.'-'.Str::random(6));
        }

        $opportunity = RfpOpportunity::create([
            'title' => $title,
            'slug' => $slug,
            'issuing_organization' => $organization,
            'description' => $data['description'] ?? null,
            'source_type' => $sourceType,
            'rfp_source_id' => $sourceId,
            'source_url' => $data['url'] ?? null,
            'budget_min' => $data['budget_min'] ?? null,
            'budget_max' => $data['budget_max'] ?? null,
            'submission_deadline' => $data['submission_deadline'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'tech_requirements' => $data['tech_keywords'] ?? [],
            'status' => 'discovered',
            'priority' => 'medium',
            'discovered_by' => 'rfp-email-monitor',
        ]);

        Log::info('RfpDiscoveryService: created opportunity from teaser', [
            'opportunity_id' => $opportunity->id,
            'title' => $title,
            'organization' => $organization,
            'source_type' => $sourceType,
            'rfp_source_id' => $sourceId,
        ]);

        // Check if this matches any decline patterns and flag accordingly
        $this->checkDeclinePatterns($opportunity);

        // Auto-evaluate immediately after discovery
        EvaluateRfpJob::dispatch($opportunity->id);

        return $opportunity;
    }

    /**
     * Query SAM.gov for federal procurement opportunities.
     *
     * Searches the SAM.gov Opportunities API v2 for active solicitations
     * matching web development NAICS codes and optional keywords.
     *
     * @param  array{keywords?: array, naics_codes?: array, posted_from?: string, posted_to?: string}  $config
     * @return array<int, array{title: string, organization: string, description: string, budget_min: ?float, budget_max: ?float, url: ?string, tech_keywords: array, submission_deadline: ?string, contact_name: ?string, contact_email: ?string}>
     */
    public function querySamGov(array $config): array
    {
        $apiKey = config('services.sam_gov.api_key');

        if (! $apiKey) {
            Log::warning('RfpDiscoveryService: SAM.gov API key not configured');

            return [];
        }

        // NAICS codes for Computer Related Services
        $naicsCodes = $config['naics_codes'] ?? [
            '541511', // Custom Computer Programming Services
            '541512', // Computer Systems Design Services
            '541513', // Computer Facilities Management Services
            '541519', // Other Computer Related Services
        ];

        $keywords = $config['keywords'] ?? [
            'web development',
            'website redesign',
            'CMS',
            'WordPress',
            'digital transformation',
        ];

        $postedFrom = $config['posted_from'] ?? now()->subDays(7)->format('m/d/Y');
        $postedTo = $config['posted_to'] ?? now()->format('m/d/Y');

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])->get('https://api.sam.gov/opportunities/v2/search', [
                'api_key' => $apiKey,
                'postedFrom' => $postedFrom,
                'postedTo' => $postedTo,
                'ncode' => implode(',', $naicsCodes),
                'limit' => 25,
                'offset' => 0,
            ]);

            if ($response->failed()) {
                Log::warning('RfpDiscoveryService: SAM.gov API request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return [];
            }

            $data = $response->json();
            $opportunities = $data['opportunitiesData'] ?? [];

            Log::info('RfpDiscoveryService: SAM.gov query returned results', [
                'total_records' => $data['totalRecords'] ?? 0,
                'returned' => count($opportunities),
            ]);

            return collect($opportunities)->map(function (array $opp) {
                $title = $opp['title'] ?? 'Untitled Federal Opportunity';
                $org = $opp['department'] ?? $opp['subtier'] ?? $opp['office'] ?? 'Federal Government';
                $description = $opp['description'] ?? '';

                // Clean HTML from description
                if ($description) {
                    $description = strip_tags($description);
                    $description = mb_substr($description, 0, 500);
                }

                return [
                    'title' => $title,
                    'organization' => $org,
                    'description' => $description,
                    'budget_min' => null,
                    'budget_max' => null,
                    'url' => $opp['uiLink'] ?? null,
                    'tech_keywords' => $this->extractTechKeywordsFromText($title.' '.$description),
                    'submission_deadline' => $this->parseSamGovDate($opp['responseDeadLine'] ?? null),
                    'contact_name' => $opp['pointOfContact'][0]['fullName'] ?? null,
                    'contact_email' => $opp['pointOfContact'][0]['email'] ?? null,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::error('RfpDiscoveryService: SAM.gov query failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Extract technology keywords from text content.
     *
     * @return array<int, string>
     */
    protected function extractTechKeywordsFromText(string $text): array
    {
        $keywords = [];
        $text = strtolower($text);

        $techTerms = [
            'wordpress' => 'WordPress',
            'drupal' => 'Drupal',
            'joomla' => 'Joomla',
            'sitecore' => 'Sitecore',
            'laravel' => 'Laravel',
            'react' => 'React',
            'vue' => 'Vue',
            'angular' => 'Angular',
            'next.js' => 'Next.js',
            'node.js' => 'Node.js',
            'php' => 'PHP',
            'python' => 'Python',
            'cms' => 'CMS',
            'crm' => 'CRM',
            'seo' => 'SEO',
            'wcag' => 'WCAG',
            'ada compliance' => 'ADA Compliance',
            'accessibility' => 'Accessibility',
            'responsive' => 'Responsive Design',
            'e-commerce' => 'E-Commerce',
            'ecommerce' => 'E-Commerce',
            'aws' => 'AWS',
            'azure' => 'Azure',
            'gcp' => 'GCP',
            'api' => 'API',
            'mobile app' => 'Mobile App',
        ];

        foreach ($techTerms as $search => $label) {
            if (str_contains($text, $search)) {
                $keywords[] = $label;
            }
        }

        return array_unique($keywords);
    }

    /**
     * Parse a SAM.gov date string into YYYY-MM-DD format.
     */
    protected function parseSamGovDate(?string $dateString): ?string
    {
        if (! $dateString) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Normalize a string for fuzzy comparison.
     */
    protected function normalizeForComparison(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\s]/', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Check if a new opportunity matches past decline patterns.
     *
     * If matches are found, the opportunity's priority is lowered and a note is logged.
     * The opportunity is still imported — evaluation scoring handles the full adjustment.
     */
    protected function checkDeclinePatterns(RfpOpportunity $opportunity): void
    {
        $insights = RfpLearningInsight::query()
            ->where('insight_type', 'decline_pattern')
            ->where('is_active', true)
            ->get();

        if ($insights->isEmpty()) {
            return;
        }

        $orgName = strtolower($opportunity->issuing_organization ?? '');
        $matchedReasons = [];

        foreach ($insights as $insight) {
            $evidence = $insight->evidence ?? [];
            $declinedOrg = strtolower($evidence['organization'] ?? '');

            // Exact org match
            if (! empty($orgName) && ! empty($declinedOrg) && str_contains($orgName, $declinedOrg)) {
                $matchedReasons[] = "Previously declined from {$evidence['organization']} ({$evidence['category']})";
            }
        }

        if (! empty($matchedReasons)) {
            $opportunity->update(['priority' => 'low']);

            Log::info('RfpDiscoveryService: new opportunity matches decline patterns', [
                'opportunity_id' => $opportunity->id,
                'title' => $opportunity->title,
                'organization' => $opportunity->issuing_organization,
                'matched_patterns' => $matchedReasons,
            ]);
        }
    }
}
