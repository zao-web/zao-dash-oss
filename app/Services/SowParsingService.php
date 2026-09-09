<?php

namespace App\Services;

use App\Services\AI\ClaudeCliService;
use App\Services\Google\DriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

class SowParsingService
{
    private const DEFAULT_AI_INPUT_MAX_CHARS = 45000;

    public function __construct(
        private ClaudeCliService $claude,
        private DriveService $driveService
    ) {}

    /**
     * Parse one or more documents and extract structured project data.
     *
     * @param  array<int, array{type: string, file?: UploadedFile, google_drive_url?: string, content?: string, label?: string}>  $documents
     * @param  \App\Models\User|null  $user  Required for Google Drive documents
     * @return array|null Structured project data or null on failure
     */
    public function parseDocuments(array $documents, ?\App\Models\User $user = null): ?array
    {
        Log::info('[SowParsing] parseDocuments called', [
            'document_count' => count($documents),
            'user_id' => $user?->id,
            'document_types' => array_column($documents, 'type'),
        ]);

        $combinedContent = $this->buildCombinedText($documents, $user);

        if (! $combinedContent) {
            Log::warning('[SowParsing] buildCombinedText returned null - no text extracted from any document');

            return null;
        }

        Log::info('[SowParsing] Combined text built, sending to AI', [
            'combined_length' => strlen($combinedContent),
        ]);

        return $this->extractWithAI($combinedContent);
    }

    /**
     * Extract text content from a PDF file.
     */
    public function extractTextFromPdf(UploadedFile $file): string
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseFile($file->getRealPath());

