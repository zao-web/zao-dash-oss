<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportXBookmarksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credential_id' => 'required|integer|exists:x_credentials,id',
            'bookmarks' => 'required|array|min:1',
            'bookmarks.*' => 'required|array',
            'skip_duplicates' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'credential_id.required' => 'Please select an X account to import to.',
            'credential_id.exists' => 'The selected X account does not exist.',
            'bookmarks.required' => 'No bookmarks data provided.',
            'bookmarks.min' => 'At least one bookmark is required.',
        ];
    }
}
