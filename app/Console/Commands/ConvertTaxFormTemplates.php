<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ConvertTaxFormTemplates extends Command
{
    protected $signature = 'tax:convert-templates {--year=2025}';

    protected $description = 'Convert existing tax form template PDFs to PDF 1.4 for FPDI compatibility (requires Ghostscript)';

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $basePath = "tax-form-templates/{$year}";

        // Check Ghostscript
        try {
            $gs = Process::run(['gs', '--version']);

            if (! $gs->successful()) {
                $this->error('Ghostscript (gs) not found. Install with: apt-get install ghostscript');

                return self::FAILURE;
            }

            $this->info('Ghostscript '.trim($gs->output()).' found.');
        } catch (\Throwable) {
            $this->error('Ghostscript (gs) not found. Install with: apt-get install ghostscript');

            return self::FAILURE;
        }

        $forms = [
            'f1040', 'f1040s1', 'f1040sa', 'f1040se', 'f1120s',
            'f1120ssk', 'f8995', 'or-40', 'or-a', 'or-20-s',
            'or-add-dep',
        ];

        $converted = 0;
        $skipped = 0;

        foreach ($forms as $code) {
            $storagePath = "{$basePath}/{$code}.pdf";

            if (! Storage::exists($storagePath)) {
                $this->warn("  ✗ {$code}.pdf — not found, skipping");
                $skipped++;

                continue;
            }

            $content = Storage::get($storagePath);

            if (strlen($content) < 1000 || ! str_starts_with($content, '%PDF')) {
                $this->warn("  ✗ {$code}.pdf — invalid PDF, skipping");
                $skipped++;

                continue;
            }

            $this->line("  ↻ Converting {$code}.pdf to PDF 1.4...");

            $tempInput = tempnam(sys_get_temp_dir(), 'pdf_in_');
            $tempOutput = tempnam(sys_get_temp_dir(), 'pdf_out_');
            file_put_contents($tempInput, $content);

            try {
                $result = Process::run([
                    'gs', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4',
                    '-dNOPAUSE', '-dQUIET', '-dBATCH',
                    '-sOutputFile='.$tempOutput, $tempInput,
                ]);

                if ($result->successful() && file_exists($tempOutput) && filesize($tempOutput) > 100) {
                    Storage::put($storagePath, file_get_contents($tempOutput));
                    $newSize = filesize($tempOutput);
                    $this->line('    ✓ Converted — '.number_format($newSize).' bytes');
                    $converted++;
                } else {
                    $this->error("    ✗ Conversion failed: {$result->errorOutput()}");
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $this->error("    ✗ Error: {$e->getMessage()}");
                $skipped++;
            } finally {
                @unlink($tempInput);
                @unlink($tempOutput);
            }
        }

        $this->newLine();
        $this->info("Converted: {$converted}, Skipped: {$skipped}");

        if ($converted > 0) {
            $this->info('Templates are now FPDI-compatible. Run tax:check-dependencies to verify.');
        }

        return $skipped > 0 ? self::FAILURE : self::SUCCESS;
    }
}
