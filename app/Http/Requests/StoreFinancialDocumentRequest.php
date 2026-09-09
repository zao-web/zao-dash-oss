<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFinancialDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|max:102400|mimes:pdf,jpg,jpeg,png,zip',
            'document_type' => 'nullable|string|max:50',
            'scope' => 'nullable|string|in:all,personal,business',
            'tax_year' => 'nullable|integer|min:2018|max:2035',
            'return_to' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose a document or Financials archive to upload.',
            'file.max' => 'Uploads must be smaller than 100MB.',
            'file.mimes' => 'Only PDF, JPG, PNG, and ZIP archive uploads are supported.',
            'scope.in' => 'The document scope must be personal or business.',
            'tax_year.integer' => 'The tax year must be a valid year.',
            'return_to.max' => 'The return path is too long.',
        ];
    }
}
