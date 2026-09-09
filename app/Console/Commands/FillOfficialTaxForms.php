<?php

namespace App\Console\Commands;

use App\Services\Tax\OfficialForms\FillableFormService;
use App\Services\Tax\OfficialForms\OfficialTaxFormService;
use Illuminate\Console\Command;

class FillOfficialTaxForms extends Command
{
    protected $signature = 'tax:fill-official-forms
        {user : The user ID to fill forms for}
        {--year= : Tax year (defaults to prior year)}';

    protected $description = 'Fill official IRS/Oregon PDF forms with workpaper data and output download links';

    public function handle(FillableFormService $fillable, OfficialTaxFormService $substitute): int
    {
        $userId = (int) $this->argument('user');
        $year = (int) ($this->option('year') ?? (now()->year - 1));
        $baseUrl = rtrim((string) config('app.url'), '/');

        $this->info("Generating tax forms for user #{$userId}, tax year {$year}");
        $this->info(str_repeat('─', 60));
        $this->newLine();

        // 1. Fill official IRS/Oregon PDFs
        $this->info('Filling official IRS/Oregon PDF forms...');
        $fillResult = $fillable->fillAllForms($userId, $year);

        foreach ($fillResult['forms'] as $form) {
            $icon = $form['status'] === 'filled' ? '✓' : '✗';
            $this->line("  {$icon} {$form['form']} — {$form['status']}");
        }

        $this->newLine();

        // 2. Generate substitute forms as backup
        $this->info('Generating substitute forms (backup set)...');
        $subResult = $substitute->generateFilingPackage($userId, $year);

        foreach ($subResult['forms'] as $form) {
            $this->line("  ✓ {$form['form']} — {$form['title']}");
        }

        $this->newLine();
        $this->info(str_repeat('─', 60));
        $this->info("Results: {$fillResult['generated']} official + {$subResult['generated']} substitute forms");

        // 3. Output download links
        if ($fillResult['generated'] > 0 || $subResult['generated'] > 0) {
            $this->newLine();
            $this->info('Download links (official filled forms):');

            foreach ($fillResult['paths'] as $code => $path) {
                $relativePath = str_replace('tax-forms/', '', $path);
                $url = "{$baseUrl}/life/tax-forms/{$relativePath}";
                $this->line("  {$code}: {$url}");
            }

            if ($fillResult['generated'] === 0) {
                $this->warn('  No official forms were filled — templates may be missing.');
                $this->line('  Run: php artisan tax:download-official-forms --year='.$year);
            }

            $this->newLine();
            $this->info('Download links (substitute forms):');

            foreach ($subResult['paths'] as $code => $path) {
                $relativePath = str_replace('tax-forms/', '', $path);
                $url = "{$baseUrl}/life/tax-forms/{$relativePath}";
                $this->line("  {$code}: {$url}");
            }
        }

        // 4. Coordinate calibration reminder
        if ($fillResult['generated'] > 0) {
            $this->newLine();
            $this->warn('Review the filled PDFs — coordinates may need calibration.');
            $this->line('If values are misaligned, run:');
            $this->line('  php artisan tax:dump-form-fields f1040 --json');
            $this->line('and paste the output back to Claude to adjust config/tax-form-fields.php.');
        }

        return self::SUCCESS;
    }
}
