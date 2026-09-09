<?php

namespace App\Http\Requests;

use App\Models\TransactionCategory;
use Illuminate\Foundation\Http\FormRequest;

class FilterAccountTransactionsRequest extends FormRequest
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
            'category' => [
                'nullable',
                'string',
                'max:40',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '' || $value === 'uncategorized') {
                        return;
                    }

                    if (! is_numeric($value)) {
                        $fail('Choose a valid category filter.');

                        return;
                    }

                    $categoryExists = TransactionCategory::query()
                        ->whereKey((int) $value)
                        ->where(function ($query): void {
                            $query->whereNull('user_id')
                                ->orWhere('user_id', $this->user()?->id);
                        })
                        ->exists();

                    if (! $categoryExists) {
                        $fail('Choose a valid category filter.');
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $search = $this->input('search');
        $category = $this->input('category');
        $startDate = $this->input('start_date');
        $endDate = $this->input('end_date');

        $this->merge([
            'search' => is_string($search) ? trim($search) : $search,
            'category' => $category === '' ? null : $category,
            'start_date' => $startDate === '' ? null : $startDate,
            'end_date' => $endDate === '' ? null : $endDate,
        ]);
    }
}
