<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\InvoiceReminderSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceReminderScheduleController extends Controller
{
    /**
     * Show the global invoice reminder settings page, with a list of
     * client overrides for quick navigation.
     */
    public function index(): Response
    {
        $resolved = InvoiceReminderSchedule::resolve();

        $clientOverrides = InvoiceReminderSchedule::query()
            ->where('scope', InvoiceReminderSchedule::SCOPE_CLIENT)
            ->with('client:id,name')
            ->get()
            ->map(fn (InvoiceReminderSchedule $s) => [
                'client_id' => $s->client_id,
                'client_name' => $s->client?->name,
                'enabled' => $s->enabled,
                'entry_count' => count($s->schedule ?? []),
            ]);

        return Inertia::render('Invoices/Settings', [
            'schedule' => [
                'enabled' => $resolved['enabled'],
                'entries' => $resolved['entries'],
            ],
            'clientOverrides' => $clientOverrides,
            'limits' => [
                'min_offset' => InvoiceReminderSchedule::MIN_OFFSET,
                'max_offset' => InvoiceReminderSchedule::MAX_OFFSET,
            ],
        ]);
    }

    /**
     * Update the global reminder schedule.
     */
    public function updateGlobal(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);

        $schedule = InvoiceReminderSchedule::updateOrCreate(
            ['scope' => InvoiceReminderSchedule::SCOPE_GLOBAL, 'client_id' => null],
            [
                'enabled' => $data['enabled'],
                'schedule' => InvoiceReminderSchedule::normalize($data['entries']),
            ]
        );

        $affected = $schedule->rematerialize();

        return back()->with('success', "Global reminder schedule saved. {$affected} invoices rescheduled.");
    }

    /**
     * Update a client's reminder schedule override.
     */
    public function updateForClient(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validatePayload($request);

        $schedule = InvoiceReminderSchedule::updateOrCreate(
            ['scope' => InvoiceReminderSchedule::SCOPE_CLIENT, 'client_id' => $client->id],
            [
                'enabled' => $data['enabled'],
                'schedule' => InvoiceReminderSchedule::normalize($data['entries']),
            ]
        );

        $affected = $schedule->rematerialize();

        return back()->with('success', "Reminder schedule saved for {$client->name}. {$affected} invoices rescheduled.");
    }

    /**
     * Remove a client's override (fall back to global).
     */
    public function destroyForClient(Client $client): RedirectResponse
    {
        $override = InvoiceReminderSchedule::query()
            ->where('scope', InvoiceReminderSchedule::SCOPE_CLIENT)
            ->where('client_id', $client->id)
            ->first();

        if ($override) {
            $override->delete();

            // Re-materialize affected invoices against the global schedule
            $invoices = Invoice::query()
                ->where('client_id', $client->id)
                ->whereNotIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED])
                ->where('reminders_disabled', false)
                ->with('client')
                ->get();

            foreach ($invoices as $invoice) {
                InvoiceReminder::scheduleForInvoice($invoice);
            }
        }

        return back()->with('success', "Reminder override removed for {$client->name}.");
    }

    /**
     * @return array{enabled: bool, entries: array<int, array{offset_days: int, enabled: bool}>}
     */
    protected function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'entries' => ['array'],
            'entries.*.offset_days' => [
                'required',
                'integer',
                'min:'.InvoiceReminderSchedule::MIN_OFFSET,
                'max:'.InvoiceReminderSchedule::MAX_OFFSET,
            ],
            'entries.*.enabled' => ['required', 'boolean'],
        ]);

        return [
            'enabled' => $validated['enabled'],
            'entries' => $validated['entries'] ?? [],
        ];
    }
}
