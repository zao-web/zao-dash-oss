<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendProjectMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('project')->user_id === auth()->id();
    }

    public function rules(): array
    {
        return [
            'message' => 'required|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Please provide a message.',
            'message.max' => 'Message cannot exceed 2000 characters.',
        ];
    }
}
