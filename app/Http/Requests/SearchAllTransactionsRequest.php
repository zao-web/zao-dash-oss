<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchAllTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:end_date'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'account_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => is_string($this->input('search')) ? trim((string) $this->input('search')) : null,
            'start_date' => $this->input('start_date') === '' ? null : $this->input('start_date'),
            'end_date' => $this->input('end_date') === '' ? null : $this->input('end_date'),
            'account_id' => $this->input('account_id') === '' ? null : $this->input('account_id'),
        ]);
    }
}
