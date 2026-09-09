<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateAnnualReturnPacketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => 'required|integer|min:2024|max:2030',
            'format' => 'nullable|in:html,pdf',
        ];
    }

    public function messages(): array
    {
        return [
            'year.required' => 'Choose the tax year for the annual return packet.',
            'year.integer' => 'The tax year must be a valid year.',
            'format.in' => 'Annual return packets can only be generated as HTML or PDF.',
        ];
    }
}
