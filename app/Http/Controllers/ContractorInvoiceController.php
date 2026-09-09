<?php

namespace App\Http\Controllers;

use App\Models\ContractorInvoice;
use App\Models\WiseConnection;
use App\Models\WiseTransfer;
use App\Services\Wise\WiseApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ContractorInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status');

        $query = ContractorInvoice::with('contractor')
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $invoices = $query->paginate(20)->through(fn ($inv) => [
            'id' => $inv->id,
            'uuid' => $inv->uuid,
            'invoice_number' => $inv->invoice_number,
            'contractor' => [
                'id' => $inv->contractor->id,
                'name' => $inv->contractor->name,
                'company_name' => $inv->contractor->company_name,
            ],
            'invoice_date' => $inv->invoice_date->format('M d, Y'),
            'due_date' => $inv->due_date?->format('M d, Y'),
            'amount' => $inv->amount,
            'currency' => $inv->currency,
            'status' => $inv->status,
            'is_overdue' => $inv->isOverdue(),
            'can_be_approved' => $inv->canBeApproved(),
            'can_be_paid' => $inv->canBePaid(),
            'created_at' => $inv->created_at->format('M d, Y'),
        ]);

        $stats = [
            'total' => ContractorInvoice::count(),
            'pending' => ContractorInvoice::pending()->count(),
            'approved_unpaid' => ContractorInvoice::approved()->count(),
            'overdue' => ContractorInvoice::overdue()->count(),
            'total_pending_amount' => ContractorInvoice::pending()->sum('amount'),
        ];

        return Inertia::render('ContractorInvoices/Index', [
            'invoices' => $invoices,
            'stats' => $stats,
            'currentStatus' => $status,
        ]);
    }

    public function pending()
    {
        $invoices = ContractorInvoice::with('contractor')
            ->whereIn('status', [
                ContractorInvoice::STATUS_SUBMITTED,
                ContractorInvoice::STATUS_APPROVED,
            ])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($inv) => [
                'id' => $inv->id,
                'uuid' => $inv->uuid,
                'invoice_number' => $inv->invoice_number,
                'contractor' => [
                    'id' => $inv->contractor->id,
                    'name' => $inv->contractor->name,
                    'company_name' => $inv->contractor->company_name,
                    'is_us_person' => $inv->contractor->is_us_person,
                    'has_w9_on_file' => $inv->contractor->has_w9_on_file,
                ],
                'invoice_date' => $inv->invoice_date->format('M d, Y'),
                'due_date' => $inv->due_date?->format('M d, Y'),
                'amount' => $inv->amount,
                'currency' => $inv->currency,
                'description' => $inv->description,
                'line_items' => $inv->line_items,
                'attachments' => $inv->attachments,
                'status' => $inv->status,
                'is_overdue' => $inv->isOverdue(),
                'created_at' => $inv->created_at->format('M d, Y'),
            ]);

        $stats = [
            'submitted' => ContractorInvoice::where('status', ContractorInvoice::STATUS_SUBMITTED)->count(),
            'approved' => ContractorInvoice::where('status', ContractorInvoice::STATUS_APPROVED)->count(),
            'total_pending_amount' => ContractorInvoice::whereIn('status', [
                ContractorInvoice::STATUS_SUBMITTED,
                ContractorInvoice::STATUS_APPROVED,
            ])->sum('amount'),
        ];

        return Inertia::render('ContractorInvoices/Pending', [
            'invoices' => $invoices,
            'stats' => $stats,
        ]);
    }

    public function show(ContractorInvoice $invoice)
    {
        $invoice->load(['contractor', 'approver', 'transfer']);

        return Inertia::render('ContractorInvoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'uuid' => $invoice->uuid,
                'invoice_number' => $invoice->invoice_number,
                'contractor' => [
                    'id' => $invoice->contractor->id,
                    'name' => $invoice->contractor->name,
                    'company_name' => $invoice->contractor->company_name,
                    'email' => $invoice->contractor->email,
                    'can_receive_payments' => $invoice->contractor->canReceivePayments(),
                ],
                'invoice_date' => $invoice->invoice_date->format('M d, Y'),
                'due_date' => $invoice->due_date?->format('M d, Y'),
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'description' => $invoice->description,
                'line_items' => $invoice->line_items,
                'attachments' => $invoice->attachments,
                'status' => $invoice->status,
                'approved_by' => $invoice->approver ? [
                    'id' => $invoice->approver->id,
                    'name' => $invoice->approver->name,
                ] : null,
                'approved_at' => $invoice->approved_at?->format('M d, Y H:i'),
                'rejection_reason' => $invoice->rejection_reason,
                'paid_at' => $invoice->paid_at?->format('M d, Y H:i'),
                'transfer' => $invoice->transfer ? [
                    'id' => $invoice->transfer->id,
                    'status' => $invoice->transfer->status,
                    'source_amount' => $invoice->transfer->source_amount,
                    'fee' => $invoice->transfer->fee,
                ] : null,
                'is_overdue' => $invoice->isOverdue(),
                'can_be_approved' => $invoice->canBeApproved(),
                'can_be_paid' => $invoice->canBePaid(),
                'created_at' => $invoice->created_at->format('M d, Y'),
            ],
        ]);
    }

    public function approve(ContractorInvoice $invoice)
    {
        if (! $invoice->canBeApproved()) {
            return redirect()->back()
                ->with('error', 'This invoice cannot be approved in its current state.');
        }

        $invoice->approve(Auth::id());

        return redirect()->back()
            ->with('success', 'Invoice approved. Ready for payment.');
    }

    public function reject(Request $request, ContractorInvoice $invoice)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        if ($invoice->status !== ContractorInvoice::STATUS_SUBMITTED) {
            return redirect()->back()
                ->with('error', 'Only submitted invoices can be rejected.');
        }

        $invoice->reject($validated['reason']);

        return redirect()->back()
            ->with('success', 'Invoice rejected. The contractor will be notified.');
    }

    public function pay(Request $request, ContractorInvoice $invoice, WiseApiService $wiseService)
    {
        if (! $invoice->canBePaid()) {
            return redirect()->back()
                ->with('error', 'This invoice cannot be paid. Check contractor payment setup.');
        }

        $wiseConnection = WiseConnection::where('user_id', Auth::id())->active()->first();

        if (! $wiseConnection) {
            return redirect()->back()
                ->with('error', 'No active Wise connection. Connect Wise in Settings.');
        }

        $contractor = $invoice->contractor;

        if (! $contractor->wise_recipient_id) {
            return redirect()->back()
                ->with('error', 'Contractor does not have bank details configured in Wise.');
        }

        try {
            // Create quote
            $quote = $wiseService->createQuote($wiseConnection, [
                'source_currency' => 'USD',
                'target_currency' => $invoice->currency,
                'source_amount' => $invoice->amount,
            ]);

            // Create pending transfer record
            $transfer = WiseTransfer::create([
                'wise_connection_id' => $wiseConnection->id,
                'contractor_id' => $contractor->id,
                'contractor_invoice_id' => $invoice->id,
                'wise_quote_id' => $quote['id'],
                'source_amount' => $quote['sourceAmount'],
                'source_currency' => $quote['sourceCurrency'],
                'target_amount' => $quote['targetAmount'],
                'target_currency' => $quote['targetCurrency'],
                'exchange_rate' => $quote['rate'] ?? 1,
                'fee' => $quote['fee']['total'] ?? 0,
                'recipient_id' => $contractor->wise_recipient_id,
                'recipient_name' => $contractor->display_name,
                'reference' => $invoice->invoice_number ?? 'INV-'.$invoice->id,
                'payment_type' => WiseTransfer::TYPE_INVOICE,
                'status' => WiseTransfer::STATUS_PENDING,
                'initiated_by' => Auth::id(),
            ]);

            // NOTE: The actual transfer + funding happens after approval
            // This creates an approval request for the owner

            return redirect()->back()
                ->with('success', 'Payment initiated. Awaiting owner approval to execute transfer.');

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to initiate payment: '.$e->getMessage());
        }
    }

    public function bulkApprove(Request $request)
    {
        $validated = $request->validate([
            'invoice_ids' => 'required|array',
            'invoice_ids.*' => 'exists:contractor_invoices,id',
        ]);

        $approved = 0;
        $skipped = 0;

        foreach ($validated['invoice_ids'] as $id) {
            $invoice = ContractorInvoice::find($id);
            if ($invoice && $invoice->canBeApproved()) {
                $invoice->approve(Auth::id());
                $approved++;
            } else {
                $skipped++;
            }
        }

        $message = "Approved {$approved} invoices.";
        if ($skipped > 0) {
            $message .= " {$skipped} were skipped (already approved or not eligible).";
        }

        return redirect()->back()->with('success', $message);
    }
}
