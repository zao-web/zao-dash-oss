<?php

namespace App\Http\Controllers;

use App\Models\Contractor;
use App\Models\ContractorInvoice;
use App\Services\Contractor\ContractorOnboardingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Handles contractor features for team members.
 *
 * Team members who are contractors can:
 * - Set up their payment info (bank details, W9)
 * - Submit invoices for payment
 * - View their payment history
 */
class MyContractorController extends Controller
{
    public function __construct(
        protected ContractorOnboardingService $onboardingService
    ) {}

    /**
     * Get the contractor record for the current user, or create one if needed.
     */
    protected function getOrCreateContractor(): Contractor
    {
        $user = auth()->user();

        $contractor = Contractor::where('user_id', $user->id)->first();

        if (! $contractor) {
            // Auto-create contractor record for team member
            $contractor = Contractor::create([
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => Contractor::STATUS_PENDING,
                'onboarding_status' => Contractor::ONBOARDING_INVITED,
                'payment_type' => 'invoice', // Default to invoice-based
            ]);
        }

        return $contractor;
    }

    /**
     * Payment setup page - bank details, W9, etc.
     */
    public function paymentSetup()
    {
        $contractor = $this->getOrCreateContractor();

        return Inertia::render('My/PaymentSetup', [
            'contractor' => [
                'id' => $contractor->id,
                'name' => $contractor->name,
                'email' => $contractor->email,
                'phone' => $contractor->phone,
                'company_name' => $contractor->company_name,
                'country_code' => $contractor->country_code,
                'is_us_person' => $contractor->is_us_person,
                'status' => $contractor->status,
                'onboarding_status' => $contractor->onboarding_status,
                'has_w9_on_file' => $contractor->has_w9_on_file,
                'w9_received_at' => $contractor->w9_received_at?->format('M j, Y'),
                'has_bank_details' => ! empty($contractor->wise_recipient_id),
                'can_receive_payments' => $contractor->canReceivePayments(),
            ],
            'steps' => $this->getOnboardingSteps($contractor),
        ]);
    }

