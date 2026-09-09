<?php

namespace App\Http\Controllers;

use App\Mail\RetainerReportMail;
use App\Models\RetainerPeriod;
use App\Services\Reports\RetainerReportPdfGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class RetainerReportController extends Controller
{
    public function __construct(protected RetainerReportPdfGenerator $generator) {}

    /**
     * Public web view of a retainer report, accessible via a signed URL.
     */
    public function publicShow(Request $request, RetainerPeriod $period): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired link.');
        }

        $data = $this->generator->buildViewData($period);

        // Carry the signed-URL session forward so the embedded PDF/CSV
        // download buttons work without needing the user to re-auth.
        $expiresAt = now()->addDays(90);

        return response()->view('retainer-reports.show', array_merge($data, [
            'isPdf' => false,
            'isPublicView' => true,
            'publicPdfUrl' => URL::signedRoute('retainer-reports.public.download', ['period' => $period->id], $expiresAt),
            'publicCsvUrl' => URL::signedRoute('retainer-reports.public.csv', ['period' => $period->id], $expiresAt),
        ]));
    }

    /**
     * Public PDF download of a retainer report, accessible via a signed URL.
     */
    public function publicDownload(Request $request, RetainerPeriod $period)
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired link.');
        }

        $storagePath = $this->generator->getStoragePath($period);

        if (! Storage::exists($storagePath)) {
            $this->generator->generateAndStore($period);
        }

        return Storage::download(
            $storagePath,
            "retainer-report-{$period->client?->name}-{$period->period_start}.pdf",
        );
    }

    /**
     * Admin (authenticated) web view — no signature required.
     */
    public function adminShow(Request $request, RetainerPeriod $period): Response
    {
        $data = $this->generator->buildViewData($period);

        // Prev/next periods for the same client, ordered by start date.
        $allPeriods = RetainerPeriod::query()
            ->where('client_id', $period->client_id)
            ->orderBy('period_start')
            ->get(['id', 'period_start', 'period_end']);
        $currentIndex = $allPeriods->search(fn ($p) => $p->id === $period->id);
        $prevPeriod = $currentIndex !== false && $currentIndex > 0 ? $allPeriods[$currentIndex - 1] : null;
        $nextPeriod = $currentIndex !== false && $currentIndex < $allPeriods->count() - 1
            ? $allPeriods[$currentIndex + 1] : null;

        // Refresh-job status for the toolbar banner. Terminal states (done /
        // error) display once and are cleared here so the banner doesn't
        // persist across later visits.
        $statusKey = \App\Jobs\RefreshRetainerReportJob::statusKey($period->id);
        $refreshStatus = \Illuminate\Support\Facades\Cache::get($statusKey);
        if (in_array($refreshStatus['state'] ?? null, ['done', 'error'], true)) {
            \Illuminate\Support\Facades\Cache::forget($statusKey);
        }

        return response()->view('retainer-reports.show', array_merge($data, [
            'isPdf' => false,
            'isAdminView' => true,
            'prevPeriod' => $prevPeriod,
            'nextPeriod' => $nextPeriod,
            'allPeriods' => $allPeriods,
            'shareUrl' => self::signedUrlFor($period),
            'refreshStatus' => $refreshStatus,
        ]));
    }

    /**
     * Admin (authenticated) PDF download — no signature required.
     */
    public function adminDownload(Request $request, RetainerPeriod $period)
    {
        $storagePath = $this->generator->getStoragePath($period);

        // Always regenerate from admin to avoid serving a stale snapshot.
        $this->generator->generateAndStore($period);

        return Storage::download(
            $storagePath,
            "retainer-report-{$period->client?->name}-{$period->period_start}.pdf",
        );
    }

    /**
     * Queue a full report refresh (commit re-pull, snapshot, narrative).
     * The work runs in RefreshRetainerReportJob — doing it inline blew past
     * the gateway timeout (GitHub pulls + a 120s-timeout LLM call, retried).
     * The admin view polls the job's cache status and reloads when done.
     */
    public function refresh(Request $request, RetainerPeriod $period): RedirectResponse
    {
        $statusKey = \App\Jobs\RefreshRetainerReportJob::statusKey($period->id);
        $status = \Illuminate\Support\Facades\Cache::get($statusKey);

        // A refresh is already in flight — don't stack another one.
        if (($status['state'] ?? null) !== 'running') {
            \Illuminate\Support\Facades\Cache::put($statusKey, [
                'state' => 'running',
                'started_at' => now()->toIso8601String(),
            ], now()->addMinutes(15));

            \App\Jobs\RefreshRetainerReportJob::dispatch($period);
        }

        return back();
    }

    /**
     * Correct a single time entry on the report (hours/notes/billable). The
     * entry is promoted to source='manual' so a later narrative regenerate
     * won't overwrite the correction.
     */
    public function adjustEntry(Request $request, RetainerPeriod $period, \App\Models\TimeEntry $entry): RedirectResponse
    {
        abort_unless($entry->retainer_period_id === $period->id, 404);

        $validated = $request->validate([
            'hours' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'is_billable' => 'nullable|boolean',
        ]);

        $changes = array_filter($validated, fn ($v) => $v !== null);
        if (empty($changes)) {
            return back()->with('error', 'Nothing to change.');
        }

        app(\App\Services\Reports\RetainerTimeEntryAdjuster::class)->adjust($entry, $changes);
        $this->bustReportCaches($period);

        return back()->with('success', 'Time entry updated.');
    }

    /**
     * Remove a time entry from the report — e.g. work the client handled.
     */
    public function deleteEntry(Request $request, RetainerPeriod $period, \App\Models\TimeEntry $entry): RedirectResponse
    {
        abort_unless($entry->retainer_period_id === $period->id, 404);

        app(\App\Services\Reports\RetainerTimeEntryAdjuster::class)->remove($entry);
        $this->bustReportCaches($period);

        return back()->with('success', 'Time entry removed.');
    }

    /**
     * Drop the cached PDF for a period so the next download/render reflects
     * edited entries. The narrative cache is left intact — edits change entries,
     * not the LLM narrative.
     */
    protected function bustReportCaches(RetainerPeriod $period): void
    {
        $pdfPath = $this->generator->getStoragePath($period);
        if (\Illuminate\Support\Facades\Storage::exists($pdfPath)) {
            \Illuminate\Support\Facades\Storage::delete($pdfPath);
        }
    }

    /**
     * Stream a timesheet CSV for the period. Format optimized for CFOs who
     * default to spreadsheets: one row per tracked time entry (with its real
     * date) plus one row per narrative topic (with the period date range),
     * a totals row, then a brief methodology footer.
     */
    public function timesheetCsv(Request $request, RetainerPeriod $period): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $this->streamTimesheetCsv($period);
    }

    /**
     * Public CSV download — signed URL, no auth.
     */
    public function publicCsv(Request $request, RetainerPeriod $period): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired link.');
        }

        return $this->streamTimesheetCsv($period);
    }

    protected function streamTimesheetCsv(RetainerPeriod $period): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $period->loadMissing('client');
        $data = $this->generator->buildViewData($period);

        $start = $data['start'];
        $end = $data['end'];
        $client = $data['client'];
        $narrative = $data['narrative'] ?? null;
        $timeEntries = $data['timeEntries'];

        $filename = sprintf(
            'timesheet-%s-%s.csv',
            \Illuminate\Support\Str::slug($client?->name ?? 'client'),
            $start->format('Y-m'),
        );

        return response()->streamDownload(function () use ($start, $end, $client, $narrative, $timeEntries) {
            $out = fopen('php://output', 'w');

            // Header row. No "Source" or "Status" column — finance reviewers
            // shouldn't be querying our estimation methodology, and the topic
            // status (completed/in_progress) wasn't telling the CFO anything
            // a timesheet should be telling them.
            fputcsv($out, ['Date', 'Description', 'Hours']);

            $runningTotal = 0.0;

            // All time entries — manual + AI-estimated — emitted with their
            // real per-day spent_date. AI topics are already persisted as
            // split per-day TimeEntry rows (discussion start + delivery end,
            // sometimes a midpoint), so a single loop produces the same data
            // the Blade table shows.
            foreach ($timeEntries as $entry) {
                $hours = (float) $entry->hours;
                $runningTotal += $hours;
                fputcsv($out, [
                    \Carbon\Carbon::parse($entry->spent_date)->format('Y-m-d'),
                    trim(($entry->notes ?? $entry->description ?? '—').($entry->project ? ' ['.$entry->project->name.']' : '')),
                    number_format($hours, 2, '.', ''),
                ]);
            }

            $periodRange = $start->format('Y-m-d').' – '.$end->format('Y-m-d');

            // Totals.
            fputcsv($out, []);
            fputcsv($out, ['', 'TOTAL', number_format($runningTotal, 2, '.', '')]);

            // Footnote rows so the CFO knows what they're looking at.
            fputcsv($out, []);
            fputcsv($out, ['# Client', $client?->name ?? '']);
            fputcsv($out, ['# Period', $periodRange]);
            fputcsv($out, ['# Generated', now()->format('Y-m-d H:i')]);
            if ($narrative && ! empty($narrative['value_summary'])) {
                fputcsv($out, ['# Summary', $narrative['value_summary']]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Email the retainer report (with PDF + signed link) to the client.
     */
    public function send(Request $request, RetainerPeriod $period): RedirectResponse
    {
        $period->loadMissing('client.contacts');
        $client = $period->client;

        if (! $client) {
            return back()->with('error', 'Retainer is not linked to a client.');
        }

        $recipient = $client->billing_email
            ?? $client->contacts()->whereNotNull('email')->first()?->email;

        if (! $recipient) {
            return back()->with('error', "No billing email or contact email on file for {$client->name}.");
        }

        Mail::to($recipient)->send(new RetainerReportMail($period));

        return back()->with('success', "Retainer report sent to {$recipient}.");
    }

    /**
     * Generate a signed URL for sharing the retainer report (90-day expiry).
     */
    public static function signedUrlFor(RetainerPeriod $period): string
    {
        return URL::signedRoute(
            'retainer-reports.public.show',
            ['period' => $period->id],
            now()->addDays(90),
        );
    }
}
