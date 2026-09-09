<?php

namespace App\Agents\Tools\Ollie;

use App\Agents\Tools\BaseTool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/**
 * Parse product briefs from various sources.
 *
 * Extracts structured requirements from PDFs, Google Docs, or text content.
 */
class OllieParseBriefTool extends BaseTool
{
    public function category(): string
    {
        return 'ollie';
    }

    public function name(): string
    {
        return 'Parse Product Brief';
    }

    public function description(): string
    {
        return 'Parse a product brief from PDF, Google Doc, or text to extract brand guidelines, site requirements, and content specifications. Returns structured data for site building.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_type' => [
                    'type' => 'string',
                    'enum' => ['pdf', 'google_doc', 'text', 'url'],
                    'description' => 'Type of brief source',
                ],
                'source' => [
                    'type' => 'string',
                    'description' => 'File path (PDF), Google Doc ID, URL, or raw text content',
                ],
            ],
            'required' => ['source_type', 'source'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'source_type' => 'required|in:pdf,google_doc,text,url',
            'source' => 'required|string',
        ];
    }

    public function execute(array $params): array
    {
        $content = $this->extractContent($params['source_type'], $params['source']);

        if (empty($content)) {
            return [
                'success' => false,
                'error' => 'Could not extract content from source',
            ];
        }

        // Parse the content for structured data
        $parsed = $this->parseContent($content);

        return [
            'success' => true,
            'raw_content_length' => strlen($content),
            'extracted' => $parsed,
        ];
    }

    private function extractContent(string $type, string $source): string
    {
        return match ($type) {
            'pdf' => $this->extractFromPdf($source),
            'google_doc' => $this->extractFromGoogleDoc($source),
            'url' => $this->extractFromUrl($source),
            'text' => $source,
            default => '',
        };
    }

    private function extractFromPdf(string $path): string
    {
        if (! file_exists($path)) {
            // Try storage path
            $path = Storage::disk('local')->path($path);
        }

        if (! file_exists($path)) {
            return '';
        }

        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($path);

            return $pdf->getText();
        } catch (\Exception $e) {
            return '';
        }
    }

    private function extractFromGoogleDoc(string $docId): string
    {
        // Export Google Doc as plain text via public export URL
        $url = "https://docs.google.com/document/d/{$docId}/export?format=txt";

        try {
            $response = Http::get($url);

            return $response->successful() ? $response->body() : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    private function extractFromUrl(string $url): string
    {
        try {
            $response = Http::get($url);
            if (! $response->successful()) {
                return '';
            }

            // Strip HTML tags and get text content
            $html = $response->body();
            $text = strip_tags($html);

            return preg_replace('/\s+/', ' ', $text);
        } catch (\Exception $e) {
            return '';
        }
    }

    private function parseContent(string $content): array
    {
        // This returns structured data that will be further processed by the LLM agent
        // The agent will interpret this content intelligently

        $parsed = [
            'project' => [
                'name' => null,
                'type' => null,
                'environment' => 'staging',
            ],
            'brand' => [
                'colors' => [],
                'typography' => [],
                'logo' => null,
                'voice' => null,
            ],
            'structure' => [
                'pages' => [],
                'navigation' => 'standard',
            ],
            'content' => [
                'source' => 'provided',
                'migration_url' => null,
            ],
            'integrations' => [],
            'custom_requirements' => [],
            'raw_content' => $content,
        ];

        // Extract hex colors using regex
        preg_match_all('/#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})\b/', $content, $colorMatches);
        if (! empty($colorMatches[0])) {
            $parsed['brand']['colors'] = array_unique($colorMatches[0]);
        }

        // Extract URLs for potential migration sources
        preg_match_all('/https?:\/\/[^\s<>"]+/', $content, $urlMatches);
        if (! empty($urlMatches[0])) {
            $parsed['detected_urls'] = array_unique($urlMatches[0]);
        }

        // Detect common page types mentioned
        $pageKeywords = ['home', 'about', 'services', 'contact', 'blog', 'pricing', 'team', 'portfolio', 'faq'];
        foreach ($pageKeywords as $page) {
            if (stripos($content, $page) !== false) {
                $parsed['structure']['pages'][] = $page;
            }
        }

        // Detect integrations mentioned
        $integrationKeywords = ['forms', 'analytics', 'ecommerce', 'woocommerce', 'mailchimp', 'hubspot', 'google analytics'];
        foreach ($integrationKeywords as $integration) {
            if (stripos($content, $integration) !== false) {
                $parsed['integrations'][] = $integration;
            }
        }

        return $parsed;
    }
}
