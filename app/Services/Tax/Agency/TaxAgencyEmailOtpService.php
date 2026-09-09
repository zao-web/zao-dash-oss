<?php

namespace App\Services\Tax\Agency;

use App\Models\Email;
use App\Models\TaxAgencyConnection;
use App\Models\User;
use App\Services\Google\GmailService;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TaxAgencyEmailOtpService
{
    public function __construct(
        protected GmailService $gmailService,
        protected GoogleOAuthService $googleOAuthService,
    ) {}

    /**
     * @return array{
     *     code: string,
     *     from_address: string,
     *     subject: string|null,
     *     received_at: string,
     *     source: string
     * }|null
     */
    public function resolveLatestCode(User $user, string $agencyCode, int $lookbackMinutes = 20): ?array
    {
        if (! $this->googleOAuthService->hasValidCredentials($user)) {
            return null;
        }

        $notBefore = now()->subMinutes(max(1, $lookbackMinutes));
        $seenMessageIds = [];

        foreach ($this->gmailQueries($agencyCode) as $query) {
            $messages = $this->gmailService->listMessages($user, [
                'q' => $query,
                'maxResults' => 10,
            ]);

            foreach ($messages['messages'] ?? [] as $message) {
                $messageId = (string) ($message['id'] ?? '');
                if ($messageId === '' || in_array($messageId, $seenMessageIds, true)) {
                    continue;
                }

                $seenMessageIds[] = $messageId;
                $email = $this->gmailService->syncAndStoreEmail($user, $messageId);
                $candidate = $this->candidateFromEmail($email, $agencyCode, $notBefore, 'gmail_api');

                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        $credentialEmail = $user->googleCredential?->email;
        if (! is_string($credentialEmail) || $credentialEmail === '') {
            return null;
        }

        /** @var ?Email $storedEmail */
        $storedEmail = Email::query()
            ->where('received_at', '>=', $notBefore)
            ->whereJsonContains('to_addresses', $credentialEmail)
            ->orderByDesc('received_at')
            ->get()
            ->first(fn (Email $email): bool => $this->candidateFromEmail($email, $agencyCode, $notBefore, 'stored_email') !== null);

        return $storedEmail === null
            ? null
            : $this->candidateFromEmail($storedEmail, $agencyCode, $notBefore, 'stored_email');
    }

    /**
     * @return array<int, string>
     */
    protected function gmailQueries(string $agencyCode): array
    {
        return match ($agencyCode) {
            TaxAgencyConnection::AGENCY_IRS => [
                'from:id.me newer_than:1d',
                'from:irs.gov newer_than:1d',
                '"verification code" newer_than:1d',
            ],
            TaxAgencyConnection::AGENCY_OREGON_DOR => [
                'from:oregon.gov newer_than:1d',
                'from:revenueonline newer_than:1d',
                '"verification code" newer_than:1d',
            ],
            default => [
                '"verification code" newer_than:1d',
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    protected function senderMarkers(string $agencyCode): array
    {
        return match ($agencyCode) {
            TaxAgencyConnection::AGENCY_IRS => ['id.me', 'irs.gov'],
            TaxAgencyConnection::AGENCY_OREGON_DOR => ['oregon.gov', 'revenueonline'],
            default => [],
        };
    }

    /**
     * @return array{
     *     code: string,
     *     from_address: string,
     *     subject: string|null,
     *     received_at: string,
     *     source: string
     * }|null
     */
    protected function candidateFromEmail(?Email $email, string $agencyCode, Carbon $notBefore, string $source): ?array
    {
        if (! $email || ! $email->received_at || $email->received_at->lt($notBefore)) {
            return null;
        }

        $fromAddress = Str::lower((string) ($email->from_address ?? ''));
        $content = trim(implode("\n", array_filter([
            $email->subject,
            $email->body_text,
            is_string($email->body_html) ? strip_tags($email->body_html) : null,
        ])));

        if ($content === '') {
            return null;
        }

        $senderMatches = $this->senderMatchesAgency($fromAddress, $agencyCode);
        $keywordMatches = Str::contains(Str::lower($content), [
            'verification code',
            'security code',
            'one-time code',
            'one time code',
            'passcode',
            'authentication code',
        ]);

        if (! $senderMatches && ! $keywordMatches) {
            return null;
        }

        $code = $this->extractCode($content);
        if ($code === null) {
            return null;
        }

        return [
            'code' => $code,
            'from_address' => $email->from_address,
            'subject' => $email->subject,
            'received_at' => $email->received_at->toIso8601String(),
            'source' => $source,
        ];
    }

    protected function senderMatchesAgency(string $fromAddress, string $agencyCode): bool
    {
        foreach ($this->senderMarkers($agencyCode) as $marker) {
            if (Str::contains($fromAddress, Str::lower($marker))) {
                return true;
            }
        }

        return false;
    }

    protected function extractCode(string $content): ?string
    {
        $patterns = [
            '/(?:verification|security|one[- ]time|authentication|passcode|code)[^\d]{0,24}(\d{6,8})/i',
            '/\b(\d{6})\b/',
            '/\b(\d{7,8})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
