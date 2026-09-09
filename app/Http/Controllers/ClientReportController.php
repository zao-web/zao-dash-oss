<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReportPdfJob;
use App\Mail\ClientReportMail;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ClientReportSettings;
use App\Services\Pdf\TailwindPdf;
use App\Services\Reports\ClientReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ClientReportController extends Controller
{
    public function __construct(
        protected ClientReportService $reportService
    ) {}

    /**
     * Get report settings and reports list for a client.
     */
    public function index(Client $client)
    {
        $settings = ClientReportSettings::firstOrCreate(
            ['client_id' => $client->id],
            [
                'is_enabled' => false,
                'frequency' => 'monthly',
                'send_day' => 1,
                'recipients' => [],
                'include_time_breakdown' => true,
                'include_github_activity' => true,
                'include_tasks_completed' => true,
                'include_financials' => false,
                'include_upcoming' => true,
            ]
        );

        $reports = ClientReport::where('client_id', $client->id)
            ->orderBy('period_end', 'desc')
            ->limit(12)
            ->get()
            ->map(fn ($report) => [
                'id' => $report->id,
                'period_label' => $report->period_label,
                'period_start' => $report->period_start->format('M j, Y'),
                'period_end' => $report->period_end->format('M j, Y'),
                'report_type' => $report->report_type,
                'status' => $report->status,
                'total_hours' => $report->total_hours,
                'tasks_completed' => $report->tasks_completed,
                'prs_merged' => $report->prs_merged,
                'sent_at' => $report->sent_at?->format('M j, Y g:i A'),
                'opens_count' => $report->opens_count,
                'can_regenerate' => $report->canRegenerate(),
                'has_pdf' => (bool) $report->pdf_path,
                'created_at' => $report->created_at->format('M j, Y'),
            ]);

        // Get contacts for recipient selection
        $contacts = $client->contacts()
            ->orderBy('is_primary', 'desc')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_primary']);

        return response()->json([
            'settings' => [
                'id' => $settings->id,
                'is_enabled' => $settings->is_enabled,
                'frequency' => $settings->frequency,
                'send_day' => $settings->send_day,
                'recipients' => $settings->recipients ?? [],
                'include_time_breakdown' => $settings->include_time_breakdown,
                'include_github_activity' => $settings->include_github_activity,
                'include_tasks_completed' => $settings->include_tasks_completed,
                'include_financials' => $settings->include_financials,
                'include_upcoming' => $settings->include_upcoming,
                'custom_branding' => $settings->custom_branding ?? [],
            ],
            'reports' => $reports,
            'contacts' => $contacts,
        ]);
    }

    /**
     * Update report settings for a client.
     */
    public function updateSettings(Request $request, Client $client)
    {
        $validated = $request->validate([
            'is_enabled' => 'boolean',
            'frequency' => 'in:weekly,monthly,quarterly',
            'send_day' => 'integer|min:0|max:31',
            'recipients' => 'array',
            'recipients.*' => 'email',
            'include_time_breakdown' => 'boolean',
            'include_github_activity' => 'boolean',
            'include_tasks_completed' => 'boolean',
            'include_financials' => 'boolean',
            'include_upcoming' => 'boolean',
            'custom_branding' => 'array|nullable',
            'custom_branding.primary_color' => 'string|nullable',
            'custom_branding.accent_color' => 'string|nullable',
            'custom_branding.logo_url' => 'string|nullable',
        ]);

        $settings = ClientReportSettings::updateOrCreate(
            ['client_id' => $client->id],
            $validated
        );

        return redirect()->back()->with('success', 'Report settings saved.');
    }

    /**
     * Generate a new report for preview.
     */
    public function generate(Request $request, Client $client)
    {
        $validated = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'report_type' => 'in:weekly,monthly,quarterly',
        ]);

        $periodStart = Carbon::parse($validated['period_start']);
        $periodEnd = Carbon::parse($validated['period_end']);
        $reportType = $validated['report_type'] ?? 'monthly';

        // Check if report already exists for this period
        $existing = ClientReport::where('client_id', $client->id)
            ->forPeriod($periodStart, $periodEnd)
            ->first();

        if ($existing) {
            return redirect()->back()->with('error', 'Report already exists for this period.');
        }

        $report = $this->reportService->generateReport(
            $client,
            $periodStart,
            $periodEnd,
            $reportType,
            $request->user()->id
        );

        return redirect()->back()->with('success', 'Report generated successfully.');
    }

    /**
     * Get a single report for preview.
     */
    public function show(Client $client, ClientReport $report)
    {
        if ($report->client_id !== $client->id) {
            abort(404);
        }

        return response()->json([
            'report' => [
                'id' => $report->id,
                'client_name' => $client->name,
                'period_label' => $report->period_label,
                'period_start' => $report->period_start->format('M j, Y'),
                'period_end' => $report->period_end->format('M j, Y'),
                'report_type' => $report->report_type,
                'status' => $report->status,
                'executive_summary' => $report->executive_summary,
                'highlights' => $report->highlights,
                'metrics' => $report->metrics,
                'total_hours' => $report->total_hours,
                'hours_by_category' => $report->hours_by_category,
                'hours_by_project' => $report->hours_by_project,
                'tasks_completed' => $report->tasks_completed,
                'prs_merged' => $report->prs_merged,
                'issues_closed' => $report->issues_closed,
                'meetings_held' => $report->meetings_held,
                'data_snapshot' => $report->data_snapshot,
                'sent_to' => $report->sent_to,
                'sent_at' => $report->sent_at?->format('M j, Y g:i A'),
                'opens_count' => $report->opens_count,
                'can_regenerate' => $report->canRegenerate(),
                'has_pdf' => (bool) $report->pdf_path,
                'created_at' => $report->created_at->format('M j, Y g:i A'),
            ],
        ]);
    }

    /**
     * Regenerate an existing report.
     */
    public function regenerate(Request $request, Client $client, ClientReport $report)
    {
        if ($report->client_id !== $client->id) {
            abort(404);
        }

        if (! $report->canRegenerate()) {
            return redirect()->back()->with('error', 'This report cannot be regenerated.');
        }

        $newReport = $this->reportService->generateReport(
            $client,
            $report->period_start,
            $report->period_end,
            $report->report_type,
            $request->user()->id
        );

        // Delete the old report
        if ($report->pdf_path) {
            Storage::disk($report->pdf_disk ?? 'local')->delete($report->pdf_path);
        }
        $report->delete();

        return redirect()->back()->with('success', 'Report regenerated successfully.');
    }

    /**
     * Send a report to recipients.
     */
    public function send(Request $request, Client $client, ClientReport $report)
    {
        if ($report->client_id !== $client->id) {
            abort(404);
        }

        $validated = $request->validate([
            'recipients' => 'required|array|min:1',
            'recipients.*' => 'email',
        ]);

        $recipients = $validated['recipients'];

        // Generate PDF if not already done
        if (! $report->pdf_path) {
            GenerateReportPdfJob::dispatch($report, true, $recipients)->onQueue('reports');

            return redirect()->back()->with('info', 'Report is being generated and will be sent shortly.');
        }

        // Send to each recipient
        foreach ($recipients as $email) {
            Mail::to($email)->queue(new ClientReportMail($report));
        }

        $report->markSent($recipients);

        return redirect()->back()->with('success', 'Report sent to '.count($recipients).' recipient(s).');
    }

    /**
     * Download report PDF.
     */
    public function download(Client $client, ClientReport $report)
    {
        if ($report->client_id !== $client->id) {
            abort(404);
        }

        if (! $report->pdf_path) {
            // Generate on-demand if needed
            GenerateReportPdfJob::dispatch($report)->onQueue('reports');

            return redirect()->back()->with('info', 'PDF is being generated. Try again in a moment.');
        }

        $disk = $report->pdf_disk ?? 'local';
        $filename = "{$client->name} - {$report->period_label} Report.pdf";

        return Storage::disk($disk)->download($report->pdf_path, $filename);
    }

    /**
     * Delete a report.
     */
    public function destroy(Client $client, ClientReport $report)
    {
        if ($report->client_id !== $client->id) {
            abort(404);
        }

        // Delete PDF if exists
        if ($report->pdf_path) {
            Storage::disk($report->pdf_disk ?? 'local')->delete($report->pdf_path);
        }

        $report->delete();

        return redirect()->back()->with('success', 'Report deleted.');
    }

    /**
     * Render HTML preview of a report (for iframe preview).
     */
    public function preview(Client $client, ClientReport $report)
    {
        if ($report->client_id !== $client->id) {
            abort(404);
        }

        $settings = ClientReportSettings::where('client_id', $client->id)->first()
            ?? new ClientReportSettings(['client_id' => $client->id]);

        return view('pdf.client-report', [
            'report' => $report,
            'settings' => $settings,
        ]);
    }

    /**
     * Stream PDF for inline viewing in browser.
     */
    public function viewPdf(Client $client, ClientReport $report)
    {
        if ($report->client_id !== $client->id) {
            abort(404);
        }

        $settings = ClientReportSettings::where('client_id', $client->id)->first()
            ?? new ClientReportSettings(['client_id' => $client->id]);

        $filename = "{$client->name} - {$report->period_label} Report.pdf";

        return TailwindPdf::view('pdf.client-report', [
            'report' => $report,
            'settings' => $settings,
        ])
            ->letter()
            ->noMargins()
            ->primaryColor($settings->primary_color ?? '#2563eb')
            ->stream($filename);
    }

    /**
     * Quick generate for last month (convenience endpoint).
     */
    public function generateLastMonth(Request $request, Client $client)
    {
        $lastMonth = now()->subMonth();
        $periodStart = $lastMonth->copy()->startOfMonth();
        $periodEnd = $lastMonth->copy()->endOfMonth();

        // Check if report already exists
        $existing = ClientReport::where('client_id', $client->id)
            ->forPeriod($periodStart, $periodEnd)
            ->first();

        if ($existing) {
            return redirect()->back()->with('info', 'Report already exists for last month.');
        }

        $report = $this->reportService->generateReport(
            $client,
            $periodStart,
            $periodEnd,
            'monthly',
            $request->user()->id
        );

        return redirect()->back()->with('success', 'Report generated for '.$lastMonth->format('F Y'));
    }
}
