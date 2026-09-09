<?php

namespace App\Services\Reports;

use App\Models\AgentRun;
use App\Models\CalendarEvent;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

class RetainerReportPdfGenerator
{
    protected string $tempDir;

    protected bool $useBrowsershot;

    protected ?string $chromePath = null;

    public function __construct(protected RetainerHealthService $health)
    {
        $this->tempDir = storage_path('app/temp/retainer-reports');

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        $this->chromePath = $this->findChromePath();
        $this->useBrowsershot = $this->chromePath !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildViewData(RetainerPeriod $period): array
    {
        $period->loadMissing('client');

        $start = $period->windowStart();
        $end = $period->windowEnd();

        $snapshot = $this->health->computeAndPersistSnapshot($period, $start, $end, persist: false);

        // Read narrative from cache only — never block render on the LLM
        // call. If empty, the user clicks "Refresh Data" which triggers
        // RetainerNarrativeService::buildNarrative() in the controller.
        $narrative = app(RetainerNarrativeService::class)->getCached($period);

        // Pull the currently-active items from the activity-feed pipeline
        // (separate synthesis, 30-min TTL). Surfaced ABOVE the closed-period
        // narrative so clients see what's in flight before billing detail.
        $currentlyActive = $period->client
            ? app(\App\Services\Activity\ClientActivityService::class)->getCached($period->client)
            : null;

        // Union of real tracked time + AI-estimated entries this narrative
        // synthesized for the period. The report's time entries section
        // should never be empty when there's observable activity.
        //
        // The date-range branch only admits entries not claimed by any period:
        // spent_date is a bare DATE, and the Pacific window end lands hours
        // into the next UTC day, so without the retainer_period_id guard the
        // next period's first-day entries leak into this report.
        $timeEntries = TimeEntry::query()
            ->where(function ($q) use ($period) {
                $q->where('retainer_period_id', $period->id)
                    ->orWhere(function ($inner) use ($period) {
                        $inner->where('client_id', $period->client_id)
                            ->whereNull('retainer_period_id')
                            ->whereBetween('spent_date', [
                                $period->period_start->toDateString(),
                                $period->period_end->toDateString(),
                            ]);
                    });
            })
            ->orderBy('spent_date')
            ->get();

        $meetings = CalendarEvent::query()
            ->where('client_id', $period->client_id)
            ->where('is_client_meeting', true)
            ->whereBetween('start_at', [$start, $end])
            ->orderBy('start_at')
            ->get();

        $agentRuns = AgentRun::query()
            ->where('client_id', $period->client_id)
            ->whereBetween('started_at', [$start, $end])
            ->orderBy('started_at')
            ->get();

        return [
            'period' => $period,
            'client' => $period->client,
            'start' => $start,
            'end' => $end,
            'snapshot' => $snapshot,
            'narrative' => $narrative,
            'currentlyActive' => $currentlyActive,
            'timeEntries' => $timeEntries,
            'meetings' => $meetings,
            'agentRuns' => $agentRuns,
            'company' => [
                'name' => config('app.company_name', 'Zao'),
                'email' => config('app.company_email', 'billing@example.com'),
            ],
        ];
    }

    public function renderHtml(RetainerPeriod $period): string
    {
        return View::make('retainer-reports.show', array_merge($this->buildViewData($period), [
            'isPdf' => true,
        ]))->render();
    }

    public function generateAndStore(RetainerPeriod $period): string
    {
        $html = $this->renderHtml($period);
        $filename = sprintf('retainer-report-%d-%s.pdf', $period->id, Carbon::parse($period->period_start)->format('Y-m'));
        $storagePath = "retainer-reports/{$filename}";
        $tempPath = "{$this->tempDir}/{$filename}";

        $this->renderPdf($html, $tempPath, $filename);

        Storage::put($storagePath, file_get_contents($tempPath));
        @unlink($tempPath);

        return $storagePath;
    }

    public function getStoragePath(RetainerPeriod $period): string
    {
        $filename = sprintf('retainer-report-%d-%s.pdf', $period->id, Carbon::parse($period->period_start)->format('Y-m'));

        return "retainer-reports/{$filename}";
    }

    /**
     * Render HTML to PDF using Browsershot (Chrome headless) when available,
     * falling back to dompdf. Mirrors PdfInvoiceGenerator's approach so
     * retainer reports match invoice quality.
     */
    protected function renderPdf(string $html, string $tempPath, string $filename): void
    {
        // Tier 1: Cloudflare Browser Rendering (free tier, ~120 PDFs/day).
        // Same Chrome rendering quality as Browsershot, no local install
        // required. Best on Laravel Cloud where local Chrome can't run.
        if ($this->renderViaCloudflare($html, $tempPath, $filename)) {
            return;
        }

        if ($this->useBrowsershot && $this->chromePath) {
            try {
                $browsershot = Browsershot::html($html)
                    ->setChromePath($this->chromePath)
                    ->format('Letter')
                    ->margins(0, 0, 0, 0)
                    ->showBackground()
                    ->scale(1.0)
                    ->deviceScaleFactor(2)
                    ->waitUntilNetworkIdle()
                    ->waitForFunction('document.fonts.ready');

                $modulePaths = [
                    base_path('node_modules'),
                    getenv('HOME').'/bin/puppeteer/node_modules',
                    '/home/clouduser/bin/puppeteer/node_modules',
                    '/var/www/bin/puppeteer/node_modules',
                ];
                foreach ($modulePaths as $path) {
                    if (is_dir($path) && is_dir($path.'/puppeteer')) {
                        $browsershot->setNodeModulePath($path);
                        break;
                    }
                }

                if (getenv('LARAVEL_CLOUD') || file_exists('/.dockerenv')) {
                    $browsershot->noSandbox()
                        ->addChromiumArguments([
                            'disable-gpu',
                            'disable-dev-shm-usage',
                            'disable-setuid-sandbox',
                            'font-render-hinting=none',
                        ]);
                }

                $browsershot->save($tempPath);

                return;
            } catch (\Exception $e) {
                Log::warning('Browsershot retainer PDF failed, trying wkhtmltopdf next', [
                    'file' => $filename,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // wkhtmltopdf — middle tier. Supports modern-ish CSS (much more than
        // dompdf) and has ARM64 binaries, so works on Laravel Cloud where
        // Chrome doesn't. Installed via deploy/wkhtmltopdf.sh.
        if ($wkPath = $this->findWkhtmltopdfPath()) {
            try {
                $htmlPath = $tempPath.'.html';
                file_put_contents($htmlPath, $html);
                $process = new \Symfony\Component\Process\Process([
                    $wkPath,
                    '--quiet',
                    '--enable-local-file-access',
                    '--print-media-type',
                    '--page-size', 'Letter',
                    '--margin-top', '0.4in',
                    '--margin-bottom', '0.4in',
                    '--margin-left', '0.4in',
                    '--margin-right', '0.4in',
                    '--encoding', 'utf-8',
                    $htmlPath,
                    $tempPath,
                ]);
                $process->setTimeout(60);
                $process->run();
                @unlink($htmlPath);

                if ($process->isSuccessful() && file_exists($tempPath) && filesize($tempPath) > 0) {
                    return;
                }

                Log::warning('wkhtmltopdf failed, falling back to dompdf', [
                    'file' => $filename,
                    'exit' => $process->getExitCode(),
                    'stderr' => $process->getErrorOutput(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('wkhtmltopdf threw, falling back to dompdf', [
                    'file' => $filename,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Pdf::loadHTML($html)
            ->setPaper('letter')
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true)
            ->save($tempPath);
    }

    /**
     * Render via Cloudflare Browser Rendering REST API. Returns true if the
     * PDF was successfully written to $tempPath, false otherwise (caller
     * proceeds to next tier).
     *
     * Needs CLOUDFLARE_ACCOUNT_ID + a CLOUDFLARE_API_TOKEN with the
     * "Browser Rendering" Account permission.
     *
     * Docs: https://developers.cloudflare.com/browser-rendering/rest-api/pdf/
     */
    protected function renderViaCloudflare(string $html, string $tempPath, string $filename): bool
    {
        $accountId = config('services.cloudflare.account_id');
        $apiToken = config('services.cloudflare.api_token');

        if (! $accountId || ! $apiToken) {
            return false;
        }

        try {
            // CF's /pdf endpoint only accepts a narrow set of top-level keys:
            //   html, url, viewport, gotoOptions, addStyleTag, addScriptTag,
            //   setExtraHTTPHeaders, userAgent.
            // PDF format/margins are controlled entirely via CSS @page
            // (printBackground / preferCSSPageSize / format are NOT accepted
            // here — sending them returns 400 "unrecognized_keys").
            $response = \Illuminate\Support\Facades\Http::withToken($apiToken)
                ->timeout(120)
                ->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/browser-rendering/pdf", [
                    'html' => $html,
                    // @page CSS in the Blade template already declares
                    // size + margins; this is a defensive backstop only.
                    'addStyleTag' => [['content' => '@page { size: Letter; margin: 0.5in; }']],
                    'viewport' => ['width' => 1100, 'height' => 1500, 'deviceScaleFactor' => 2],
                    'gotoOptions' => ['waitUntil' => 'networkidle0', 'timeout' => 60000],
                ]);

            if (! $response->successful() || strlen($response->body()) < 1000) {
                Log::warning('Cloudflare Browser Rendering failed', [
                    'file' => $filename,
                    'status' => $response->status(),
                    'body_preview' => substr($response->body(), 0, 400),
                ]);

                return false;
            }

            file_put_contents($tempPath, $response->body());

            return file_exists($tempPath) && filesize($tempPath) > 1000;
        } catch (\Throwable $e) {
            Log::warning('Cloudflare Browser Rendering threw', [
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function findWkhtmltopdfPath(): ?string
    {
        $homeDir = getenv('HOME') ?: '/home/clouduser';
        $candidates = [
            "{$homeDir}/bin/wkhtmltopdf",
            '/usr/local/bin/wkhtmltopdf',
            '/usr/bin/wkhtmltopdf',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function findChromePath(): ?string
    {
        $homeDir = getenv('HOME') ?: '/home/clouduser';
        $candidates = [
            "{$homeDir}/bin/chrome/chrome-headless-shell",
            "{$homeDir}/bin/chrome/chrome",
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_executable($path) && $this->chromeBinaryWorks($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Confirm the Chrome binary actually runs on this host. Existence on
     * disk isn't enough — Laravel Cloud's ARM64 containers have x86_64
     * Chrome downloads that fail at exec with "Exec format error" or
     * missing shared libraries. Test before claiming Browsershot is viable.
     */
    protected function chromeBinaryWorks(string $path): bool
    {
        static $cache = [];
        if (isset($cache[$path])) {
            return $cache[$path];
        }

        try {
            $process = new \Symfony\Component\Process\Process([$path, '--version']);
            $process->setTimeout(5);
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();
            $broken = $process->getExitCode() > 1
                || str_contains($output, 'Exec format error')
                || str_contains($output, 'cannot open shared object')
                || str_contains($output, 'cannot execute');
        } catch (\Throwable $e) {
            $broken = true;
        }

        return $cache[$path] = ! $broken;
    }
}
