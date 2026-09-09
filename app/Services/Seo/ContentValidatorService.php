<?php

namespace App\Services\Seo;

class ContentValidatorService
{
    public function __construct(
        protected SeoResearchService $research,
    ) {}

    /**
     * Validate content against playbook-specific rules.
     *
     * @param  array{content?: string, meta_title?: string, meta_description?: string}  $content
     */
    public function validate(array $content, string $playbook): array
    {
        $errors = [];
        $warnings = [];

        $body = $content['content'] ?? '';
        $wordCount = str_word_count(strip_tags($body));

        // Word count by playbook
        $minWords = $this->getMinWordCount($playbook);
        if ($wordCount < $minWords) {
            $errors[] = "Content too short: {$wordCount} words (minimum: {$minWords} for {$playbook})";
        }

        // Humanization check - detect AI patterns
        $patterns = $this->research->detectAIPatterns($body);
        if (count($patterns) > 0) {
            $patternTypes = array_unique(array_column($patterns, 'type'));
            $errors[] = 'AI patterns detected: '.implode(', ', array_slice($patternTypes, 0, 3));
        }

        // Meta length checks
        $titleLen = strlen($content['meta_title'] ?? '');
        $descLen = strlen($content['meta_description'] ?? '');

        if ($titleLen === 0) {
            $errors[] = 'Missing meta title';
        } elseif ($titleLen > 60) {
            $warnings[] = "Meta title too long: {$titleLen} chars (max 60)";
        } elseif ($titleLen < 30) {
            $warnings[] = "Meta title too short: {$titleLen} chars (min 30 recommended)";
        }

        if ($descLen === 0) {
            $errors[] = 'Missing meta description';
        } elseif ($descLen > 160) {
            $warnings[] = "Meta description too long: {$descLen} chars (max 160)";
        } elseif ($descLen < 70) {
            $warnings[] = "Meta description too short: {$descLen} chars (min 70 recommended)";
        }

        // Playbook-specific validation
        $playbookErrors = $this->validatePlaybookRequirements($content, $playbook);
        $errors = array_merge($errors, $playbookErrors);

        // Schema markup check
        $hasSchema = $this->hasValidSchema($body);
        if (! $hasSchema) {
            $warnings[] = 'No valid JSON-LD schema markup detected';
        }

        // Internal links check
        $internalLinks = $this->countInternalLinks($body);
        if ($internalLinks < 3) {
            $warnings[] = "Only {$internalLinks} internal links (recommend 3-5)";
        } elseif ($internalLinks > 10) {
            $warnings[] = "Too many internal links: {$internalLinks} (recommend 3-10)";
        }

        // External links check
        $externalLinks = $this->countExternalLinks($body);
        if ($externalLinks === 0 && $wordCount > 500) {
            $warnings[] = 'No external links to authoritative sources';
        }

        // Heading structure check
        $headingIssues = $this->validateHeadingStructure($body);
        $warnings = array_merge($warnings, $headingIssues);

        // Calculate humanization score
        $humanizationScore = empty($patterns) ? 100 : max(0, 100 - (count($patterns) * 10));

        return [
            'valid' => empty($errors),
            'score' => $this->calculateScore($errors, $warnings),
            'errors' => $errors,
            'warnings' => $warnings,
            'metadata' => [
                'word_count' => $wordCount,
                'internal_links' => $internalLinks,
                'external_links' => $externalLinks,
                'humanization_score' => $humanizationScore,
                'has_schema' => $hasSchema,
                'meta_title_length' => $titleLen,
                'meta_description_length' => $descLen,
            ],
        ];
    }

    /**
     * Validate playbook-specific requirements.
     */
    protected function validatePlaybookRequirements(array $content, string $playbook): array
    {
        $body = $content['content'] ?? '';

        return match ($playbook) {
            'Comparisons' => $this->validateComparison($body),
            'Location' => $this->validateLocation($body),
            'Case Study' => $this->validateCaseStudy($body),
            'Glossary', 'Educational' => $this->validateGlossary($body),
            'Calculators', 'Converters', 'Tools' => $this->validateInteractive($body),
            'Rankings', 'Curation', 'Listings' => $this->validateList($body),
            'Templates', 'Examples' => $this->validateTemplates($body),
            'Integration' => $this->validateIntegration($body),
            default => [],
        };
    }

    /**
     * Validate comparison pages.
     */
    protected function validateComparison(string $body): array
    {
        $errors = [];

        if (! str_contains($body, '<table') && ! preg_match('/comparison|versus|vs\.?/i', $body)) {
            $errors[] = 'Comparison pages should include a comparison table or explicit comparison';
        }

        if (! preg_match('/vs\.?|versus|compared to|difference between/i', $body)) {
            $errors[] = 'Comparison pages should explicitly compare options';
        }

        // Should have pros/cons or advantages/disadvantages
        if (! preg_match('/pros?|cons?|advantage|disadvantage|benefit|drawback/i', $body)) {
            $errors[] = 'Comparison pages should list pros/cons or advantages/disadvantages';
        }

        return $errors;
    }

    /**
     * Validate location pages.
     */
    protected function validateLocation(string $body): array
    {
        $errors = [];

        // Use centralized location config
        $cities = array_keys(config('seo.locations', []));
        $states = config('seo.states', []);
        $locations = array_merge($cities, $states);

        $hasLocation = false;
        foreach ($locations as $loc) {
            if (stripos($body, $loc) !== false) {
                $hasLocation = true;
                break;
            }
        }

        if (! $hasLocation) {
            $errors[] = 'Location pages must reference specific geographic area';
        }

        // Should mention local service area
        if (! preg_match('/local|near|nearby|area|region|based in/i', $body)) {
            $errors[] = 'Location pages should emphasize local service area';
        }

        return $errors;
    }

