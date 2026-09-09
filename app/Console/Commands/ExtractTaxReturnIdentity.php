<?php

namespace App\Console\Commands;

use App\Models\FinancialDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class ExtractTaxReturnIdentity extends Command
{
    protected $signature = 'tax:extract-identity {document_id} {--pages=3} {--full : Print all extracted text without truncation}';

    protected $description = 'Extract page text from a stored prior-year tax return PDF for parsing identity fields';

    public function handle(): int
    {
        $documentId = (int) $this->argument('document_id');
        $maxPages = (int) $this->option('pages');
        $full = (bool) $this->option('full');

        $document = FinancialDocument::find($documentId);

        if (! $document) {
            $this->error("Document {$documentId} not found.");

            return self::FAILURE;
        }

        $this->info("Document: {$document->file_name}");
        $this->info("Path: {$document->file_path}");
        $this->newLine();

        $pdfContent = null;
        $diskUsed = null;

        foreach (['private', 'public', 's3', 'local'] as $candidate) {
            try {
                if (Storage::disk($candidate)->exists($document->file_path)) {
                    $pdfContent = Storage::disk($candidate)->get($document->file_path);
                    $diskUsed = $candidate;
                    break;
                }
            } catch (\Throwable) {
                // Disk not configured — try the next one.
            }
        }

        if ($pdfContent === null) {
            $this->error("File not found on any configured disk (tried: public, s3, local). Path: {$document->file_path}");

            return self::FAILURE;
        }

        $this->info("Loaded from disk: {$diskUsed}");

        try {
            $parser = new Parser;
            $pdf = $parser->parseContent($pdfContent);
            $pages = $pdf->getPages();
            $pageCount = min(count($pages), $maxPages);

            $this->info('Total pages: '.count($pages).", reading first {$pageCount}");
            $this->newLine();

            for ($i = 0; $i < $pageCount; $i++) {
                $text = trim($pages[$i]->getText());

                $this->line('=== PAGE '.($i + 1).' ===');

                if (! $full && strlen($text) > 4000) {
                    $this->line(substr($text, 0, 4000));
                    $this->newLine();
                    $this->warn('[truncated — pass --full to see complete text]');
                } else {
                    $this->line($text);
                }

                $this->newLine();
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('PDF parse failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
