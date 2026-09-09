<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncTaxAgencyPortalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => 'required|integer|min:2024|max:2030',
            'agencies' => 'nullable|array',
            'agencies.*' => 'in:irs,oregon_dor',
        ];
    }

    public function messages(): array
    {
        return [
            'year.required' => 'Choose the tax year to sync against the agency portals.',
            'agencies.*.in' => 'Agency sync only supports IRS and Oregon Revenue Online.',
        ];
    }
}
