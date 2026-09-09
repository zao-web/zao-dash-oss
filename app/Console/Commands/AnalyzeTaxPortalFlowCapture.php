<?php

namespace App\Console\Commands;

use App\Services\Tax\Agency\TaxPortalFlowCaptureAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

class AnalyzeTaxPortalFlowCapture extends Command
{
    protected $signature = 'tax:analyze-portal-flow
        {capture : Absolute or repo-relative path to the exported tax flow JSON}
        {--write : Write a sibling .analysis.json file next to the capture}';

    protected $description = 'Analyze an exported IRS/Oregon tax portal flow capture and produce a bridge blueprint.';

    public function __construct(
        protected TaxPortalFlowCaptureAnalyzer $analyzer,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $capturePath = $this->resolveCapturePath((string) $this->argument('capture'));

        if (! File::exists($capturePath) || ! File::isFile($capturePath)) {
            $this->error("Capture file not found: {$capturePath}");

            return self::FAILURE;
        }

        try {
            /** @var array<string, mixed> $capture */
            $capture = json_decode(File::get($capturePath), true, 512, JSON_THROW_ON_ERROR);
            $analysis = $this->analyzer->analyze($capture);
            $encoded = json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("Capture file is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->line($encoded);

        if ($this->option('write')) {
            $analysisPath = $this->analysisPath($capturePath);
            File::put($analysisPath, $encoded.PHP_EOL);
            $this->newLine();
            $this->info("Analysis written to {$analysisPath}");
        }

        return self::SUCCESS;
    }

    protected function resolveCapturePath(string $capture): string
    {
        if (str_starts_with($capture, '/')) {
            return $capture;
        }

        return base_path($capture);
    }

    protected function analysisPath(string $capturePath): string
    {
        if (str_ends_with($capturePath, '.json')) {
            return substr($capturePath, 0, -5).'.analysis.json';
        }

        return $capturePath.'.analysis.json';
    }
}
