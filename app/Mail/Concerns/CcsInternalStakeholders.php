<?php

namespace App\Mail\Concerns;

use Illuminate\Mail\Mailables\Address;

trait CcsInternalStakeholders
{
    /**
     * Resolve the CC address list configured for client-facing emails.
     * Returns Address objects suitable for the Mailable Envelope `cc:`
     * parameter. Empty array if no addresses configured.
     *
     * @return array<int, Address>
     */
    protected function internalCcs(): array
    {
        $raw = (string) config('app.client_email_cc', '');

        return collect(explode(',', $raw))
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL))
            ->map(fn ($v) => new Address($v))
            ->values()
            ->all();
    }
}
