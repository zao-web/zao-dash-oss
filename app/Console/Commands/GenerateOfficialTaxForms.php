<?php

namespace App\Console\Commands;

use App\Services\Tax\OfficialForms\OfficialTaxFormService;
use Illuminate\Console\Command;

class GenerateOfficialTaxForms extends Command
{
    protected $signature = 'tax:generate-official-forms
        {user : The user ID to generate forms for}
        {--year= : Tax year (defaults to prior year)}';

    protected $description = 'Generate a complete set of official IRS/Oregon substitute tax forms from the workpaper packet';

    public function handle(OfficialTaxFormService $service): int
    {
        $userId = (int) $this->argument('user');
        $year = (int) ($this->option('year') ?? (now()->year - 1));

        $this->info("Generating official tax forms for user #{$userId}, tax year {$year}...");
        $this->newLine();

        $result = $service->generateFilingPackage($userId, $year);

        $this->info("Generated {$result['generated']} forms:");
        $this->newLine();

        foreach ($result['forms'] as $form) {
            $this->line("  ✓ {$form['form']} — {$form['title']}");
            $this->line("    → {$form['path']}");
        }

        $this->newLine();
        $this->info("Filing package stored at: {$result['filing_package_path']}");

        return self::SUCCESS;
    }
}
