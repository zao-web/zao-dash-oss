<?php

namespace App\Http\Controllers;

use App\Models\Contractor;
use App\Models\User;
use App\Services\Wise\WiseApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ContractorController extends Controller
{
    public function index()
    {
        $contractors = Contractor::with(['invoices' => fn ($q) => $q->latest()->limit(3)])
            ->withCount(['invoices', 'transfers'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($contractor) => [
                'id' => $contractor->id,
                'uuid' => $contractor->uuid,
                'name' => $contractor->name,
                'email' => $contractor->email,
                'company_name' => $contractor->company_name,
                'country_code' => $contractor->country_code,
                'is_us_person' => $contractor->is_us_person,
                'payment_type' => $contractor->payment_type,
                'recurring_amount' => $contractor->recurring_amount,
                'recurring_currency' => $contractor->recurring_currency,
                'recurring_schedule' => $contractor->recurring_schedule,
                'status' => $contractor->status,
                'onboarding_status' => $contractor->onboarding_status,
                'has_w9_on_file' => $contractor->has_w9_on_file,
                'invoices_count' => $contractor->invoices_count,
                'transfers_count' => $contractor->transfers_count,
                'total_paid_this_year' => $contractor->total_paid_this_year,
                'can_receive_payments' => $contractor->canReceivePayments(),
                'needs_w9' => $contractor->requiresW9(),
                'created_at' => $contractor->created_at->format('M d, Y'),
            ]);

        $stats = [
            'total' => Contractor::count(),
            'active' => Contractor::active()->count(),
            'pending_onboarding' => Contractor::where('onboarding_status', '!=', Contractor::ONBOARDING_COMPLETE)->count(),
            'needs_w9' => Contractor::needsW9()->count(),
            'recurring' => Contractor::recurring()->count(),
            'total_paid_this_year' => Contractor::active()
                ->get()
                ->sum('total_paid_this_year'),
        ];

        return Inertia::render('Contractors/Index', [
            'contractors' => $contractors,
            'stats' => $stats,
        ]);
    }

    public function show(Contractor $contractor)
    {
        $contractor->load([
            'invoices' => fn ($q) => $q->latest(),
            'transfers' => fn ($q) => $q->latest(),
            'user',
        ]);

        return Inertia::render('Contractors/Show', [
            'contractor' => [
                'id' => $contractor->id,
                'uuid' => $contractor->uuid,
                'name' => $contractor->name,
                'email' => $contractor->email,
                'phone' => $contractor->phone,
                'company_name' => $contractor->company_name,
                'country_code' => $contractor->country_code,
                'is_us_person' => $contractor->is_us_person,
                'tax_id_last_four' => $contractor->tax_id_last_four,
                'tax_id_type' => $contractor->tax_id_type,
                'has_w9_on_file' => $contractor->has_w9_on_file,
                'w9_received_at' => $contractor->w9_received_at?->format('M d, Y'),
                'payment_type' => $contractor->payment_type,
                'recurring_amount' => $contractor->recurring_amount,
                'recurring_currency' => $contractor->recurring_currency,
                'recurring_schedule' => $contractor->recurring_schedule,
                'wise_recipient_id' => $contractor->wise_recipient_id,
                'status' => $contractor->status,
                'onboarding_status' => $contractor->onboarding_status,
                'can_receive_payments' => $contractor->canReceivePayments(),
                'needs_w9' => $contractor->requiresW9(),
                'total_paid_this_year' => $contractor->total_paid_this_year,
                'created_at' => $contractor->created_at->format('M d, Y'),
            ],
            'invoices' => $contractor->invoices->map(fn ($inv) => [
                'id' => $inv->id,
                'uuid' => $inv->uuid,
                'invoice_number' => $inv->invoice_number,
                'invoice_date' => $inv->invoice_date->format('M d, Y'),
                'due_date' => $inv->due_date?->format('M d, Y'),
                'amount' => $inv->amount,
                'currency' => $inv->currency,
                'status' => $inv->status,
                'is_overdue' => $inv->isOverdue(),
            ]),
            'transfers' => $contractor->transfers->map(fn ($tr) => [
                'id' => $tr->id,
                'source_amount' => $tr->source_amount,
                'source_currency' => $tr->source_currency,
                'target_amount' => $tr->target_amount,
                'target_currency' => $tr->target_currency,
                'fee' => $tr->fee,
                'status' => $tr->status,
                'payment_type' => $tr->payment_type,
                'reference' => $tr->reference,
                'created_at' => $tr->created_at->format('M d, Y'),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:contractors,email',
            'phone' => 'nullable|string|max:50',
            'company_name' => 'nullable|string|max:255',
            'country_code' => 'required|string|size:2',
            'is_us_person' => 'boolean',
            'payment_type' => 'required|in:recurring,invoice',
            'recurring_amount' => 'nullable|numeric|min:0',
            'recurring_currency' => 'nullable|string|size:3',
            'recurring_schedule' => 'nullable|in:weekly,biweekly,monthly',
        ]);

        $validated['uuid'] = (string) Str::uuid();
        $validated['status'] = Contractor::STATUS_PENDING;
        $validated['onboarding_status'] = Contractor::ONBOARDING_INVITED;

        $contractor = Contractor::create($validated);

        return redirect()->route('contractors.show', $contractor)
            ->with('success', 'Contractor created. Send them an invite to complete onboarding.');
    }

    public function update(Request $request, Contractor $contractor)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:contractors,email,'.$contractor->id,
            'phone' => 'nullable|string|max:50',
            'company_name' => 'nullable|string|max:255',
            'country_code' => 'required|string|size:2',
            'is_us_person' => 'boolean',
            'payment_type' => 'required|in:recurring,invoice',
            'recurring_amount' => 'nullable|numeric|min:0',
            'recurring_currency' => 'nullable|string|size:3',
            'recurring_schedule' => 'nullable|in:weekly,biweekly,monthly',
        ]);

        $contractor->update($validated);

        return redirect()->back()->with('success', 'Contractor updated successfully.');
    }

    public function destroy(Contractor $contractor)
    {
        $contractor->delete();

        return redirect()->route('contractors.index')
            ->with('success', 'Contractor archived.');
    }

    public function invite(Request $request, Contractor $contractor)
    {
        // Create a user account for the contractor if they don't have one
        if (! $contractor->user_id) {
            $user = User::create([
                'name' => $contractor->name,
                'email' => $contractor->email,
                'password' => Hash::make(Str::random(32)), // They'll reset via email
                'role' => 'contractor',
            ]);

            $contractor->update(['user_id' => $user->id]);
        }

        // Generate password reset token and send invitation email
        $token = Password::broker()->createToken($contractor->user);
        $resetUrl = url(route('password.reset', [
            'token' => $token,
            'email' => $contractor->email,
        ], false));

        Mail::to($contractor->email)->send(new \App\Mail\ContractorInvitation($contractor, $resetUrl));

        return redirect()->back()
            ->with('success', 'Invitation sent to '.$contractor->email);
    }

    public function activate(Contractor $contractor)
    {
        if (! $contractor->isOnboardingComplete()) {
            return redirect()->back()
                ->with('error', 'Cannot activate contractor until onboarding is complete.');
        }

        $contractor->update(['status' => Contractor::STATUS_ACTIVE]);

        return redirect()->back()
            ->with('success', 'Contractor activated and can now receive payments.');
    }

    public function suspend(Contractor $contractor)
    {
        $contractor->update(['status' => Contractor::STATUS_SUSPENDED]);

        return redirect()->back()
            ->with('success', 'Contractor suspended. No new payments will be processed.');
    }

    public function uploadW9(Request $request, Contractor $contractor)
    {
        $request->validate([
            'w9_file' => 'required|file|mimes:pdf|max:10240',
            'tax_id' => 'required|string|max:20',
            'tax_id_type' => 'required|in:ssn,ein',
        ]);

        // Store the W-9 file securely
        $path = $request->file('w9_file')->store('contractor-w9s', 'private');

        $contractor->update([
            'w9_file_path' => $path,
            'tax_id' => $request->tax_id, // Uses encrypted setter
            'tax_id_type' => $request->tax_id_type,
            'has_w9_on_file' => true,
            'w9_received_at' => now(),
        ]);

        // Update onboarding status if applicable
        if ($contractor->onboarding_status === Contractor::ONBOARDING_INFO_SUBMITTED) {
            $contractor->update(['onboarding_status' => Contractor::ONBOARDING_BANK_VERIFIED]);
        }

        return redirect()->back()
            ->with('success', 'W-9 uploaded and tax ID saved securely.');
    }

    public function setupWiseRecipient(Request $request, Contractor $contractor, WiseApiService $wiseService)
    {
        $request->validate([
            'bank_details' => 'required|array',
        ]);

        // Get the user's Wise connection
        $wiseConnection = $request->user()->wiseConnection;

        if (! $wiseConnection) {
            return redirect()->back()
                ->with('error', 'Please connect your Wise account first.');
        }

        try {
            // Determine recipient type based on country
            $recipientType = match ($contractor->country_code) {
                'US' => 'aba',
                'GB' => 'sort_code',
                default => $request->input('bank_details.type', 'swift_code'),
            };

            $recipient = $wiseService->createRecipient($wiseConnection, [
                'account_holder_name' => $contractor->company_name ?: $contractor->name,
                'currency' => $contractor->recurring_currency ?: 'USD',
                'type' => $recipientType,
                'details' => $request->bank_details,
                'legal_type' => $contractor->company_name ? 'BUSINESS' : 'PRIVATE',
            ]);

            $contractor->update([
                'wise_recipient_id' => $recipient['id'],
                'wise_recipient_details' => $recipient,
                'onboarding_status' => Contractor::ONBOARDING_COMPLETE,
            ]);

            return redirect()->back()
                ->with('success', 'Bank details verified and Wise recipient created.');

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to create Wise recipient: '.$e->getMessage());
        }
    }
}