    /**
     * Validate case study pages.
     */
    protected function validateCaseStudy(string $body): array
    {
        $errors = [];

        // Must have measurable results
        if (! preg_match('/\d+%|\d+x|increased|decreased|improved|reduced|saved/i', $body)) {
            $errors[] = 'Case studies should include measurable results (percentages, multipliers)';
        }

        // Should have challenge/solution/results structure
        $hasChallenge = preg_match('/challenge|problem|issue|pain point/i', $body);
        $hasSolution = preg_match('/solution|approach|implemented|built/i', $body);
        $hasResults = preg_match('/result|outcome|impact|achieved/i', $body);

        if (! ($hasChallenge && $hasSolution && $hasResults)) {
            $errors[] = 'Case studies should have challenge, solution, and results sections';
        }

        return $errors;
    }

    /**
     * Validate glossary/educational pages.
     */
    protected function validateGlossary(string $body): array
    {
        $errors = [];

        if (! preg_match('/<h[23]|definition|meaning|what is|refers to/i', $body)) {
            $errors[] = 'Glossary pages should have clear definition structure with headings';
        }

        return $errors;
    }

    /**
     * Validate interactive tool pages.
     */
    protected function validateInteractive(string $body): array
    {
        $errors = [];

        // Should have form or interactive element references
        if (! preg_match('/calculate|convert|input|enter|result|output|form/i', $body)) {
            $errors[] = 'Tool pages should explain how to use the calculator/converter';
        }

        // Should have instructions
        if (! preg_match('/how to use|instructions|steps|enter|click/i', $body)) {
            $errors[] = 'Tool pages should include usage instructions';
        }

        return $errors;
    }

    /**
     * Validate list/ranking pages.
     */
    protected function validateList(string $body): array
    {
        $errors = [];

        // Should have numbered or bulleted lists
        if (! preg_match('/<[ou]l>|<li>|\d+\.|•|→/i', $body)) {
            $errors[] = 'List pages should contain numbered or bulleted lists';
        }

        // Should have multiple items
        $listItems = substr_count($body, '<li>');
        if ($listItems < 3) {
            $errors[] = 'List pages should have at least 3 list items';
        }

        return $errors;
    }

    /**
     * Validate template/example pages.
     */
    protected function validateTemplates(string $body): array
    {
        $errors = [];

        // Should have code examples or downloadable content
        if (! preg_match('/<code|<pre|```|download|template|example/i', $body)) {
            $errors[] = 'Template pages should include code examples or downloadable templates';
        }

        return $errors;
    }

    /**
     * Validate integration pages.
     */
    protected function validateIntegration(string $body): array
    {
        $errors = [];

        // Should mention integration process
        if (! preg_match('/integrate|connect|setup|configure|install|api/i', $body)) {
            $errors[] = 'Integration pages should explain the integration process';
        }

        // Should have prerequisites or requirements
        if (! preg_match('/require|prerequisite|need|before you|first/i', $body)) {
            $errors[] = 'Integration pages should list prerequisites or requirements';
        }

        return $errors;
    }

    /**
     * Get minimum word count by playbook type.
     */
    public function getMinWordCount(string $playbook): int
    {
        return match ($playbook) {
            'Location' => 1000,
            'Persona', 'Vertical' => 1200,
            'Comparisons' => 1500,
            'Case Study' => 1000,
            'Glossary', 'Educational' => 600,
            'Templates', 'Tools', 'Calculators', 'Converters' => 800,
            'Examples', 'Curation' => 1000,
            'Rankings', 'Listings', 'Directory' => 1000,
            'Integration' => 900,
            'Galleries' => 500,
            'Profile' => 600,
            'Translations' => 800,
            default => 800,
        };
    }

    /**
     * Check if content has valid JSON-LD schema.
     */
    protected function hasValidSchema(string $content): bool
    {
        return str_contains($content, 'application/ld+json');
    }

    /**
     * Count internal links (to example.com).
     */
    protected function countInternalLinks(string $content): int
    {
        preg_match_all('/href=["\']https?:\/\/(?:www\.)?zao\.is[^"\']*["\']/i', $content, $matches);

        return count($matches[0]);
    }

    /**
     * Count external links (not to example.com).
     */
    protected function countExternalLinks(string $content): int
    {
        preg_match_all('/href=["\']https?:\/\/(?!(?:www\.)?zao\.is)[^"\']+["\']/i', $content, $matches);

        return count($matches[0]);
    }

    /**
     * Validate heading structure.
     */
    protected function validateHeadingStructure(string $content): array
    {
        $warnings = [];

        // Check for H1
        if (! preg_match('/<h1[^>]*>/i', $content)) {
            $warnings[] = 'Missing H1 heading';
        }

        // Check for multiple H1s
        preg_match_all('/<h1[^>]*>/i', $content, $h1Matches);
        if (count($h1Matches[0]) > 1) {
            $warnings[] = 'Multiple H1 headings detected (should have only one)';
        }

        // Check heading hierarchy (H2 should exist before H3)
        $h2Pos = stripos($content, '<h2');
        $h3Pos = stripos($content, '<h3');

        if ($h3Pos !== false && ($h2Pos === false || $h3Pos < $h2Pos)) {
            $warnings[] = 'Heading hierarchy issue: H3 appears before H2';
        }

        return $warnings;
    }

    /**
     * Calculate overall quality score.
     */
    protected function calculateScore(array $errors, array $warnings): int
    {
        $score = 100;
        $score -= count($errors) * 20;
        $score -= count($warnings) * 5;

        return max(0, min(100, $score));
    }
}