    /**
     * Update personal/business info.
     */
    public function updatePersonalInfo(Request $request)
    {
        $contractor = $this->getOrCreateContractor();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:50',
            'company_name' => 'nullable|string|max:255',
            'country_code' => 'required|string|size:2',
            'is_us_person' => 'required|boolean',
        ]);

        $this->onboardingService->submitPersonalInfo($contractor, $validated);

        return back()->with('success', 'Personal information updated.');
    }

    /**
     * Submit W-9 form.
     */
    public function submitW9(Request $request)
    {
        $contractor = $this->getOrCreateContractor();

        $request->validate([
            'w9_file' => 'required|file|mimes:pdf|max:10240',
        ]);

        $this->onboardingService->submitW9($contractor, $request->file('w9_file'));

        return back()->with('success', 'W-9 uploaded successfully.');
    }

    /**
     * Submit bank details for Wise recipient setup.
     */
    public function submitBankDetails(Request $request)
    {
        $contractor = $this->getOrCreateContractor();

        $validated = $request->validate([
            'currency' => 'required|string|size:3',
            'account_holder_name' => 'required|string|max:255',
            'account_type' => 'required|in:checking,savings',
            'routing_number' => 'required_if:currency,USD|string|size:9',
            'account_number' => 'required|string|max:34',
            'iban' => 'required_without:routing_number|string|max:34',
            'swift_bic' => 'nullable|string|max:11',
        ]);

        $this->onboardingService->submitBankDetails($contractor, $validated);

        return back()->with('success', 'Bank details saved and verified.');
    }

    /**
     * List my invoices.
     */
    public function invoices()
    {
        $contractor = $this->getOrCreateContractor();

        $invoices = ContractorInvoice::where('contractor_id', $contractor->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('My/Invoices', [
            'invoices' => $invoices->through(fn ($inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'amount' => $inv->amount,
                'currency' => $inv->currency,
                'description' => $inv->description,
                'status' => $inv->status,
                'invoice_date' => $inv->invoice_date?->format('M j, Y'),
                'due_date' => $inv->due_date?->format('M j, Y'),
                'approved_at' => $inv->approved_at?->format('M j, Y'),
                'paid_at' => $inv->paid_at?->format('M j, Y'),
            ]),
            'canSubmitInvoices' => $contractor->canReceivePayments(),
            'setupRequired' => ! $contractor->canReceivePayments(),
        ]);
    }

    /**
     * Show create invoice form.
     */
    public function createInvoice()
    {
        $contractor = $this->getOrCreateContractor();

        if (! $contractor->canReceivePayments()) {
            return redirect()->route('my.payment-setup')
                ->with('error', 'Please complete payment setup before submitting invoices.');
        }

        return Inertia::render('My/InvoiceCreate', [
            'contractor' => [
                'id' => $contractor->id,
                'name' => $contractor->name,
                'currency' => $contractor->recurring_currency ?? 'USD',
            ],
            'nextInvoiceNumber' => $this->generateInvoiceNumber($contractor),
        ]);
    }

    /**
     * Store a new invoice.
     */
    public function storeInvoice(Request $request)
    {
        $contractor = $this->getOrCreateContractor();

        if (! $contractor->canReceivePayments()) {
            return redirect()->route('my.payment-setup')
                ->with('error', 'Please complete payment setup before submitting invoices.');
        }

        $validated = $request->validate([
            'invoice_number' => 'required|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'amount' => 'required|numeric|min:1',
            'currency' => 'required|string|size:3',
            'description' => 'required|string|max:1000',
            'line_items' => 'nullable|array',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ]);

        // Handle file uploads
        $attachmentPaths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store("contractor-invoices/{$contractor->id}", 'private');
                $attachmentPaths[] = [
                    'path' => $path,
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                ];
            }
        }

        $invoice = ContractorInvoice::create([
            'contractor_id' => $contractor->id,
            'invoice_number' => $validated['invoice_number'],
            'invoice_date' => $validated['invoice_date'],
            'due_date' => $validated['due_date'] ?? now()->addDays(30),
            'amount' => $validated['amount'],
            'currency' => strtoupper($validated['currency']),
            'description' => $validated['description'],
            'line_items' => $validated['line_items'] ?? [],
            'attachments' => $attachmentPaths,
            'status' => ContractorInvoice::STATUS_DRAFT,
        ]);

        return redirect()->route('my.invoices')
            ->with('success', "Invoice #{$invoice->invoice_number} created as draft.");
    }

    /**
     * Submit invoice for approval.
     */
    public function submitInvoice(ContractorInvoice $invoice)
    {
        $contractor = $this->getOrCreateContractor();

        if ($invoice->contractor_id !== $contractor->id) {
            abort(403);
        }

        if ($invoice->status !== ContractorInvoice::STATUS_DRAFT) {
            return back()->with('error', 'Only draft invoices can be submitted.');
        }

        $invoice->submit();

        return back()->with('success', "Invoice #{$invoice->invoice_number} submitted for approval.");
    }

    /**
     * View payment history.
     */
    public function payments()
    {
        $contractor = $this->getOrCreateContractor();

        $payments = $contractor->transfers()
            ->with('invoice')
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('My/Payments', [
            'payments' => $payments->through(fn ($transfer) => [
                'id' => $transfer->id,
                'amount' => $transfer->target_amount,
                'currency' => $transfer->target_currency,
                'source_amount' => $transfer->source_amount,
                'source_currency' => $transfer->source_currency,
                'exchange_rate' => $transfer->exchange_rate,
                'fee' => $transfer->fee,
                'reference' => $transfer->reference,
                'payment_type' => $transfer->payment_type,
                'invoice_number' => $transfer->invoice?->invoice_number,
                'completed_at' => $transfer->updated_at->format('M j, Y'),
            ]),
            'summary' => [
                'total_received' => $contractor->transfers()->where('status', 'completed')->sum('target_amount'),
                'pending_invoices' => $contractor->invoices()->whereIn('status', ['submitted', 'approved'])->sum('amount'),
                'this_year' => $contractor->transfers()
                    ->where('status', 'completed')
                    ->whereYear('created_at', now()->year)
                    ->sum('target_amount'),
            ],
        ]);
    }

    /**
     * Get onboarding steps with completion status.
     */
    protected function getOnboardingSteps(Contractor $contractor): array
    {
        return [
            [
                'id' => 'personal_info',
                'title' => 'Personal Info',
                'description' => 'Your name, email, and country',
                'complete' => $contractor->onboarding_status !== Contractor::ONBOARDING_INVITED,
                'current' => $contractor->onboarding_status === Contractor::ONBOARDING_INVITED,
            ],
            [
                'id' => 'w9',
                'title' => 'W-9 Form',
                'description' => 'Required for US tax reporting',
                'complete' => $contractor->has_w9_on_file,
                'current' => $contractor->onboarding_status === Contractor::ONBOARDING_INFO_SUBMITTED && ! $contractor->has_w9_on_file,
                'skip' => ! $contractor->is_us_person,
            ],
            [
                'id' => 'bank_details',
                'title' => 'Bank Details',
                'description' => 'Where to send your payments',
                'complete' => ! empty($contractor->wise_recipient_id),
                'current' => ($contractor->has_w9_on_file || ! $contractor->is_us_person) && empty($contractor->wise_recipient_id),
            ],
            [
                'id' => 'complete',
                'title' => 'Ready',
                'description' => 'You can now submit invoices',
                'complete' => $contractor->canReceivePayments(),
                'current' => false,
            ],
        ];
    }

    /**
     * Generate next invoice number for contractor.
     */
    protected function generateInvoiceNumber(Contractor $contractor): string
    {
        $prefix = strtoupper(substr($contractor->name, 0, 3));
        $year = now()->format('y');
        $count = ContractorInvoice::where('contractor_id', $contractor->id)
            ->whereYear('created_at', now()->year)
            ->count() + 1;

        return sprintf('%s-%s-%03d', $prefix, $year, $count);
    }
}
