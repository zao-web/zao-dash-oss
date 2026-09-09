<?php

namespace App\Http\Requests;

use App\Services\Tax\OwnerPaymentReviewService;
use Illuminate\Foundation\Http\FormRequest;

class ReviewOwnerPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'classification' => 'required|in:'.implode(',', OwnerPaymentReviewService::classificationValues()),
            'learn_match' => 'nullable|boolean',
            'return_to' => 'nullable|string|max:500',
        ];
    }
}
