<?php

namespace App\Http\Controllers;

use App\Mail\ClientContactEmail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'health_score' => 'nullable|numeric|min:0|max:10',
            'status' => 'nullable|in:active,inactive,prospect',
            'website' => 'nullable|url|max:255',
            'slack_channel' => 'nullable|string|max:255',
            'slack_channel_id' => 'nullable|exists:slack_channels,id',
            'billing_email' => 'nullable|email|max:255',
            'billing_cc_emails' => 'nullable|string|max:1000',
            'contacts' => 'nullable|array',
            'contacts.*.name' => 'nullable|string|max:255',
            'contacts.*.email' => 'nullable|email|max:255',
            'contacts.*.role' => 'nullable|string|max:255',
            'contacts.*.is_primary' => 'nullable|boolean',
        ]);

        $contacts = $validated['contacts'] ?? [];
        unset($validated['contacts']);

        $validated['slug'] = Str::slug($validated['name']);

        $client = Client::create($validated);

        foreach ($contacts as $contact) {
            if (! empty($contact['name']) || ! empty($contact['email'])) {
                $client->contacts()->create($contact);
            }
        }

        if ($request->wantsJson()) {
            return response()->json($client, 201);
        }

        return redirect()->back()->with('success', 'Client created successfully.');
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'health_score' => 'nullable|numeric|min:0|max:10',
            'status' => 'nullable|in:active,inactive,prospect',
            'website' => 'nullable|url|max:255',
            'slack_channel' => 'nullable|string|max:255',
            'slack_channel_id' => 'nullable|exists:slack_channels,id',
            // Billing settings
            'billing_email' => 'nullable|email|max:255',
            'billing_cc_emails' => 'nullable|string|max:1000',
            'payment_terms' => 'nullable|string|in:Due on Receipt,Net 15,Net 30,Net 45,Net 60',
            'default_hourly_rate' => 'nullable|numeric|min:0',
            'default_tax_rate' => 'nullable|numeric|min:0|max:100',
            'recurring_invoice_enabled' => 'nullable|boolean',
            'recurring_invoice_amount' => 'nullable|numeric|min:0',
            'recurring_invoice_day' => 'nullable|integer|min:1|max:28',
            'recurring_invoice_auto_send' => 'nullable|boolean',
            'recurring_invoice_description' => 'nullable|string|max:1000',
            'recurring_invoice_project_id' => 'nullable|exists:projects,id',
        ]);

        $validated['default_tax_rate'] = $validated['default_tax_rate'] ?? 0;

        if ($validated['name'] !== $client->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $client->update($validated);

        return redirect()->back()->with('success', 'Client updated successfully.');
    }

    public function archive(Client $client)
    {
        $client->archive();

        return redirect()->back()->with('success', "Client '{$client->name}' archived.");
    }

    public function unarchive(Client $client)
    {
        $client->unarchive();

        return redirect()->back()->with('success', "Client '{$client->name}' restored.");
    }

    public function destroy(Client $client)
    {
        // Check for active projects before deleting
        $activeProjects = $client->projects()->whereNotIn('status', ['archived', 'completed'])->count();
        if ($activeProjects > 0) {
            return redirect()->back()->with('error', "Cannot delete client with {$activeProjects} active project(s). Archive or reassign them first.");
        }

        try {
            $client->delete();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to delete client', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Failed to delete client: '.$e->getMessage());
        }

        return redirect()->route('clients.index')->with('success', 'Client deleted successfully.');
    }

    public function addContact(Request $request, Client $client)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'is_primary' => 'nullable|boolean',
        ]);

        $client->contacts()->create($validated);

        return redirect()->back()->with('success', 'Contact added successfully.');
    }

    public function updateContact(Request $request, Client $client, ClientContact $contact)
    {
        if ($contact->client_id !== $client->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'is_primary' => 'nullable|boolean',
        ]);

        $contact->update($validated);

        return redirect()->back()->with('success', 'Contact updated successfully.');
    }

    public function removeContact(Client $client, ClientContact $contact)
    {
        if ($contact->client_id !== $client->id) {
            abort(404);
        }

        $contact->delete();

        return redirect()->back()->with('success', 'Contact removed successfully.');
    }

    public function sendContactEmail(Request $request, Client $client, ClientContact $contact)
    {
        if ($contact->client_id !== $client->id) {
            abort(404);
        }

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
        ]);

        Mail::to($contact->email)
            ->send(new ClientContactEmail(
                client: $client,
                contact: $contact,
                sender: $request->user(),
                emailSubject: $validated['subject'],
                emailBody: $validated['body'],
            ));

        return redirect()->back()->with('success', 'Email sent to '.$contact->name);
    }

    public function storeNote(Request $request, Client $client)
    {
        $validated = $request->validate([
            'content' => 'required|string|max:10000',
        ]);

        $client->notes()->create([
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);

        return redirect()->back()->with('success', 'Note added successfully.');
    }

    public function destroyNote(Client $client, ClientNote $note)
    {
        if ($note->client_id !== $client->id) {
            abort(404);
        }

        $note->delete();

        return redirect()->back()->with('success', 'Note deleted successfully.');
    }
}
