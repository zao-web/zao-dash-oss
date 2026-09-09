<?php

namespace App\Mcp\Tools;

use App\Services\AI\ClaudeCliService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class FetchRfpListingTool extends Tool
{
    protected string $name = 'fetch-rfp-listing';

    protected string $title = 'Fetch RFP Listing';

    protected string $description = 'Fetch and extract content from an RFP listing URL. Downloads the page content and extracts key details like requirements, deadlines, and contact info.';

    public function __construct(
        protected ClaudeCliService $claude,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'url' => 'required|url|max:2048',
            'extract_requirements' => 'nullable|boolean',
        ]);

        $url = $request->get('url');
        $extractRequirements = $request->get('extract_requirements', true);

        Log::info('FetchRfpListingTool: Fetching listing', [
            'url' => $url,
            'extract_requirements' => $extractRequirements,
        ]);

        try {
            $response = Http::timeout(30)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; ZaoDash/1.0)',
                'Accept' => 'text/html,application/xhtml+xml,application/pdf,*/*',
            ])->get($url);

            if ($response->failed()) {
                return Response::structured([
                    'success' => false,
                    'url' => $url,
                    'error' => "Failed to fetch URL (HTTP {$response->status()})",
                ]);
            }

            $contentType = $response->header('Content-Type', '');
            $body = $response->body();

            // Handle PDF content
            if (str_contains($contentType, 'pdf') || str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.pdf')) {
                return Response::structured([
                    'success' => true,
                    'url' => $url,
                    'content_type' => 'pdf',
                    'content_excerpt' => 'PDF document detected. Use RetrieveRfpDocumentJob to download and parse the full PDF.',
                    'size_bytes' => strlen($body),
                    'needs_download' => true,
                    'message' => 'This is a PDF document. The full content needs to be downloaded and parsed separately using the RFP document retrieval pipeline.',
                ]);
            }

            // Handle HTML content
            $textContent = $this->extractTextFromHtml($body);
            $title = $this->extractTitle($body);
            $contentExcerpt = mb_substr($textContent, 0, 3000);

            $result = [
                'success' => true,
                'url' => $url,
                'content_type' => 'html',
                'title' => $title,
                'content_excerpt' => $contentExcerpt,
                'content_length' => strlen($textContent),
                'needs_download' => false,
            ];

            // Extract requirements using AI if requested
            if ($extractRequirements && strlen($textContent) > 50) {
                $extracted = $this->extractDetailsWithAi($textContent, $url);

                if ($extracted) {
                    $result = array_merge($result, $extracted);
                }
            }

            return Response::structured($result);
        } catch (\Exception $e) {
            Log::error('FetchRfpListingTool: Fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return Response::structured([
                'success' => false,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract key RFP details from page content using AI.
     *
     * @return array<string, mixed>|null
     */
    protected function extractDetailsWithAi(string $content, string $url): ?array
    {
        // Truncate to reasonable size for Haiku
        $truncated = mb_substr($content, 0, 8000);

        $systemPrompt = <<<'PROMPT'
Extract key details from this RFP/procurement listing page. Return a JSON object with:
{
  "title": "Title of the RFP or project",
  "organization": "Issuing organization name",
  "description": "Brief summary of what they need (1-3 sentences)",
  "requirements": ["Key requirement 1", "Key requirement 2", ...],
  "deadline": "YYYY-MM-DD if found, or null",
  "deadline_text": "Original deadline text as written",
  "budget_min": null,
  "budget_max": null,
  "contact_name": "Contact name or null",
  "contact_email": "Contact email or null",
  "tech_keywords": ["WordPress", "React", "etc"],
  "relevance_to_web_agency": "high|medium|low|none"
}

Rules:
- Parse budget amounts into numbers (e.g., "$200K" -> 200000)
- Only include actual requirements, not boilerplate
- tech_keywords should be specific technologies mentioned
- relevance_to_web_agency: how relevant is this for a web development agency
PROMPT;

        try {
            $result = $this->claude->messageJson(
                "Extract RFP details from this listing page ({$url}):\n\n{$truncated}",
                $systemPrompt,
                'haiku',
                120
            );

            return $result;
        } catch (\Exception $e) {
            Log::warning('FetchRfpListingTool: AI extraction failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Strip HTML tags and extract main text content.
     */
    protected function extractTextFromHtml(string $html): string
    {
        // Remove script and style elements
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html) ?? $html;
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html) ?? $html;
        $html = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $html) ?? $html;
        $html = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $html) ?? $html;

        // Remove tags but keep content
        $text = strip_tags($html);

        // Normalize whitespace
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Extract the page title from HTML.
     */
    protected function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $matches)) {
            return html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $html, $matches)) {
            return html_entity_decode(trim(strip_tags($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->required()->description('URL of the RFP listing page to fetch and analyze'),
            'extract_requirements' => $schema->boolean()->description('Whether to use AI to extract key details (default: true)'),
        ];
    }
}
