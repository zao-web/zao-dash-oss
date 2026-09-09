<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaxSprintPersonalReturnFactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tax_year' => 'required|integer|min:2024|max:2035',
            'mortgage_interest_paid' => 'nullable|numeric|min:0|max:999999999.99',
            'property_tax_paid' => 'nullable|numeric|min:0|max:999999999.99',
            'charitable_contributions_paid' => 'nullable|numeric|min:0|max:999999999.99',
            'medical_expenses_paid' => 'nullable|numeric|min:0|max:999999999.99',
            'hsa_contributions_paid' => 'nullable|numeric|min:0|max:999999999.99',
            'education_expenses_paid' => 'nullable|numeric|min:0|max:999999999.99',
        ];
    }

    public function messages(): array
    {
        return [
            'mortgage_interest_paid.min' => 'Mortgage interest cannot be negative.',
            'property_tax_paid.min' => 'Property tax cannot be negative.',
            'charitable_contributions_paid.min' => 'Charitable contributions cannot be negative.',
            'medical_expenses_paid.min' => 'Medical expenses cannot be negative.',
            'hsa_contributions_paid.min' => 'HSA contributions cannot be negative.',
            'education_expenses_paid.min' => 'Education expenses cannot be negative.',
        ];
    }
}
