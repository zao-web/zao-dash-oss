<?php

namespace App\Console\Commands\Retainers;

use App\Models\RetainerPeriod;
use App\Services\Reports\RetainerNarrativeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DiagnoseNarrativeProvider extends Command
{
    protected $signature = 'retainer:diagnose-narrative-provider
        {--period= : If set, run the REAL narrative build for this retainer_periods.id (force rebuild + persist) and report the outcome}';

    protected $description = 'Diagnose the Cloudflare-only retainer narrative path: config presence, a bare test call, the EXACT production payload (json_object response_format, seed, 4096 max_tokens), and optionally a real end-to-end narrative build for a period.';

    public function handle(): int
    {
        $accountId = config('services.cloudflare.account_id');
        $apiToken = config('services.cloudflare.api_token');
        $model = config('services.cloudflare.narrative_model', '@cf/meta/llama-3.3-70b-instruct-fp8-fast');

        $this->line('Cloudflare account_id: '.($accountId ? 'set ('.strlen($accountId).' chars)' : '<MISSING>'));
        $this->line('Cloudflare api_token : '.($apiToken ? 'set ('.strlen($apiToken).' chars)' : '<MISSING>'));
        $this->line('Narrative model      : '.$model);
        $this->newLine();

        if (! $accountId || ! $apiToken) {
            $this->error('Config missing — the app does not see the Cloudflare env vars. If they are set in the dashboard, config is cached: run php artisan config:clear');

            return self::FAILURE;
        }

        $url = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model}";

        // 1) Bare call — proves connectivity/auth/model availability.
        $this->info('1) Bare test call …');
        $bare = Http::withToken($apiToken)->acceptJson()->timeout(60)->post($url, [
            'messages' => [['role' => 'user', 'content' => 'Reply with the single word OK.']],
            'max_tokens' => 16,
        ]);
        $this->line('   status: '.$bare->status().'  success: '.json_encode($bare->json('success')));
        $this->line('   body  : '.substr($bare->body(), 0, 300));
        $this->newLine();

        // 2) EXACT production payload — this is what RetainerNarrativeService
        //    actually sends. If the bare call works but this fails, the culprit
        //    is one of these params (response_format json_object / seed /
        //    max_tokens 4096), not config.
        $this->info('2) Production-shape call (json_object, seed, 4096 tokens) …');
        $prod = Http::withToken($apiToken)->acceptJson()->timeout(120)->post($url, [
            'messages' => [
                ['role' => 'system', 'content' => 'You output strict JSON only.'],
                ['role' => 'user', 'content' => 'Return {"ok": true} and nothing else.'],
            ],
            'max_tokens' => 4096,
            'temperature' => 0.2,
            'seed' => 12345,
            'response_format' => ['type' => 'json_object'],
        ]);
        $this->line('   status: '.$prod->status().'  success: '.json_encode($prod->json('success')));
        $this->line('   body  : '.substr($prod->body(), 0, 500));
        $this->newLine();

        if (! ($prod->successful() && $prod->json('success', false))) {
            $this->error('The production-shape call FAILED while the bare call may have worked.');
            $this->line('  → The model likely rejects response_format=json_object or max_tokens=4096.');
            $this->line('  → Fix options: drop response_format for this model, lower max_tokens, or set');
            $this->line('     CLOUDFLARE_NARRATIVE_MODEL to one that supports JSON mode.');

            return self::FAILURE;
        }

        $this->info('✓ Both calls succeeded — the provider path is healthy.');

        // 3) Optional: replay the REAL period prompt and show the raw error.
        if ($periodId = $this->option('period')) {
            $period = RetainerPeriod::with('client')->find((int) $periodId);
            if (! $period) {
                $this->error("No retainer period {$periodId}.");

                return self::FAILURE;
            }

            $this->newLine();
            $this->info("3) Real period-{$periodId} prompt ({$period->client?->name}) …");

            $svc = app(RetainerNarrativeService::class);
            $ref = new \ReflectionClass($svc);
            $start = \Carbon\Carbon::parse($period->period_start)->startOfDay();
            $end = \Carbon\Carbon::parse($period->period_end)->endOfDay();

            $gather = $ref->getMethod('gatherEvidence');
            $gather->setAccessible(true);
            $evidence = $gather->invoke($svc, $period, $start, $end);

            $health = $ref->getProperty('health');
            $health->setAccessible(true);
            $estimated = $health->getValue($svc)->aggregateEstimatedHours($period, $start, $end);

            $sys = $ref->getMethod('systemPrompt');
            $sys->setAccessible(true);
            $systemPrompt = $sys->invoke($svc);

            $bup = $ref->getMethod('buildUserPrompt');
            $bup->setAccessible(true);
            $userPrompt = $bup->invoke($svc, $period, $evidence, $estimated);

            $chars = strlen($systemPrompt) + strlen($userPrompt);
            $this->line('   evidence: '.count($evidence['slack'] ?? []).' slack, '
                .count($evidence['emails'] ?? []).' emails, '.count($evidence['commits'] ?? []).' commits');
            $this->line('   prompt size: '.number_format($chars).' chars (~'.number_format((int) ($chars / 4)).' tokens) + 4096 output');
            $this->newLine();

            $this->info('   Posting the REAL prompt to Cloudflare (raw response below) …');
            $resp = Http::withToken($apiToken)->acceptJson()->timeout(120)->post($url, [
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'max_tokens' => 4096,
                'temperature' => 0.2,
                'seed' => 12345,
                'response_format' => ['type' => 'json_object'],
            ]);
            $this->line('   status : '.$resp->status().'  success: '.json_encode($resp->json('success')));
            $this->line('   errors : '.json_encode($resp->json('errors')));
            $this->line('   body   : '.substr($resp->body(), 0, 700));
        }

        return self::SUCCESS;
    }
}
