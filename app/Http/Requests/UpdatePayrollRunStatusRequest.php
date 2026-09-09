<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePayrollRunStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:complete_run,reopen_run,complete_federal_deposit,reopen_federal_deposit,complete_oregon_deposit,reopen_oregon_deposit',
            'return_to' => 'nullable|string|max:500',
        ];
    }
}
