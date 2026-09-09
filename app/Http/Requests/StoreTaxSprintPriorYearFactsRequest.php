<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaxSprintPriorYearFactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tax_year' => 'required|integer|min:2024|max:2035',
            'prior_year_federal_overpayment_applied' => 'nullable|numeric|min:0|max:999999999.99',
            'prior_year_oregon_overpayment_applied' => 'nullable|numeric|min:0|max:999999999.99',
            'prior_year_capital_loss_carryforward' => 'nullable|numeric|min:0|max:999999999.99',
            'prior_year_nol_carryforward' => 'nullable|numeric|min:0|max:999999999.99',
            'prior_year_shareholder_basis' => 'nullable|numeric|min:0|max:999999999.99',
        ];
    }

    public function messages(): array
    {
        return [
            'prior_year_federal_overpayment_applied.min' => 'Federal overpayment applied cannot be negative.',
            'prior_year_oregon_overpayment_applied.min' => 'Oregon overpayment applied cannot be negative.',
            'prior_year_capital_loss_carryforward.min' => 'Capital loss carryforward cannot be negative.',
            'prior_year_nol_carryforward.min' => 'NOL carryforward cannot be negative.',
            'prior_year_shareholder_basis.min' => 'Shareholder basis cannot be negative.',
        ];
    }
}
