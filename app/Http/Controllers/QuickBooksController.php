<?php

namespace App\Http\Controllers;

use App\Jobs\SyncQuickBooksJob;
use App\Models\FinancialSnapshot;
use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksApiService;
use App\Services\QuickBooks\QuickBooksOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuickBooksController extends Controller
{
    public function __construct(
        protected QuickBooksOAuthService $oauth,
        protected QuickBooksApiService $api
    ) {}

    public function redirect(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('qbo_oauth_state', $state);

        return redirect($this->oauth->getAuthorizationUrl($state));
    }

    public function callback(Request $request)
    {
        if ($request->session()->get('qbo_oauth_state') !== $request->state) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Invalid OAuth state');
        }

        if (! $request->code || ! $request->realmId) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Missing authorization code or realm ID');
        }

        try {
            $tokenData = $this->oauth->exchangeCodeForTokens($request->code, $request->realmId);
            $connection = $this->oauth->storeConnection(auth()->user(), $tokenData);

            // Dispatch initial sync
            SyncQuickBooksJob::dispatch($connection->id)->onQueue('sync');

            return redirect()->route('settings.integrations')
                ->with('success', 'Connected to QuickBooks Online. Syncing your data now...');
        } catch (\Exception $e) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Failed to connect: '.$e->getMessage());
        }
    }

    public function disconnect(QuickBooksConnection $connection)
    {
        $name = $connection->company_name;
        $connection->delete();

        return back()->with('success', "Disconnected QuickBooks: {$name}");
    }

    public function sync(QuickBooksConnection $connection)
    {
        try {
            $results = $this->api->syncAll($connection);
            $total = array_sum($results);

            return back()->with('success', "Synced {$total} records from QuickBooks");
        } catch (\Exception $e) {
            return back()->with('error', 'Sync failed: '.$e->getMessage());
        }
    }

    public function snapshot(QuickBooksConnection $connection, Request $request)
    {
        $validated = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
        ]);

        try {
            $startDate = $validated['period_start'];
            $endDate = $validated['period_end'];

            // Get P&L report
            $pnl = $this->api->getProfitAndLoss($connection, $startDate, $endDate);

            // Extract key metrics from report
            $revenue = $this->extractReportValue($pnl, 'Income');
            $expenses = $this->extractReportValue($pnl, 'Expenses');
            $netIncome = $this->extractReportValue($pnl, 'Net Income');

            // Get balance sheet for cash position
            $balance = $this->api->getBalanceSheet($connection, $endDate);
            $cashOnHand = $this->extractReportValue($balance, 'Bank Accounts');

            // Calculate metrics
            $invoices = $connection->invoices()->whereBetween('txn_date', [$startDate, $endDate])->get();
            $arTotal = $invoices->sum('balance');
            $overdueAr = $invoices->where('status', 'Overdue')->sum('balance');

            $apTotal = $connection->transactions()
                ->where('txn_type', 'Purchase')
                ->whereBetween('txn_date', [$startDate, $endDate])
                ->sum('amount');

            $snapshot = FinancialSnapshot::create([
                'qbo_connection_id' => $connection->id,
                'period_start' => $startDate,
                'period_end' => $endDate,
                'revenue' => $revenue,
                'expenses' => $expenses,
                'net_income' => $netIncome,
                'cash_on_hand' => $cashOnHand,
                'accounts_receivable' => $arTotal,
                'accounts_payable' => $apTotal,
                'overdue_ar' => $overdueAr,
                'runway_months' => $expenses > 0 ? round($cashOnHand / ($expenses / 12), 1) : null,
            ]);

            return back()->with('success', 'Financial snapshot created');
        } catch (\Exception $e) {
            return back()->with('error', 'Snapshot failed: '.$e->getMessage());
        }
    }

    protected function extractReportValue(array $report, string $section): float
    {
        // Navigate QBO report structure to find section total
        $rows = $report['Rows']['Row'] ?? [];

        foreach ($rows as $row) {
            $header = $row['Header']['ColData'][0]['value'] ?? '';
            if (stripos($header, $section) !== false) {
                $summary = $row['Summary']['ColData'][1]['value'] ?? 0;

                return (float) $summary;
            }
        }

        return 0;
    }

    public function reports(QuickBooksConnection $connection)
    {
        return response()->json([
            'snapshots' => $connection->snapshots()->latest()->take(12)->get(),
            'invoices' => $connection->invoices()->where('status', '!=', 'Paid')->get(),
            'recent_transactions' => $connection->transactions()->latest('txn_date')->take(20)->get(),
        ]);
    }

    public function cashFlow(QuickBooksConnection $connection, Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        try {
            $report = $this->api->getCashFlow($connection, $validated['start_date'], $validated['end_date']);

            return response()->json($report);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
