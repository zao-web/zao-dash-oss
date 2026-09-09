<?php

namespace App\Http\Controllers;

use App\Services\Leads\LeadDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GravityFormsWebhookController extends Controller
{
    public function __construct(
        private LeadDetectionService $leadService
    ) {}

    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('Gravity Forms webhook received', [
            'form_id' => $payload['form_id'] ?? null,
            'entry_id' => $payload['entry_id'] ?? null,
        ]);

        if (! $this->verifyWebhook($request)) {
            Log::warning('GF webhook: Invalid signature');

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $leadData = $this->extractLeadData($payload);

        if (! $leadData['contact_email'] && ! $leadData['company_name']) {
            Log::info('GF webhook: No lead data found, skipping');

            return response()->json(['status' => 'skipped', 'reason' => 'no_lead_data']);
        }

        $formId = $payload['form_id'] ?? 'unknown';
        $entryId = $payload['entry_id'] ?? 'unknown';

        $lead = $this->leadService->createLead(
            data: array_merge($leadData, [
                'original_content' => $this->formatOriginalContent($payload),
            ]),
            source: 'gravity_forms',
            sourceId: "gf:{$formId}:{$entryId}"
        );

        return response()->json([
            'status' => 'success',
            'lead_id' => $lead->id,
            'is_duplicate' => $lead->wasRecentlyCreated === false,
        ]);
    }

    protected function verifyWebhook(Request $request): bool
    {
        $secret = config('services.gravity_forms.webhook_secret');

        // No secret configured - allow all requests
        if (! $secret) {
            return true;
        }

        $signature = $request->header('X-GF-Signature');
        if (! $signature) {
            return false;
        }

        // Gravity Forms sends a static signature value, not an HMAC
        return hash_equals($secret, $signature);
    }

    protected function extractLeadData(array $payload): array
    {
        $data = [
            'contact_name' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'company_name' => null,
            'website' => null,
            'description' => null,
            'estimated_value' => null,
        ];

        // Track name parts for GF compound fields (e.g., 1.3 = first, 1.6 = last)
        $nameParts = ['first' => null, 'last' => null];

        $fieldMap = $this->getFieldMap($payload['form_id'] ?? null);

        foreach ($payload as $key => $value) {
            if (! is_string($value) || empty(trim($value))) {
                continue;
            }

            $value = trim($value);
            $keyLower = strtolower($key);

            // Check explicit field map first
            if (isset($fieldMap[$key])) {
                $mappedField = $fieldMap[$key];
                if ($mappedField === 'first_name') {
                    $nameParts['first'] = $value;
                } elseif ($mappedField === 'last_name') {
                    $nameParts['last'] = $value;
                } elseif ($mappedField === 'estimated_value') {
                    // Parse budget strings like "Site Builds / Standard eCommerce (20-50k)"
                    $data['estimated_value'] = $this->parseBudget($value);
                } else {
                    $data[$mappedField] = $value;
                }

                continue;
            }

            // GF compound name fields: X.3 = first, X.6 = last (where X is the field ID)
            if (preg_match('/^\d+\.3$/', $key)) {
                $nameParts['first'] = $nameParts['first'] ?? $value;

                continue;
            }
            if (preg_match('/^\d+\.6$/', $key)) {
                $nameParts['last'] = $nameParts['last'] ?? $value;

                continue;
            }

            // Check source_url for website
            if ($key === 'source_url' && filter_var($value, FILTER_VALIDATE_URL)) {
                $data['website'] = $data['website'] ?? $value;

                continue;
            }

            if ($this->isEmailField($keyLower, $value)) {
                $data['contact_email'] = $data['contact_email'] ?? $value;
            } elseif ($this->isNameField($keyLower)) {
                $data['contact_name'] = $data['contact_name'] ?? $value;
            } elseif ($this->isPhoneField($keyLower)) {
                $data['contact_phone'] = $data['contact_phone'] ?? $value;
            } elseif ($this->isCompanyField($keyLower)) {
                $data['company_name'] = $data['company_name'] ?? $value;
            } elseif ($this->isWebsiteField($keyLower, $value)) {
                $data['website'] = $data['website'] ?? $value;
            } elseif ($this->isMessageField($keyLower) && strlen($value) > 20) {
                $data['description'] = ($data['description'] ?? '').$value."\n";
            } elseif ($this->isBudgetField($keyLower)) {
                $data['estimated_value'] = $this->parseBudget($value);
            }
        }

        // Combine name parts if we found them
        if ($nameParts['first'] || $nameParts['last']) {
            $fullName = trim(($nameParts['first'] ?? '').' '.($nameParts['last'] ?? ''));
            $data['contact_name'] = $data['contact_name'] ?? $fullName;
        }

        // Fallback: use email username as contact name if still null
        if (! $data['contact_name'] && $data['contact_email']) {
            $data['contact_name'] = explode('@', $data['contact_email'])[0];
        }

        if (! $data['company_name'] && $data['contact_email']) {
            $data['company_name'] = $this->extractCompanyFromEmail($data['contact_email']);
        }

        return $data;
    }

    protected function getFieldMap(?string $formId): array
    {
        $maps = config('services.gravity_forms.field_maps', []);

        return $maps[$formId] ?? [];
    }

    protected function isEmailField(string $key, string $value): bool
    {
        return str_contains($key, 'email') || filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    protected function isNameField(string $key): bool
    {
        return preg_match('/(^name$|_name$|first.*name|last.*name|full.*name|your.*name)/i', $key);
    }

    protected function isPhoneField(string $key): bool
    {
        return preg_match('/(phone|tel|mobile|cell)/i', $key);
    }

    protected function isCompanyField(string $key): bool
    {
        return preg_match('/(company|business|organization|org)/i', $key);
    }

    protected function isWebsiteField(string $key, string $value): bool
    {
        return str_contains($key, 'website') ||
               str_contains($key, 'url') ||
               str_contains($key, 'site') ||
               preg_match('/^https?:\/\//', $value);
    }

    protected function isMessageField(string $key): bool
    {
        return preg_match('/(message|description|details|project|about|tell.*us|how.*help|comment)/i', $key);
    }

    protected function isBudgetField(string $key): bool
    {
        return preg_match('/(budget|amount|price|cost|investment)/i', $key);
    }

    protected function parseBudget(string $value): ?float
    {
        // Handle range formats like "20-50k", "(20-50k)", "$20k-$50k"
        // Extract the high end of any range for the estimate
        if (preg_match('/(\d+)\s*[-–]\s*(\d+)\s*k/i', $value, $matches)) {
            // Range with 'k' suffix - use high end * 1000
            return (float) $matches[2] * 1000;
        }

        if (preg_match('/(\d+)\s*k/i', $value, $matches)) {
            // Single value with 'k' suffix
            return (float) $matches[1] * 1000;
        }

        if (preg_match('/(\d+)\s*[-–]\s*(\d+)/i', $value, $matches)) {
            // Range without suffix - use high end
            $highEnd = (float) $matches[2];

            // If high end is small (< 1000), might be in thousands
            return $highEnd;
        }

        // Fallback: extract any number
        $cleaned = preg_replace('/[^0-9.]/', '', $value);
        $amount = (float) $cleaned;

        return $amount > 0 ? $amount : null;
    }

    protected function extractCompanyFromEmail(string $email): string
    {
        $domain = substr(strrchr($email, '@'), 1);

        $freeProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com'];

        if (in_array($domain, $freeProviders)) {
            return 'Unknown Company';
        }

        $name = explode('.', $domain)[0];

        return ucfirst($name);
    }

    protected function formatOriginalContent(array $payload): string
    {
        $lines = ['Form ID: '.($payload['form_id'] ?? 'unknown')];
        $lines[] = 'Entry ID: '.($payload['entry_id'] ?? 'unknown');
        $lines[] = '---';

        foreach ($payload as $key => $value) {
            if (in_array($key, ['form_id', 'entry_id', 'ip', 'user_agent'])) {
                continue;
            }
            if (is_string($value) && ! empty(trim($value))) {
                $lines[] = "{$key}: {$value}";
            }
        }

        return implode("\n", $lines);
    }
}
