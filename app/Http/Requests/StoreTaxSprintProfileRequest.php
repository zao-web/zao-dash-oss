<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaxSprintProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tax_year' => 'required|integer|min:2024|max:2035',
            'filing_status' => 'required|string|in:single,mfj,mfs,hoh',
            'resident_state' => 'required|string|size:2',
            'resident_city' => 'nullable|string|max:255',
            'entity_type' => 'required|string|in:sole_prop,s_corp,llc,c_corp',
            'reasonable_salary' => 'nullable|numeric|min:0|max:999999999.99',
            'w2_wages_paid' => 'nullable|numeric|min:0|max:999999999.99',
        ];
    }

    public function messages(): array
    {
        return [
            'filing_status.in' => 'Choose a supported federal filing status.',
            'resident_state.size' => 'Use a two-letter state code.',
            'entity_type.in' => 'Choose the entity type that will file this year.',
            'reasonable_salary.min' => 'Reasonable salary cannot be negative.',
            'w2_wages_paid.min' => 'Actual W-2 wages paid cannot be negative.',
        ];
    }
}
