<?php

namespace App\Console\Commands\Retainers;

use App\Models\RetainerPeriod;
use App\Services\Reports\RetainerNarrativeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NarrativeFromText extends Command
{
    protected $signature = 'retainer:narrative-from-text
        {--period= : retainer_periods.id to build the narrative for}
        {--file= : Path to a transcript file (absolute, or relative to storage/app). Omit to read from STDIN}
        {--dry-run : Generate and print the narrative without persisting or caching}';

    protected $description = 'Build a retainer period narrative + AI hour estimates from a pasted/file transcript, for work that never synced into Slack/email/commits. Runs the same LLM estimation pipeline as the normal report and persists the result so the report reflects it.';

    public function handle(RetainerNarrativeService $service): int
    {
        $periodId = $this->option('period');
        if (! $periodId || ! is_numeric($periodId)) {
            $this->error('Pass a numeric --period (retainer_periods.id).');

            return self::FAILURE;
        }

        $period = RetainerPeriod::with('client')->find((int) $periodId);
        if (! $period) {
            $this->error("No retainer period found for id {$periodId}.");

            return self::FAILURE;
        }

        $transcript = $this->readTranscript();
        if ($transcript === null) {
            return self::FAILURE;
        }

        if (trim($transcript) === '') {
            $this->error('Transcript is empty. Provide --file= or pipe text via STDIN.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Generating narrative for period %d (%s, %s–%s) from %s chars of transcript …',
            $period->id,
            $period->client?->name ?? 'no client',
            $period->period_start,
            $period->period_end,
            number_format(strlen($transcript)),
        ));

        $result = $service->buildNarrativeFromText($period, $transcript, persist: ! $this->option('dry-run'));

        if (! empty($result['warnings'])) {
            $this->error('Narrative had issues: '.implode(' / ', $result['warnings']));

            return self::FAILURE;
        }

        $topics = $result['topics'] ?? [];
        $this->newLine();
        $this->table(
            ['Topic', 'Status', 'Hours', 'Dates'],
            collect($topics)->map(fn ($t) => [
                Str::limit($t['title'] ?? '—', 50),
                $t['status'] ?? '—',
                number_format((float) ($t['estimated_hours'] ?? 0), 2),
                trim(($t['start_date'] ?? '?').' → '.($t['end_date'] ?? '?')),
            ])->all(),
        );

        $this->info(sprintf(
            '%d topic(s), %s total estimated hours.',
            count($topics),
            number_format((float) ($result['total_estimated_hours'] ?? 0), 2),
        ));

        if (! empty($result['value_summary'])) {
            $this->newLine();
            $this->line('<comment>Value summary:</comment> '.$result['value_summary']);
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Dry run — nothing persisted. Re-run without --dry-run to save and update the report.');
        } else {
            $this->newLine();
            $this->info('Persisted as ai_estimated time entries and cached. Refresh the retainer report to see it.');
        }

        return self::SUCCESS;
    }

    /**
     * Read the transcript from --file (absolute or storage/app-relative) or,
     * when no file is given, from STDIN (supports heredoc paste in the runner).
     */
    protected function readTranscript(): ?string
    {
        $file = $this->option('file');

        if ($file) {
            if (is_file($file)) {
                return (string) file_get_contents($file);
            }

            if (Storage::exists($file)) {
                return (string) Storage::get($file);
            }

            $this->error("Transcript file not found: {$file} (looked on disk and in storage/app).");

            return null;
        }

        $this->line('Reading transcript from STDIN … (end with Ctrl-D, or use a heredoc)');

        return (string) file_get_contents('php://stdin');
    }
}
