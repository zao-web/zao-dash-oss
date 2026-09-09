<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SowImportConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client.name' => 'required|string|max:255',
            'client.website' => 'nullable|string|max:255',
            'client.description' => 'nullable|string',
            'contacts' => 'nullable|array',
            'contacts.*.name' => 'required|string|max:255',
            'contacts.*.email' => 'required|email|max:255',
            'contacts.*.role' => 'nullable|string|max:255',
            'contacts.*.phone' => 'nullable|string|max:50',
            'project.name' => 'required|string|max:255',
            'project.description' => 'nullable|string',
            'project.type' => 'required|in:retainer,project,support',
            'project.budget' => 'nullable|numeric|min:0',
            'project.start_date' => 'nullable|date',
            'project.end_date' => 'nullable|date',
            'milestones' => 'required|array|min:1',
            'milestones.*.name' => 'required|string|max:255',
            'milestones.*.description' => 'nullable|string',
            'milestones.*.due_date' => 'nullable|date',
            'milestones.*.tasks' => 'required|array|min:1',
            'milestones.*.tasks.*.title' => 'required|string|max:255',
            'milestones.*.tasks.*.description' => 'nullable|string',
            'milestones.*.tasks.*.priority' => 'nullable|in:low,medium,high,urgent',
            'milestones.*.tasks.*.estimated_hours' => 'nullable|numeric|min:0',
            'milestones.*.tasks.*.due_date' => 'nullable|date',
            'milestones.*.tasks.*.subtasks' => 'nullable|array',
            'milestones.*.tasks.*.subtasks.*.title' => 'required|string|max:255',
            'milestones.*.tasks.*.subtasks.*.description' => 'nullable|string',
            'milestones.*.tasks.*.subtasks.*.due_date' => 'nullable|date',
            'invoices' => 'nullable|array',
            'invoices.*.subject' => 'nullable|string|max:255',
            'invoices.*.description' => 'nullable|string',
            'invoices.*.amount' => 'nullable|numeric|min:0',
            'invoices.*.issue_date' => 'nullable|date',
            'invoices.*.due_date' => 'nullable|date',
            'invoices.*.due_days' => 'nullable|integer|min:0',
            'invoices.*.items' => 'nullable|array|min:1',
            'invoices.*.items.*.description' => 'required_with:invoices.*.items|string|max:255',
            'invoices.*.items.*.quantity' => 'required_with:invoices.*.items|numeric|min:0',
            'invoices.*.items.*.unit_price' => 'required_with:invoices.*.items|numeric|min:0',
            'invoices.*.items.*.type' => 'nullable|in:time,fixed,expense,discount',
            'billing.recurring_invoice' => 'nullable|array',
            'billing.recurring_invoice.enabled' => 'nullable|boolean',
            'billing.recurring_invoice.amount' => 'nullable|numeric|min:0',
            'billing.recurring_invoice.day' => 'nullable|integer|min:1|max:28',
            'billing.recurring_invoice.description' => 'nullable|string|max:255',
            'billing.recurring_invoice.auto_send' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'client.name.required' => 'A client name is required.',
            'project.name.required' => 'A project name is required.',
            'project.type.required' => 'A project type is required.',
            'project.type.in' => 'Project type must be retainer, project, or support.',
            'milestones.required' => 'At least one milestone is required.',
            'milestones.min' => 'At least one milestone is required.',
            'milestones.*.name.required' => 'Each milestone must have a name.',
            'milestones.*.tasks.required' => 'Each milestone must have at least one task.',
            'milestones.*.tasks.min' => 'Each milestone must have at least one task.',
            'milestones.*.tasks.*.title.required' => 'Each task must have a title.',
        ];
    }
}
