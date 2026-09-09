<?php

namespace App\Http\Requests;

use App\Models\TaxEntityLifecycleDecision;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaxEntityLifecycleDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tax_year' => 'required|integer|min:2024|max:2035',
            'entity_name' => 'required|string|max:255',
            'decision' => 'required|string|in:'.implode(',', TaxEntityLifecycleDecision::decisions()),
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'decision.in' => 'Choose whether the entity is active, filing a final return, dissolving, or inactive.',
        ];
    }
}
