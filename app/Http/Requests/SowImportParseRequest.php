<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SowImportParseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'documents' => 'required|array|min:1|max:5',
            'documents.*.type' => 'required|in:pdf,google_drive,text',
            'documents.*.file' => 'required_if:documents.*.type,pdf|file|mimes:pdf|max:20480',
            'documents.*.google_drive_url' => 'required_if:documents.*.type,google_drive|string',
            'documents.*.content' => 'required_if:documents.*.type,text|string',
            'documents.*.label' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'documents.required' => 'At least one document is required.',
            'documents.max' => 'A maximum of 5 documents can be uploaded at once.',
            'documents.*.type.required' => 'Each document must specify a type.',
            'documents.*.type.in' => 'Document type must be pdf, google_drive, or text.',
            'documents.*.file.required_if' => 'A PDF file is required for PDF document type.',
            'documents.*.file.mimes' => 'Only PDF files are supported.',
            'documents.*.file.max' => 'PDF files must be smaller than 20MB.',
            'documents.*.google_drive_url.required_if' => 'A Google Drive URL is required for Google Drive document type.',
            'documents.*.content.required_if' => 'Text content is required for text document type.',
        ];
    }
}
