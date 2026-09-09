<?php

namespace App\Services\PayPal;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalService
{
    protected ?string $clientId;

    protected ?string $clientSecret;

    protected string $mode;

    protected string $baseUrl;

    public function __construct()
    {
        $this->clientId = config('services.paypal.client_id');
        $this->clientSecret = config('services.paypal.client_secret');
        $this->mode = config('services.paypal.mode', 'sandbox');
        $this->baseUrl = $this->mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Check if PayPal is properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->clientId) && ! empty($this->clientSecret);
    }

    /**
     * Get an access token for PayPal API calls.
     */
    public function getAccessToken(): string
    {
        return Cache::remember('paypal_access_token', 3000, function () {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post("{$this->baseUrl}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to get PayPal access token: '.$response->body());
            }

            return $response->json('access_token');
        });
    }

    /**
     * Create a PayPal invoice for our invoice.
     */
    public function createInvoice(Invoice $invoice): array
    {
        $invoice->load(['client', 'lines']);

        // Get recipient email - PayPal requires a valid email
        $recipientEmail = $this->getRecipientEmail($invoice);
        if (empty($recipientEmail)) {
            Log::warning('Cannot create PayPal invoice: no recipient email', [
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'client_name' => $invoice->client->name,
            ]);
            throw new \Exception('Cannot create PayPal invoice: client has no billing email');
        }

        $payload = $this->buildInvoicePayload($invoice, $recipientEmail);

        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/v2/invoicing/invoices", $payload);

        if (! $response->successful()) {
            $errorData = $response->json();

            // Check if this is a duplicate invoice number error
            $isDuplicate = collect($errorData['details'] ?? [])
                ->contains(fn ($detail) => ($detail['issue'] ?? '') === 'DUPLICATE_INVOICE_NUMBER');

            if ($isDuplicate) {
                Log::info('PayPal invoice number exists, looking up existing invoice', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                ]);

                $existingInvoice = $this->findInvoiceByNumber($invoice->number);
                if ($existingInvoice) {
                    $existingAmount = $existingInvoice['amount']['value'] ?? null;
                    $existingStatus = $existingInvoice['status'] ?? 'unknown';

                    Log::info('Found existing PayPal invoice', [
                        'invoice_id' => $invoice->id,
                        'paypal_invoice_id' => $existingInvoice['id'],
                        'paypal_status' => $existingStatus,
                        'paypal_amount' => $existingAmount,
                        'local_total' => $invoice->total,
                    ]);

                    // If the existing PayPal invoice is in DRAFT status, update it with current data then send
                    if ($existingStatus === 'DRAFT') {
                        $invoice->update(['paypal_invoice_id' => $existingInvoice['id']]);

                        try {
                            $this->updateInvoice($invoice);
                            Log::info('Updated DRAFT PayPal invoice with current data', [
                                'invoice_id' => $invoice->id,
                                'paypal_invoice_id' => $existingInvoice['id'],
                            ]);
                        } catch (\Exception $e) {
                            Log::warning('Failed to update DRAFT PayPal invoice', [
                                'invoice_id' => $invoice->id,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        try {
                            $this->sendPayPalInvoice($existingInvoice['id'], false);
                        } catch (\Exception $e) {
                            Log::warning('Failed to send PayPal invoice after linking', [
                                'invoice_id' => $invoice->id,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        return $existingInvoice;
                    }

                    // If SENT and amounts match, safe to link directly
                    if ($existingStatus === 'SENT' && number_format((float) $existingAmount, 2) === number_format((float) $invoice->total, 2)) {
                        $invoice->update(['paypal_invoice_id' => $existingInvoice['id']]);

                        Log::info('Linked matching SENT PayPal invoice', [
                            'invoice_id' => $invoice->id,
                            'paypal_invoice_id' => $existingInvoice['id'],
                        ]);

                        return $existingInvoice;
                    }

                    // Amounts don't match or invoice is in a non-updatable state — cancel and recreate
                    Log::warning('PayPal invoice mismatch or non-updatable, cancelling and recreating', [
                        'invoice_id' => $invoice->id,
                        'paypal_invoice_id' => $existingInvoice['id'],
                        'paypal_status' => $existingStatus,
                        'paypal_amount' => $existingAmount,
                        'local_total' => $invoice->total,
                    ]);

                    // Cancel the stale PayPal invoice if possible
                    if (in_array($existingStatus, ['DRAFT', 'SENT', 'SCHEDULED'])) {
                        try {
                            $this->cancelPayPalInvoice($existingInvoice['id']);
                        } catch (\Exception $e) {
                            Log::warning('Failed to cancel stale PayPal invoice', [
                                'paypal_invoice_id' => $existingInvoice['id'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    // Recreate with a revised invoice number to avoid the duplicate
                    return $this->createWithRevisedNumber($invoice, $recipientEmail);
                }
            }

            Log::error('PayPal invoice creation failed', [
                'invoice_id' => $invoice->id,
                'response' => $errorData,
            ]);
            throw new \Exception('Failed to create PayPal invoice: '.$response->body());
        }

        $paypalInvoice = $response->json();
        $paypalInvoiceId = $this->extractPayPalInvoiceId($paypalInvoice);

        // Update our invoice with PayPal ID
        $invoice->update([
            'paypal_invoice_id' => $paypalInvoiceId,
        ]);

        // Auto-send the invoice to activate the payment link (don't email recipient)
        // This is how services like Harvest handle it - invoice is "sent" but customer
        // gets the link through the app, not a PayPal email
        try {
            Log::info('Auto-sending PayPal invoice to activate payment link', [
                'invoice_id' => $invoice->id,
                'paypal_invoice_id' => $paypalInvoiceId,
            ]);
            $this->sendPayPalInvoice($paypalInvoiceId, false);
        } catch (\Exception $e) {
            Log::warning('Failed to auto-send PayPal invoice', [
                'invoice_id' => $invoice->id,
                'paypal_invoice_id' => $paypalInvoiceId,
                'error' => $e->getMessage(),
            ]);
        }

        return $paypalInvoice;
    }

    /**
     * Search for an existing PayPal invoice by invoice number.
     */
    public function findInvoiceByNumber(string $invoiceNumber): ?array
    {
        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/v2/invoicing/search-invoices", [
                'invoice_number' => $invoiceNumber,
            ]);

        if (! $response->successful()) {
            Log::warning('PayPal invoice search failed', [
                'invoice_number' => $invoiceNumber,
                'response' => $response->json(),
            ]);

            return null;
        }

        $result = $response->json();
        $invoices = $result['items'] ?? [];

        // Return the first matching invoice
        return $invoices[0] ?? null;
    }

    /**
     * Send a PayPal invoice to the recipient.
     */
    public function sendInvoice(Invoice $invoice, bool $sendToRecipient = false): array
    {
        if (! $invoice->paypal_invoice_id) {
            $this->createInvoice($invoice);
            $invoice->refresh();
        }

        return $this->sendPayPalInvoice($invoice->paypal_invoice_id, $sendToRecipient);
    }

    /**
     * Send a PayPal invoice by its PayPal ID.
     */
    public function sendPayPalInvoice(string $paypalInvoiceId, bool $sendToRecipient = true): array
    {
        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/v2/invoicing/invoices/{$paypalInvoiceId}/send", [
                'send_to_invoicer' => false,
                'send_to_recipient' => $sendToRecipient,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to send PayPal invoice: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get the payment link for an invoice.
     */
    public function getPaymentLink(Invoice $invoice): ?string
    {
        if (! $invoice->paypal_invoice_id) {
            Log::warning('Cannot get PayPal payment link: no paypal_invoice_id', [
                'invoice_id' => $invoice->id,
            ]);

            return null;
        }

        $response = Http::withToken($this->getAccessToken())
            ->get("{$this->baseUrl}/v2/invoicing/invoices/{$invoice->paypal_invoice_id}");

        if (! $response->successful()) {
            Log::error('PayPal API call failed when getting payment link', [
                'invoice_id' => $invoice->id,
                'paypal_invoice_id' => $invoice->paypal_invoice_id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        }

        $paypalInvoice = $response->json();
        $paypalStatus = $paypalInvoice['status'] ?? 'unknown';

        $paymentLink = $paypalInvoice['detail']['metadata']['recipient_view_url'] ?? null;

        // If no payment link and invoice is DRAFT, send it to activate the link
        if (! $paymentLink && $paypalStatus === 'DRAFT') {
            Log::info('PayPal invoice is DRAFT, sending to activate payment link', [
                'invoice_id' => $invoice->id,
                'paypal_invoice_id' => $invoice->paypal_invoice_id,
            ]);

            try {
                $this->sendPayPalInvoice($invoice->paypal_invoice_id, false); // Don't email recipient

                // Fetch the invoice again to get the payment link
                $refreshResponse = Http::withToken($this->getAccessToken())
                    ->get("{$this->baseUrl}/v2/invoicing/invoices/{$invoice->paypal_invoice_id}");

                if ($refreshResponse->successful()) {
                    $refreshedInvoice = $refreshResponse->json();
                    $paymentLink = $refreshedInvoice['detail']['metadata']['recipient_view_url'] ?? null;

                    Log::info('PayPal invoice sent, payment link obtained', [
                        'invoice_id' => $invoice->id,
                        'paypal_invoice_id' => $invoice->paypal_invoice_id,
                        'has_payment_link' => ! empty($paymentLink),
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to send DRAFT PayPal invoice', [
                    'invoice_id' => $invoice->id,
                    'paypal_invoice_id' => $invoice->paypal_invoice_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $paymentLink) {
            Log::warning('PayPal invoice has no recipient_view_url', [
                'invoice_id' => $invoice->id,
                'paypal_invoice_id' => $invoice->paypal_invoice_id,
                'paypal_status' => $paypalStatus,
            ]);

            return null;
        }

        if ($this->mode === 'sandbox' && str_contains($paymentLink, 'www.paypal.com')) {
            $paymentLink = str_replace('www.paypal.com', 'www.sandbox.paypal.com', $paymentLink);

            Log::info('Fixed PayPal sandbox URL', [
                'invoice_id' => $invoice->id,
                'original_url' => $paypalInvoice['detail']['metadata']['recipient_view_url'],
                'fixed_url' => $paymentLink,
            ]);
        }

        return $paymentLink;
    }

    /**
     * Check payment status of a PayPal invoice.
     */
    public function checkPaymentStatus(Invoice $invoice): array
    {
        if (! $invoice->paypal_invoice_id) {
            return ['status' => 'unknown'];
        }

        $response = Http::withToken($this->getAccessToken())
            ->get("{$this->baseUrl}/v2/invoicing/invoices/{$invoice->paypal_invoice_id}");

        if (! $response->successful()) {
            return ['status' => 'error', 'message' => $response->body()];
        }

        $paypalInvoice = $response->json();

        return [
            'status' => $paypalInvoice['status'] ?? 'unknown',
            'amount_paid' => $paypalInvoice['amount']['value'] ?? 0,
            'payments' => $paypalInvoice['payments']['transactions'] ?? [],
        ];
    }

    /**
     * Record a payment from PayPal webhook.
     */
    public function recordPayment(Invoice $invoice, array $paymentData): Payment
    {
        return $invoice->payments()->create([
            'amount' => $paymentData['amount']['value'] ?? $invoice->amount_due,
            'method' => Payment::METHOD_PAYPAL,
            'transaction_id' => $paymentData['payment_id'] ?? null,
            'reference' => "PayPal Invoice #{$invoice->paypal_invoice_id}",
            'payment_date' => now(),
            'status' => Payment::STATUS_COMPLETED,
            'metadata' => $paymentData,
        ]);
    }

    /**
     * Verify a webhook signature from PayPal.
     */
    public function verifyWebhookSignature(array $headers, string $body): bool
    {
        $webhookId = config('services.paypal.webhook_id');

        if (! $webhookId) {
            Log::warning('PayPal webhook ID not configured');

            return false;
        }

        $payload = [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => json_decode($body, true),
        ];

        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/v1/notifications/verify-webhook-signature", $payload);

        if (! $response->successful()) {
            Log::error('PayPal webhook verification failed', [
                'response' => $response->json(),
            ]);

            return false;
        }

        return $response->json('verification_status') === 'SUCCESS';
    }

    /**
     * Cancel a PayPal invoice.
     */
    public function cancelInvoice(Invoice $invoice): bool
    {
        if (! $invoice->paypal_invoice_id) {
            return true;
        }

        return $this->cancelPayPalInvoice($invoice->paypal_invoice_id);
    }

    /**
     * Cancel a PayPal invoice by its PayPal ID.
     */
    public function cancelPayPalInvoice(string $paypalInvoiceId): bool
    {
        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/v2/invoicing/invoices/{$paypalInvoiceId}/cancel", [
                'subject' => 'Invoice cancelled',
                'note' => 'This invoice has been cancelled.',
                'send_to_invoicer' => true,
                'send_to_recipient' => false,
            ]);

        return $response->successful();
    }

    /**
     * Create a PayPal invoice with a revised number to avoid duplicate conflicts.
     * Appends "-R1", "-R2", etc. until a unique number is found.
     */
    protected function createWithRevisedNumber(Invoice $invoice, string $recipientEmail, int $attempt = 1): array
    {
        $revisedNumber = $invoice->number.'-R'.$attempt;

        Log::info('Creating PayPal invoice with revised number', [
            'invoice_id' => $invoice->id,
            'original_number' => $invoice->number,
            'revised_number' => $revisedNumber,
        ]);

        $payload = $this->buildInvoicePayload($invoice, $recipientEmail);
        $payload['detail']['invoice_number'] = $revisedNumber;

        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/v2/invoicing/invoices", $payload);

        if (! $response->successful()) {
            $errorData = $response->json();
            $isDuplicate = collect($errorData['details'] ?? [])
                ->contains(fn ($detail) => ($detail['issue'] ?? '') === 'DUPLICATE_INVOICE_NUMBER');

            if ($isDuplicate && $attempt < 5) {
                return $this->createWithRevisedNumber($invoice, $recipientEmail, $attempt + 1);
            }

            throw new \Exception('Failed to create PayPal invoice with revised number: '.$response->body());
        }

        $paypalInvoice = $response->json();
        $paypalInvoiceId = $this->extractPayPalInvoiceId($paypalInvoice);

        $invoice->update(['paypal_invoice_id' => $paypalInvoiceId]);

        // Auto-send to activate payment link
        try {
            $this->sendPayPalInvoice($paypalInvoiceId, false);
        } catch (\Exception $e) {
            Log::warning('Failed to auto-send revised PayPal invoice', [
                'invoice_id' => $invoice->id,
                'paypal_invoice_id' => $paypalInvoiceId,
                'error' => $e->getMessage(),
            ]);
        }

        return $paypalInvoice;
    }

    /**
     * Extract the PayPal invoice ID from a create response.
     * PayPal may return the ID directly or only in the href link.
     */
    protected function extractPayPalInvoiceId(array $response): string
    {
        // Some responses include the id directly
        if (! empty($response['id'])) {
            return $response['id'];
        }

        // Extract from href: https://api-m.paypal.com/v2/invoicing/invoices/INV2-XXXX-...
        $href = $response['href'] ?? '';
        if (preg_match('#/invoices/(INV2-[A-Z0-9-]+)#', $href, $matches)) {
            return $matches[1];
        }

        throw new \Exception('Could not extract PayPal invoice ID from response: '.json_encode($response));
    }

    /**
     * Update an existing PayPal invoice with current local data.
     */
    public function updateInvoice(Invoice $invoice): array
    {
        $invoice->load(['client', 'lines']);

        $recipientEmail = $this->getRecipientEmail($invoice);
        if (empty($recipientEmail)) {
            throw new \Exception('Cannot update PayPal invoice: client has no billing email');
        }

        $payload = $this->buildInvoicePayload($invoice, $recipientEmail);

        $response = Http::withToken($this->getAccessToken())
            ->put(
                "{$this->baseUrl}/v2/invoicing/invoices/{$invoice->paypal_invoice_id}?send_to_recipient=true&send_to_invoicer=true",
                $payload
            );

        if (! $response->successful()) {
            Log::error('PayPal invoice update failed', [
                'invoice_id' => $invoice->id,
                'paypal_invoice_id' => $invoice->paypal_invoice_id,
                'response' => $response->json(),
            ]);
            throw new \Exception('Failed to update PayPal invoice: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Build the PayPal invoice payload from a local invoice.
     *
     * @param  array{detail: array, invoicer: array, primary_recipients: array, items: array, configuration: array}  $return
     */
    protected function buildInvoicePayload(Invoice $invoice, string $recipientEmail): array
    {
        $payload = [
            'detail' => [
                'invoice_number' => $invoice->number,
                'invoice_date' => $invoice->issue_date->format('Y-m-d'),
                'payment_term' => [
                    'term_type' => 'DUE_ON_DATE_SPECIFIED',
                    'due_date' => $invoice->due_date->format('Y-m-d'),
                ],
                'currency_code' => $invoice->currency,
                'note' => $invoice->notes,
            ],
            'invoicer' => [
                'name' => [
                    'business_name' => config('app.company_name', 'Zao'),
                ],
                'email_address' => config('services.paypal.invoicer_email'),
            ],
            'primary_recipients' => [
                [
                    'billing_info' => [
                        'name' => [
                            'full_name' => $invoice->client->name,
                        ],
                        'email_address' => $recipientEmail,
                    ],
                ],
            ],
            'items' => $this->formatLineItems($invoice),
            'configuration' => [
                'partial_payment' => [
                    'allow_partial_payment' => true,
                ],
                'allow_tip' => false,
            ],
        ];

        if ($invoice->tax_rate > 0) {
            $payload['configuration']['tax_calculated_after_discount'] = true;
            $payload['configuration']['tax_inclusive'] = false;
        }

        return $payload;
    }

    /**
     * Get the recipient email for PayPal invoice.
     */
    protected function getRecipientEmail(Invoice $invoice): ?string
    {
        // Per-invoice override takes highest priority
        if (! empty($invoice->recipient_email)) {
            return $invoice->recipient_email;
        }

        // Try billing email first
        if (! empty($invoice->client->billing_email)) {
            return $invoice->client->billing_email;
        }

        // Try contacts - prefer primary contact, then any contact
        try {
            if (method_exists($invoice->client, 'contacts')) {
                // First try primary contact
                $primaryContact = $invoice->client->contacts()->where('is_primary', true)->first();
                if ($primaryContact && ! empty($primaryContact->email)) {
                    return $primaryContact->email;
                }

                // Then try any contact with email
                $anyContact = $invoice->client->contacts()->whereNotNull('email')->first();
                if ($anyContact && ! empty($anyContact->email)) {
                    return $anyContact->email;
                }
            }
        } catch (\Exception $e) {
            // Relationship might not exist
        }

        return null;
    }

    /**
     * Format invoice lines for PayPal.
     */
    protected function formatLineItems(Invoice $invoice): array
    {
        return $invoice->lines->map(function ($line) use ($invoice) {
            // Skip items with negative quantities (discounts/adjustments)
            if ($line->quantity <= 0) {
                return null;
            }

            $item = [
                'name' => substr($line->description, 0, 200),
                'quantity' => (string) $line->quantity,
                'unit_amount' => [
                    'currency_code' => $invoice->currency,
                    'value' => number_format(abs($line->unit_price), 2, '.', ''),
                ],
            ];

            if ($line->details) {
                $item['description'] = substr($line->details, 0, 1000);
            }

            if ($line->unit) {
                $item['unit_of_measure'] = $line->unit === 'hours' ? 'HOURS' : 'QUANTITY';
            }

            // Tax
            if ($line->taxable && $invoice->tax_rate > 0) {
                $item['tax'] = [
                    'name' => 'Tax',
                    'percent' => (string) $invoice->tax_rate,
                ];
            }

            // Discounts are handled differently
            if ($line->type === 'discount') {
                $item['discount'] = [
                    'amount' => [
                        'currency_code' => $invoice->currency,
                        'value' => number_format(abs($line->amount), 2, '.', ''),
                    ],
                ];
            }

            return $item;
        })->filter(fn ($item) => $item !== null && ($item['discount'] ?? null) === null)->values()->all();
    }
}
