<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class DownloadOfficialTaxForms extends Command
{
    protected $signature = 'tax:download-official-forms
        {--year=2025}
        {--force : Re-download and re-convert even if files exist}';

    protected $description = 'Download official IRS and Oregon fillable PDF forms and convert to FPDI-compatible format';

    /**
     * @return array<string, array{url: string, label: string}>
     */
    protected function formUrls(int $year): array
    {
        return [
            // Federal — Individual
            'f1040' => [
                'url' => 'https://www.irs.gov/pub/irs-pdf/f1040.pdf',
                'label' => 'Form 1040 — U.S. Individual Income Tax Return',
            ],
            'f1040s1' => [
                'url' => 'https://www.irs.gov/pub/irs-pdf/f1040s1.pdf',
                'label' => 'Schedule 1 (Form 1040) — Additional Income and Adjustments',
            ],
            'f1040sa' => [
                'url' => 'https://www.irs.gov/pub/irs-pdf/f1040sa.pdf',
                'label' => 'Schedule A (Form 1040) — Itemized Deductions',
            ],
            'f1040se' => [
                'url' => 'https://www.irs.gov/pub/irs-pdf/f1040se.pdf',
                'label' => 'Schedule E (Form 1040) — Supplemental Income and Loss',
            ],
            'f8995' => [
                'url' => 'https://www.irs.gov/pub/irs-pdf/f8995.pdf',
                'label' => 'Form 8995 — Qualified Business Income Deduction Simplified',
            ],

            // Federal — S Corporation
            'f1120s' => [
                'url' => 'https://www.irs.gov/pub/irs-pdf/f1120s.pdf',
                'label' => 'Form 1120-S — U.S. Income Tax Return for an S Corporation',
            ],
            'f1120ssk' => [
                'url' => 'https://www.irs.gov/pub/irs-pdf/f1120ssk.pdf',
                'label' => 'Schedule K-1 (Form 1120-S) — Shareholder Share of Income',
            ],

            // Oregon — Individual
            'or-40' => [
                'url' => 'https://www.oregon.gov/dor/forms/FormsPubs/form-or-40_101-040_2025.pdf',
                'label' => 'Form OR-40 — Oregon Individual Income Tax Return',
            ],
            'or-a' => [
                'url' => 'https://www.oregon.gov/dor/forms/FormsPubs/schedule-or-a_101-007_2025.pdf',
                'label' => 'Schedule OR-A — Oregon Itemized Deductions',
            ],

            // Oregon — S Corporation
            'or-20-s' => [
                'url' => 'https://www.oregon.gov/dor/forms/FormsPubs/form-or-20-s_102-025_2025.pdf',
                'label' => 'Form OR-20-S — Oregon S Corporation Tax Return',
            ],
        ];
    }

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $force = (bool) $this->option('force');
        $basePath = "tax-form-templates/{$year}";
        $forms = $this->formUrls($year);

        // Check for Ghostscript
        $hasGs = $this->hasGhostscript();

        if (! $hasGs) {
            $this->warn('Ghostscript (gs) not found — PDFs will not be converted to FPDI-compatible format.');
            $this->line('Install with: deploy/ghostscript.sh');
            $this->newLine();
        }

        $this->info("Downloading {$year} official fillable PDF forms...");
        $this->newLine();

        $downloaded = 0;
        $failed = 0;

        foreach ($forms as $code => $form) {
            $storagePath = "{$basePath}/{$code}.pdf";

            if (! $force && Storage::exists($storagePath)) {
                $this->line("  ✓ {$form['label']} — already exists");
                $downloaded++;

                continue;
            }

            $this->line("  ↓ Downloading {$form['label']}...");

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'application/pdf,*/*',
                ])->timeout(30)->get($form['url']);

                $body = $response->body();

                if ($response->successful() && str_starts_with($body, '%PDF') && strlen($body) > 1000) {
                    Storage::put($storagePath, $body);

                    // Convert to PDF 1.4 so FPDI free parser can read it
                    if ($hasGs) {
                        $converted = $this->convertToPdf14($storagePath);

                        if ($converted) {
                            $this->line('    ✓ Downloaded and converted to PDF 1.4');
                        } else {
                            $this->line('    ✓ Downloaded (conversion failed, may not work with FPDI)');
                        }
                    } else {
                        $this->line('    ✓ Downloaded (not converted — install Ghostscript for FPDI compatibility)');
                    }

                    $downloaded++;
                } else {
                    $this->error("    ✗ Failed: HTTP {$response->status()} or invalid PDF content");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->error("    ✗ Failed: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Downloaded: {$downloaded}, Failed: {$failed}");

        if ($failed > 0) {
            $this->warn('You can manually download forms from https://www.irs.gov/forms-instructions');
            $this->warn("Place them in storage/app/{$basePath}/ and re-run with --force to convert.");
        }

        if (! $hasGs && $downloaded > 0) {
            $this->newLine();
            $this->warn('To convert existing PDFs for FPDI compatibility:');
            $this->line('  1. Install Ghostscript: deploy/ghostscript.sh');
            $this->line("  2. Re-run: php artisan tax:download-official-forms --year={$year} --force");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Convert a PDF to version 1.4 using Ghostscript so FPDI can read it.
     */
    protected function convertToPdf14(string $storagePath): bool
    {
        $content = Storage::get($storagePath);

        // Write to a temp file for gs processing
        $tempInput = tempnam(sys_get_temp_dir(), 'pdf_in_');
        $tempOutput = tempnam(sys_get_temp_dir(), 'pdf_out_');
        file_put_contents($tempInput, $content);

        try {
            $result = Process::run([
                'gs',
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                '-dNOPAUSE',
                '-dQUIET',
                '-dBATCH',
                '-sOutputFile='.$tempOutput,
                $tempInput,
            ]);

            if ($result->successful() && file_exists($tempOutput) && filesize($tempOutput) > 100) {
                Storage::put($storagePath, file_get_contents($tempOutput));

                return true;
            }

            return false;
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($tempInput);
            @unlink($tempOutput);
        }
    }

    protected function hasGhostscript(): bool
    {
        try {
            $result = Process::run(['gs', '--version']);

            return $result->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
