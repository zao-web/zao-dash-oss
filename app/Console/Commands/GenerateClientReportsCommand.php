<?php

namespace App\Console\Commands;

use App\Jobs\GenerateClientReportJob;
use App\Models\Client;
use App\Services\Reports\ClientReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateClientReportsCommand extends Command
{
    protected $signature = 'reports:generate
        {--client= : Specific client ID (omit for all clients due today)}
        {--month= : Month to report on (YYYY-MM format, defaults to previous month)}
        {--type=monthly : Report type (monthly, quarterly, weekly)}
        {--send : Send via email after generation}
        {--queue : Queue the job instead of running immediately}';

    protected $description = 'Generate monthly client reports';

    public function handle(ClientReportService $reportService): int
    {
        $clientId = $this->option('client');
        $monthInput = $this->option('month');
        $type = $this->option('type');
        $send = $this->option('send');
        $queue = $this->option('queue');

        // Determine period
        if ($monthInput) {
            $periodStart = Carbon::createFromFormat('Y-m', $monthInput)->startOfMonth();
            $periodEnd = $periodStart->copy()->endOfMonth();
        } else {
            $periodStart = now()->subMonth()->startOfMonth();
            $periodEnd = now()->subMonth()->endOfMonth();
        }

        $this->info("Generating {$type} reports for {$periodStart->format('F Y')}");

        // Get clients
        if ($clientId) {
            $client = Client::findOrFail($clientId);
            $clients = collect([$client]);
        } else {
            // Get clients due for reports today (based on settings)
            $clients = $reportService->getClientsDueForReports();

            if ($clients->isEmpty()) {
                // If no clients due today, get all clients with report settings enabled
                $clients = Client::whereHas('clientReportSettings', function ($q) {
                    $q->where('is_enabled', true);
                })->get();
            }
        }

        if ($clients->isEmpty()) {
            $this->warn('No clients found for report generation');

            return self::SUCCESS;
        }

        $this->info("Found {$clients->count()} client(s) to process");

        $bar = $this->output->createProgressBar($clients->count());
        $bar->start();

        $generated = 0;
        $failed = 0;

        foreach ($clients as $client) {
            try {
                $job = new GenerateClientReportJob(
                    client: $client,
                    periodStart: $periodStart,
                    periodEnd: $periodEnd,
                    reportType: $type,
                    generatedBy: null,
                    sendEmail: $send
                );

                if ($queue) {
                    dispatch($job);
                    $this->line(" Queued: {$client->name}");
                } else {
                    dispatch_sync($job);
                    $this->line(" Generated: {$client->name}");
                }

                $generated++;
            } catch (\Exception $e) {
                $this->error(" Failed: {$client->name} - {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Complete! Generated: {$generated}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
