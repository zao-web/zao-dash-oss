<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseBookkeepingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tax_year' => 'required|integer|min:2024|max:2035',
            'period_month' => 'required|integer|min:1|max:12',
            'notes' => 'nullable|string|max:1000',
            'return_to' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'period_month.min' => 'Choose a bookkeeping month between January and December.',
            'period_month.max' => 'Choose a bookkeeping month between January and December.',
        ];
    }
}
