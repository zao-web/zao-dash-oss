<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

class DumpTaxFormFields extends Command
{
    protected $signature = 'tax:dump-form-fields
        {form : Form code (e.g. f1040, f1120s, or-40)}
        {--year=2025}
        {--json : Output structured JSON for coordinate mapping revision}';

    protected $description = 'Inspect an official PDF form template and show current coordinate mappings for revision';

    public function handle(): int
    {
        $formCode = $this->argument('form');
        $year = (int) $this->option('year');
        $storagePath = "tax-form-templates/{$year}/{$formCode}.pdf";

        $filePath = resource_path($storagePath);

        if (! file_exists($filePath)) {
            $this->error("Form not found at resources/{$storagePath}");
            $this->line('Place PDF 1.4 templates in resources/tax-form-templates/{year}/');

            return self::FAILURE;
        }

        $pdfContent = file_get_contents($filePath);

        try {
            $fpdi = new Fpdi;
            $stream = StreamReader::createByString($pdfContent);
            $pageCount = $fpdi->setSourceFile($stream);
        } catch (\Throwable $e) {
            $this->error("Failed to read PDF: {$e->getMessage()}");

            return self::FAILURE;
        }

        $currentMappings = config("tax-form-fields.{$formCode}", []);

        if ($this->option('json')) {
            return $this->outputJson($formCode, $year, $pageCount, $currentMappings, $pdfContent);
        }

        return $this->outputTable($formCode, $year, $pageCount, $currentMappings, $pdfContent);
    }

    protected function outputTable(string $formCode, int $year, int $pageCount, array $currentMappings, string $pdfContent): int
    {
        $fileSize = strlen($pdfContent);

        $this->info("{$formCode}.pdf ({$year}) — {$pageCount} pages, ".number_format($fileSize).' bytes');
        $this->newLine();

        // Show page dimensions
        for ($i = 1; $i <= $pageCount; $i++) {
            $fpdi = new Fpdi;
            $fpdi->setSourceFile(StreamReader::createByString($pdfContent));
            $tpl = $fpdi->importPage($i);
            $size = $fpdi->getTemplateSize($tpl);
            $this->line("  Page {$i}: {$size['width']}mm × {$size['height']}mm ({$size['orientation']})");
        }

        $this->newLine();

        if ($currentMappings === []) {
            $this->warn('No coordinate mappings configured for this form in config/tax-form-fields.php');

            return self::SUCCESS;
        }

        $this->info('Current coordinate mappings:');
        $this->newLine();

        $rows = [];

        foreach ($currentMappings as $key => $coord) {
            $rows[] = [
                'Key' => $key,
                'Page' => $coord['page'] ?? 1,
                'X' => $coord['x'] ?? 0,
                'Y' => $coord['y'] ?? 0,
                'Size' => $coord['size'] ?? 9,
                'Align' => $coord['align'] ?? 'L',
                'Width' => $coord['width'] ?? 30,
            ];
        }

        $this->table(['Key', 'Page', 'X', 'Y', 'Size', 'Align', 'Width'], $rows);

        $this->newLine();
        $this->info(count($currentMappings).' coordinates mapped.');
        $this->newLine();
        $this->line('To revise, run with --json and paste the output back to Claude:');
        $this->line("  php artisan tax:dump-form-fields {$formCode} --year={$year} --json");
        $this->newLine();
        $this->line('Tip: Open the blank PDF in a viewer, note field positions in mm from top-left,');
        $this->line('then update config/tax-form-fields.php with the correct coordinates.');

        return self::SUCCESS;
    }

    protected function outputJson(string $formCode, int $year, int $pageCount, array $currentMappings, string $pdfContent): int
    {
        $pages = [];

        for ($i = 1; $i <= $pageCount; $i++) {
            $fpdi = new Fpdi;
            $fpdi->setSourceFile(StreamReader::createByString($pdfContent));
            $tpl = $fpdi->importPage($i);
            $size = $fpdi->getTemplateSize($tpl);
            $pages[] = [
                'page' => $i,
                'width_mm' => round($size['width'], 1),
                'height_mm' => round($size['height'], 1),
                'orientation' => $size['orientation'],
            ];
        }

        $structured = [
            'form_code' => $formCode,
            'year' => $year,
            'page_count' => $pageCount,
            'pages' => $pages,
            'current_coordinate_count' => count($currentMappings),
            'current_mappings' => $currentMappings,
            'instructions' => 'Update the x,y coordinates in config/tax-form-fields.php to match the actual field positions on the PDF. Coordinates are in mm from the top-left corner. Generate a test fill and visually inspect the output to calibrate.',
        ];

        $this->line(json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
