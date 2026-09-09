<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestTaxAgencyMfaChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_type' => 'nullable|string|in:sms_code,call_code,totp',
            'delivery_hint' => 'nullable|string|max:120',
            'code_length' => 'nullable|integer|min:4|max:8',
            'expires_in_minutes' => 'nullable|integer|min:1|max:30',
            'slack_user_id' => 'nullable|string|max:64',
            'slack_channel_id' => 'nullable|string|max:64',
            'worker_context' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'challenge_type.in' => 'Tax portal MFA requests only support SMS, call, or TOTP code challenges.',
            'code_length.min' => 'The requested MFA code length must be at least 4 digits.',
            'code_length.max' => 'The requested MFA code length may not exceed 8 digits.',
        ];
    }
}
