<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

class CheckTaxFormDependencies extends Command
{
    protected $signature = 'tax:check-dependencies {--year=2025}';

    protected $description = 'Verify that form templates are present and FPDI can read them';

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $allGood = true;

        // 1. Check PHP dependencies
        $this->info('Checking PHP dependencies...');

        if (class_exists(Fpdi::class)) {
            $this->line('  ✓ setasign/fpdi (TCPDF backend) installed');
        } else {
            $this->error('  ✗ setasign/fpdi not installed — run: composer require setasign/fpdi');
            $allGood = false;
        }

        if (class_exists(\TCPDF::class)) {
            $this->line('  ✓ tecnickcom/tcpdf installed');
        } else {
            $this->error('  ✗ tecnickcom/tcpdf not installed — run: composer require tecnickcom/tcpdf');
            $allGood = false;
        }

        $gsVersion = trim((string) shell_exec('gs --version 2>/dev/null'));

        if ($gsVersion !== '') {
            $this->line("  ✓ Ghostscript {$gsVersion} installed (for PDF 1.4 conversion)");
        } else {
            $this->warn('  ⚠ Ghostscript not found — run deploy/ghostscript.sh for PDF 1.4 conversion');
        }

        // 2. Check form templates
        $this->newLine();
        $this->info("Checking {$year} form templates...");

        $requiredForms = [
            'f1040' => 'Form 1040',
            'f1040s1' => 'Schedule 1',
            'f1040sa' => 'Schedule A',
            'f1040se' => 'Schedule E',
            'f1120s' => 'Form 1120-S',
            'f1120ssk' => 'Schedule K-1',
            'f8995' => 'Form 8995',
            'or-40' => 'Oregon OR-40',
            'or-a' => 'Oregon Schedule OR-A',
            'or-20-s' => 'Oregon OR-20-S',
        ];

        $present = 0;
        $missing = 0;

        foreach ($requiredForms as $code => $label) {
            $path = resource_path("tax-form-templates/{$year}/{$code}.pdf");

            if (file_exists($path)) {
                $size = filesize($path);
                $header = substr((string) file_get_contents($path, false, null, 0, 5), 0, 5);

                if ($header === '%PDF-') {
                    $this->line("  ✓ {$label} ({$code}.pdf) — ".number_format($size).' bytes');
                    $present++;
                } else {
                    $this->error("  ✗ {$label} ({$code}.pdf) — exists but is not a valid PDF");
                    $allGood = false;
                    $missing++;
                }
            } else {
                $this->warn("  ✗ {$label} ({$code}.pdf) — missing");
                $missing++;
            }
        }

        $this->newLine();

        if ($missing > 0) {
            $this->warn("{$present} present, {$missing} missing");
            $this->line("Run: php artisan tax:download-official-forms --year={$year}");
            $allGood = false;
        } else {
            $this->info("All {$present} form templates present.");
        }

        // 3. Check coordinate mappings
        $this->newLine();
        $this->info('Checking coordinate mappings...');

        $mappings = config('tax-form-fields', []);

        foreach ($requiredForms as $code => $label) {
            $fields = $mappings[$code] ?? [];

            if ($fields !== []) {
                $this->line("  ✓ {$label} — ".count($fields).' coordinates mapped');
            } else {
                $this->warn("  ✗ {$label} — no coordinate mapping in config/tax-form-fields.php");
            }
        }

        // 4. Quick FPDI import test
        if ($allGood && $present > 0) {
            $this->newLine();
            $this->info('FPDI import test...');
            $testPath = resource_path("tax-form-templates/{$year}/f1040.pdf");

            if (file_exists($testPath)) {
                try {
                    $fpdi = new Fpdi;
                    $stream = StreamReader::createByString(file_get_contents($testPath));
                    $pageCount = $fpdi->setSourceFile($stream);
                    $this->line("  ✓ FPDI can read f1040.pdf ({$pageCount} pages)");
                } catch (\Throwable $e) {
                    $this->error('  ✗ FPDI failed to read f1040.pdf: '.$e->getMessage());
                    $allGood = false;
                }
            }
        }

        $this->newLine();

        if ($allGood) {
            $this->info('All checks passed. Ready to fill official forms via the UI.');
        } else {
            $this->error('Some checks failed. See above for resolution steps.');
        }

        return $allGood ? self::SUCCESS : self::FAILURE;
    }
}
