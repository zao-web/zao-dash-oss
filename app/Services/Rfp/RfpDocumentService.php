<?php

namespace App\Services\Rfp;

use App\Models\RfpOpportunity;
use App\Services\AI\ClaudeCliService;
use App\Services\Google\DriveService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

class RfpDocumentService
{
    private const MAX_CONTENT_CHARS = 60000;

    public function __construct(
        protected ClaudeCliService $claude,
        protected DriveService $driveService,
    ) {}

    /**
     * Search the web for the full RFP document given an opportunity.
     *
     * Queries web search APIs for PDF links, .gov procurement pages,
     * and bidding portals related to the opportunity.
     *
     * @return string|null The URL of the found document, or null
     */
    public function searchForDocument(RfpOpportunity $opportunity): ?string
    {
        $apiKey = config('services.serper.api_key');

        if (! $apiKey) {
            Log::debug('RfpDocumentService: No search API key configured, skipping document search', [
                'opportunity_id' => $opportunity->id,
            ]);

            return null;
        }

        $searchQuery = sprintf(
            '"%s" RFP %s filetype:pdf OR procurement OR solicitation',
            $opportunity->issuing_organization,
            $this->extractTitleKeywords($opportunity->title)
        );

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://google.serper.dev/search', [
                'q' => $searchQuery,
                'num' => 10,
            ]);

            if ($response->failed()) {
                Log::warning('RfpDocumentService: Search API request failed', [
                    'opportunity_id' => $opportunity->id,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $results = $response->json('organic', []);

            // Prioritize PDF links, .gov pages, and procurement portals
            $scoredResults = collect($results)->map(function (array $result) {
                $url = $result['link'] ?? '';
                $score = 0;

                if (str_contains(strtolower($url), '.pdf')) {
                    $score += 10;
                }
                if (str_contains(strtolower($url), '.gov')) {
                    $score += 5;
                }
                if (preg_match('/procurement|solicitation|rfp|bid/i', $url)) {
                    $score += 3;
                }
                if (preg_match('/sam\.gov|bidnet|bidsync|govwin/i', $url)) {
                    $score += 4;
                }

                return ['url' => $url, 'score' => $score, 'title' => $result['title'] ?? ''];
            })->sortByDesc('score')->first();

            if ($scoredResults && $scoredResults['score'] > 0) {
                Log::info('RfpDocumentService: Found document URL via search', [
                    'opportunity_id' => $opportunity->id,
                    'url' => $scoredResults['url'],
                    'score' => $scoredResults['score'],
                ]);

                return $scoredResults['url'];
            }

            Log::debug('RfpDocumentService: No relevant document found via search', [
                'opportunity_id' => $opportunity->id,
                'results_count' => count($results),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('RfpDocumentService: Search failed', [
                'opportunity_id' => $opportunity->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Download an RFP document from a URL and store locally.
     *
     * @return string|null Local storage path of the downloaded file
     */
    public function downloadDocument(string $url, RfpOpportunity $opportunity): ?string
    {
        try {
            $response = Http::timeout(60)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; ZaoDash/1.0)',
            ])->get($url);

            if ($response->failed()) {
                Log::warning('RfpDocumentService: Download failed', [
                    'opportunity_id' => $opportunity->id,
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $contentType = $response->header('Content-Type', '');
            $extension = $this->guessExtension($url, $contentType);
            $directory = "rfp-documents/{$opportunity->id}";
            $filename = "rfp-document.{$extension}";
            $path = "{$directory}/{$filename}";

            Storage::disk('local')->makeDirectory($directory);
            Storage::disk('local')->put($path, $response->body());

            Log::info('RfpDocumentService: Document downloaded', [
                'opportunity_id' => $opportunity->id,
                'path' => $path,
                'size_bytes' => strlen($response->body()),
                'content_type' => $contentType,
            ]);

            return $path;
        } catch (\Exception $e) {
            Log::error('RfpDocumentService: Download failed', [
                'opportunity_id' => $opportunity->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse an RFP document into structured data using AI.
     *
     * Extracts requirements, evaluation criteria, timeline, budget details,
     * submission instructions, contact info, and tech requirements.
     *
     * @return array{
     *     requirements_summary: array<int, array{requirement: string, section: string, priority: string}>,
     *     evaluation_criteria: array<int, array{criterion: string, weight: ?string, description: string}>,
     *     timeline_requirements: array{start_date: ?string, end_date: ?string, milestones: array},
     *     tech_requirements: array<int, string>,
     *     submission_method: ?string,
     *     submission_email: ?string,
     *     submission_portal_url: ?string,
     *     contact_name: ?string,
     *     contact_email: ?string,
     *     contact_phone: ?string,
     *     budget_min: ?float,
     *     budget_max: ?float,
     *     budget_line_items: array
     * }
     */
    public function parseDocument(RfpOpportunity $opportunity, ?string $documentContent = null): array
    {
        if (! $documentContent) {
            $documentContent = $this->readDocumentContent($opportunity);
        }

        if (! $documentContent) {
            Log::warning('RfpDocumentService: No document content to parse', [
                'opportunity_id' => $opportunity->id,
            ]);

            return $this->emptyParsedResult();
        }

        $preparedContent = $this->prepareContentForAi($documentContent);

        Log::info('RfpDocumentService: Parsing document with AI', [
            'opportunity_id' => $opportunity->id,
            'original_length' => strlen($documentContent),
            'prepared_length' => strlen($preparedContent),
        ]);

        $systemPrompt = <<<'PROMPT'
You are an expert at analyzing RFP (Request for Proposal) documents for a web development agency.

Extract structured data from the provided RFP document. Return a JSON object with this exact structure:
{
  "requirements_summary": [
    {
      "requirement": "Description of the requirement",
      "section": "Section name or number from the RFP",
      "priority": "required|preferred|optional"
    }
  ],
  "evaluation_criteria": [
    {
      "criterion": "Name of the evaluation criterion",
      "weight": "Percentage or point value if specified, or null",
      "description": "How this criterion will be evaluated"
    }
  ],
  "timeline_requirements": {
    "start_date": "YYYY-MM-DD if specified, or null",
    "end_date": "YYYY-MM-DD if specified, or null",
    "milestones": [
      {
        "name": "Milestone name",
        "date": "YYYY-MM-DD if specified, or null",
        "description": "Milestone description"
      }
    ]
  },
  "tech_requirements": ["WordPress", "PHP", "React", "etc"],
  "submission_method": "email|portal|physical|null",
  "submission_email": "email@example.com or null",
  "submission_portal_url": "https://... or null",
  "contact_name": "Primary contact name or null",
  "contact_email": "Contact email or null",
  "contact_phone": "Contact phone or null",
  "budget_min": null,
  "budget_max": null,
  "budget_line_items": [
    {
      "item": "Line item description",
      "amount": null
    }
  ]
}

Rules:
- Extract ALL requirements mentioned in the document
- Mark requirements as "required", "preferred", or "optional" based on language used
- Include specific technology mentions in tech_requirements (CMS, frameworks, languages, tools)
- Parse budget ranges: "$200K-$350K" -> budget_min: 200000, budget_max: 350000
- Dates must be in YYYY-MM-DD format when explicit, otherwise null
- If evaluation criteria have point values or percentages, include them as weight
- Be thorough - do not skip any sections of the RFP
- If a field cannot be determined from the document, use null
PROMPT;

        $userPrompt = "Extract structured data from this RFP document:\n\n{$preparedContent}";

        try {
            $result = $this->claude->messageJson($userPrompt, $systemPrompt, 'sonnet', 540);

            if (! $result || ! is_array($result)) {
                Log::warning('RfpDocumentService: AI returned invalid structure', [
                    'opportunity_id' => $opportunity->id,
                ]);

                return $this->emptyParsedResult();
            }

            Log::info('RfpDocumentService: Document parsed successfully', [
                'opportunity_id' => $opportunity->id,
                'requirements_count' => count($result['requirements_summary'] ?? []),
                'tech_requirements_count' => count($result['tech_requirements'] ?? []),
                'has_evaluation_criteria' => ! empty($result['evaluation_criteria']),
            ]);

            return array_merge($this->emptyParsedResult(), $result);
        } catch (\Exception $e) {
            Log::error('RfpDocumentService: AI parsing failed', [
                'opportunity_id' => $opportunity->id,
                'error' => $e->getMessage(),
            ]);

            return $this->emptyParsedResult();
        }
    }

    /**
     * Read document content from the stored file.
     */
    protected function readDocumentContent(RfpOpportunity $opportunity): ?string
    {
        $path = $opportunity->full_document_path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $fullPath = Storage::disk('local')->path($path);
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        try {
            return match ($extension) {
                'pdf' => $this->extractTextFromPdf($fullPath),
                'html', 'htm' => $this->extractTextFromHtml(file_get_contents($fullPath) ?: ''),
                'txt', 'md' => file_get_contents($fullPath) ?: '',
                'docx' => $this->extractTextFromDocx($fullPath),
                default => file_get_contents($fullPath) ?: '',
            };
        } catch (\Exception $e) {
            Log::error('RfpDocumentService: Failed to read document', [
                'opportunity_id' => $opportunity->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract text from a PDF file.
     */
    protected function extractTextFromPdf(string $filePath): string
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseFile($filePath);

            return $pdf->getText();
        } catch (\Exception $e) {
            Log::error('RfpDocumentService: PDF text extraction failed', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return '';
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

        // Remove tags but keep content
        $text = strip_tags($html);

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Extract text from a DOCX file (basic extraction).
     */
    protected function extractTextFromDocx(string $filePath): string
    {
        $zip = new \ZipArchive;

        if ($zip->open($filePath) !== true) {
            return '';
        }

        $content = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! $content) {
            return '';
        }

        // Strip XML tags and extract text
        $text = strip_tags($content);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Extract meaningful keywords from an RFP title for search.
     */
    protected function extractTitleKeywords(string $title): string
    {
        $stopWords = ['rfp', 'for', 'the', 'and', 'of', 'to', 'a', 'an', 'in', 'on', 'at', 'by', 'with', 'request', 'proposal', 'proposals'];
        $words = preg_split('/\s+/', strtolower($title));
        $keywords = array_filter($words ?? [], fn (string $w) => strlen($w) > 2 && ! in_array($w, $stopWords));

        return implode(' ', array_slice($keywords, 0, 5));
    }

    /**
     * Guess the file extension from a URL and content type.
     */
    protected function guessExtension(string $url, string $contentType): string
    {
        // Try to extract from URL
        $urlPath = parse_url($url, PHP_URL_PATH) ?? '';
        $urlExtension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

        if (in_array($urlExtension, ['pdf', 'docx', 'doc', 'html', 'htm', 'txt'])) {
            return $urlExtension;
        }

        // Fall back to content type
        return match (true) {
            str_contains($contentType, 'pdf') => 'pdf',
            str_contains($contentType, 'word') || str_contains($contentType, 'docx') => 'docx',
            str_contains($contentType, 'html') => 'html',
            str_contains($contentType, 'text/plain') => 'txt',
            default => 'html',
        };
    }

    /**
     * Clean and cap document text before sending to AI.
     */
    protected function prepareContentForAi(string $content): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $normalized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $normalized) ?? $normalized;
        $normalized = preg_replace("/[ \t]+/", ' ', $normalized) ?? $normalized;
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if (strlen($normalized) <= self::MAX_CONTENT_CHARS) {
            return $normalized;
        }

        $marker = "\n\n[... document content truncated for AI input size ...]\n\n";
        $availableChars = max(0, self::MAX_CONTENT_CHARS - strlen($marker));
        $headChars = (int) floor($availableChars * 0.7);
        $tailChars = $availableChars - $headChars;

        return substr($normalized, 0, $headChars)
            .$marker
            .substr($normalized, -$tailChars);
    }

    /**
     * Return an empty parsed result structure.
     *
     * @return array{
     *     requirements_summary: array,
     *     evaluation_criteria: array,
     *     timeline_requirements: array{start_date: null, end_date: null, milestones: array},
     *     tech_requirements: array,
     *     submission_method: null,
     *     submission_email: null,
     *     submission_portal_url: null,
     *     contact_name: null,
     *     contact_email: null,
     *     contact_phone: null,
     *     budget_min: null,
     *     budget_max: null,
     *     budget_line_items: array
     * }
     */
    protected function emptyParsedResult(): array
    {
        return [
            'requirements_summary' => [],
            'evaluation_criteria' => [],
            'timeline_requirements' => [
                'start_date' => null,
                'end_date' => null,
                'milestones' => [],
            ],
            'tech_requirements' => [],
            'submission_method' => null,
            'submission_email' => null,
            'submission_portal_url' => null,
            'contact_name' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'budget_min' => null,
            'budget_max' => null,
            'budget_line_items' => [],
        ];
    }
}
