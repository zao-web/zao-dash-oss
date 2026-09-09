<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewBookkeepingAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:approve,dismiss',
            'category_id' => 'nullable|exists:transaction_categories,id',
            'learn_match' => 'nullable|boolean',
            'return_to' => 'nullable|string|max:500',
        ];
    }
}
