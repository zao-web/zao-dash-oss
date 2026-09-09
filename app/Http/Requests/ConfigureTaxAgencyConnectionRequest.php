<?php

namespace App\Http\Requests;

use App\Models\TaxAgencyConnection;
use App\Models\VaultSecretValue;
use Illuminate\Foundation\Http\FormRequest;

class ConfigureTaxAgencyConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'vault_environment' => 'nullable|string|in:'.implode(',', [
                VaultSecretValue::ENVIRONMENT_PRODUCTION,
                VaultSecretValue::ENVIRONMENT_STAGING,
                VaultSecretValue::ENVIRONMENT_DEVELOPMENT,
            ]),
            'sync_enabled' => 'nullable|boolean',
            'capabilities' => 'nullable|array|min:1',
            'capabilities.*' => 'string|in:balances,payments,notices,transcripts',
        ];
    }

    public function messages(): array
    {
        return [
            'vault_environment.in' => 'Tax agency credentials must target production, staging, or development vault storage.',
            'capabilities.min' => 'Choose at least one tax portal sync surface.',
            'capabilities.*.in' => 'Tax agency sync only supports balances, payments, notices, and transcripts.',
        ];
    }

    public function agencyCode(): string
    {
        return (string) $this->route('agencyCode');
    }

    public function supportedAgencyCodes(): array
    {
        return [
            TaxAgencyConnection::AGENCY_IRS,
            TaxAgencyConnection::AGENCY_OREGON_DOR,
        ];
    }
}
