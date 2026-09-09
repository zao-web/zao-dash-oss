<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunBookkeepingAiCleanupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tax_year' => 'required|integer|min:2024|max:2035',
            'scope' => 'nullable|in:business,all',
            'return_to' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'scope.in' => 'Bookkeeping cleanup scope must be business or all accounts.',
        ];
    }
}