            return $pdf->getText();
        } catch (\Exception $e) {
            Log::error('SowParsingService: PDF parsing failed', ['error' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * Extract text content from a Google Drive file.
     */
    public function extractTextFromGoogleDrive(?\App\Models\User $user, string $urlOrId): string
    {
        Log::info('[SowParsing] extractTextFromGoogleDrive called', [
            'url_or_id' => $urlOrId,
            'user_id' => $user?->id,
            'has_user' => $user !== null,
        ]);

        if (! $user) {
            Log::warning('[SowParsing] No user provided for Google Drive extraction');

            return '';
        }

        try {
            $fileId = $this->extractGoogleDriveFileId($urlOrId);
            Log::info('[SowParsing] Extracted file ID from URL', [
                'file_id' => $fileId,
                'original_url' => $urlOrId,
            ]);

            $content = $this->driveService->getFileContent($user, $fileId);

            Log::info('[SowParsing] Google Drive content result', [
                'file_id' => $fileId,
                'content_length' => $content ? strlen($content) : 0,
                'has_content' => $content !== null && $content !== '',
            ]);

            return $content ?? '';
        } catch (\Exception $e) {
            Log::error('[SowParsing] Google Drive extraction failed', [
                'url_or_id' => $urlOrId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Extract a Google Drive file ID from a URL or return as-is if already an ID.
     */
    private function extractGoogleDriveFileId(string $urlOrId): string
    {
        if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $urlOrId, $matches)) {
            return $matches[1];
        }

        if (preg_match('/id=([a-zA-Z0-9_-]+)/', $urlOrId, $matches)) {
            return $matches[1];
        }

        return $urlOrId;
    }

    /**
     * Build combined text from an array of document inputs.
     * Extracts text from PDFs and Google Drive, concatenates with section separators.
     *
     * @param  array<int, array{type: string, file?: \Illuminate\Http\UploadedFile, google_drive_url?: string, content?: string, label?: string}>  $documents
     */
    public function buildCombinedText(array $documents, ?\App\Models\User $user = null): ?string
    {
        Log::info('[SowParsing] buildCombinedText called', [
            'document_count' => count($documents),
            'user_id' => $user?->id,
        ]);

        $sections = [];

        foreach ($documents as $index => $doc) {
            $label = $doc['label'] ?? 'Document '.($index + 1);
            $text = match ($doc['type']) {
                'pdf' => $this->extractTextFromPdf($doc['file']),
                'google_drive' => $this->extractTextFromGoogleDrive($user, $doc['google_drive_url']),
                'text' => $doc['content'] ?? '',
                default => '',
            };

            if (empty(trim($text))) {
                Log::warning('SowParsingService: empty text from document', ['label' => $label, 'type' => $doc['type']]);

                continue;
            }

            Log::debug('SowParsingService: extracted document text', [
                'label' => $label,
                'type' => $doc['type'],
                'text_length' => strlen($text),
            ]);

            $sections[] = "=== {$label} ===\n\n{$text}";
        }

        if (empty($sections)) {
            return null;
        }

        $combinedText = implode("\n\n---\n\n", $sections);

        Log::debug('SowParsingService: built combined text', [
            'document_count' => count($sections),
            'combined_length' => strlen($combinedText),
        ]);

        return $combinedText;
    }

    /**
     * Send combined document text to AI for structured extraction.
     *
     * @return array|null Structured project data
     */
    public function extractWithAI(string $combinedContent): ?array
    {
        $systemPrompt = <<<'PROMPT'
You are a project manager expert at analyzing Statements of Work and project documents.

Extract a complete project structure from the provided documents. If multiple documents are provided, merge them into a single cohesive project:
- Combine overlapping milestones/tasks (e.g., if the SOW mentions "Discovery Phase" and a discovery doc elaborates on it, merge them)
- Use companion documents to enrich task descriptions and add subtasks
- Deduplicate tasks that appear in multiple documents

Return a JSON object with this exact structure:
{
  "client": {
    "name": "Client company name",
    "website": "https://...",
    "description": "Brief client description"
  },
  "contacts": [
    {
      "name": "Contact full name",
      "email": "email@example.com",
      "role": "Their role/title",
      "phone": "Phone number if available"
    }
  ],
  "project": {
    "name": "Project name",
    "description": "Project description/summary",
    "type": "project",
    "budget": null,
    "start_date": null,
    "end_date": null
  },
  "milestones": [
    {
      "name": "Phase/Milestone name",
      "description": "Milestone description",
      "due_date": null,
      "tasks": [
        {
          "title": "Action-oriented task title (starts with verb)",
          "description": "Task description with acceptance criteria",
          "priority": "medium",
          "estimated_hours": null,
          "due_date": null,
          "subtasks": [
            {
              "title": "Subtask title",
              "description": "Subtask description",
              "due_date": null
            }
          ]
        }
      ]
    }
  ],
  "invoices": [
    {
      "subject": "Invoice title",
      "description": "Billing milestone description",
      "amount": null,
      "issue_date": null,
      "due_date": null,
      "due_days": 30,
      "items": [
        {
          "description": "Line item description",
          "quantity": 1,
          "unit_price": 0,
          "type": "fixed"
        }
      ]
    }
  ],
  "billing": {
    "recurring_invoice": {
      "enabled": false,
      "amount": null,
      "day": 1,
      "description": "Monthly retainer",
      "auto_send": false
    }
  ]
}

Rules:
- Project type must be one of: project, retainer, support
- Task priority must be one of: low, medium, high, urgent
- Task titles should be action-oriented (start with a verb)
- Extract budget as a number if mentioned in the document
- estimated_hours should be a number if mentioned, otherwise null
- start_date, end_date, and due_date fields must be YYYY-MM-DD when explicit dates exist, otherwise null
- Group tasks into logical milestones/phases based on the document structure
- Include ALL deliverables and tasks mentioned in the documents
- Contacts array can be empty if no contact info is found
- Extract invoice/payment schedule details when the SOW specifies deposits, milestone billing, launch invoices, or fixed-fee payment terms
- If recurring monthly billing is described, populate billing.recurring_invoice
- If no invoice schedule is present, return an empty invoices array
PROMPT;

        $preparedContent = $this->prepareContentForAi($combinedContent);

        Log::info('SowParsingService: prepared AI input', [
            'original_length' => strlen($combinedContent),
            'prepared_length' => strlen($preparedContent),
            'truncated' => strlen($preparedContent) < strlen($combinedContent),
            'approx_tokens' => (int) ceil(strlen($preparedContent) / 4),
            'max_chars' => $this->maxAiInputChars(),
        ]);

        $userPrompt = "Extract the complete project structure from the following document(s):\n\n{$preparedContent}";

        try {
            $result = $this->claude->messageJson($userPrompt, $systemPrompt, 'sonnet', 540);

            if (! $result || ! isset($result['milestones'])) {
                Log::warning('SowParsingService: AI returned invalid structure', ['result' => $result]);

                return null;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('SowParsingService: AI extraction failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Clean and cap extracted document text before sending it to the CLI.
     */
    protected function prepareContentForAi(string $combinedContent): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $combinedContent);
        $normalized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $normalized) ?? $normalized;
        $normalized = preg_replace("/[ \t]+/", ' ', $normalized) ?? $normalized;
        $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;
        $normalized = trim($normalized);

        $maxChars = $this->maxAiInputChars();
        if ($maxChars <= 0 || strlen($normalized) <= $maxChars) {
            return $normalized;
        }

        $marker = "\n\n[... document content truncated for AI input size ...]\n\n";
        $availableChars = max(0, $maxChars - strlen($marker));
        if ($availableChars === 0) {
            return substr($normalized, 0, $maxChars);
        }

        $headChars = (int) floor($availableChars * 0.7);
        $tailChars = $availableChars - $headChars;

        return substr($normalized, 0, $headChars)
            .$marker
            .substr($normalized, -$tailChars);
    }

    protected function maxAiInputChars(): int
    {
        return (int) config('services.anthropic.sow_import_max_chars', self::DEFAULT_AI_INPUT_MAX_CHARS);
    }
}
