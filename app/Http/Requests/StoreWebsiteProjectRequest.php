<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebsiteProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $domain = $this->input('domain');
        if ($domain) {
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = rtrim($domain, '/');
            $this->merge(['domain' => $domain]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'project_type' => 'required|in:autonomous,guided,migration,redesign',
            'source_type' => 'nullable|in:domain,brief,url,github,manual',
            'source_data' => 'nullable|array',
            'domain' => 'nullable|string|regex:/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/',
            'brief' => 'nullable|string|min:10|max:5000',
            'hosting_type' => 'nullable|in:wordpress_com,self_hosted,existing_site',
            'environment' => 'nullable|in:staging,production',
            'budget_limit' => 'nullable|numeric|min:10|max:500',
            'url' => 'nullable|url',
            'github_url' => 'nullable|url',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please provide a project name.',
            'project_type.required' => 'Please select a project type.',
            'project_type.in' => 'Invalid project type selected.',
            'domain.regex' => 'Please provide a valid domain name (e.g., example.com).',
            'brief.min' => 'The brief must be at least 10 characters.',
            'brief.max' => 'The brief cannot exceed 5000 characters.',
            'budget_limit.min' => 'Minimum budget is $10.',
            'budget_limit.max' => 'Maximum budget is $500.',
        ];
    }
}
