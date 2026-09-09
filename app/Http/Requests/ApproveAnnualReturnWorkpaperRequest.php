<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAnnualReturnWorkpaperRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => 'required|integer|min:2024|max:2030',
            'confirm_owner_review' => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'year.required' => 'Choose the tax year you are approving.',
            'confirm_owner_review.accepted' => 'Confirm owner review before approving the filing packet.',
        ];
    }
}
