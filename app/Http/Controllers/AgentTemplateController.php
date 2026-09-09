<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Manages agent templates - pre-built configurations for creating agents.
 */
class AgentTemplateController extends Controller
{
    /**
     * List all templates.
     */
    public function index(Request $request)
    {
        $query = AgentTemplate::query();

        if ($request->has('category')) {
            $query->category($request->category);
        }

        if ($request->boolean('public_only', true)) {
            $query->public();
        }

        $templates = $query->orderBy('usage_count', 'desc')->get();

        // API routes always return JSON
        if ($request->wantsJson() || str_starts_with($request->path(), 'api/')) {
            return response()->json($templates);
        }

        return inertia('Agents/Templates', [
            'templates' => $templates,
            'categories' => AgentTemplate::distinct()->pluck('category'),
        ]);
    }

    /**
     * Show a single template.
     */
    public function show(AgentTemplate $template)
    {
        return response()->json($template);
    }

    /**
     * Create an agent from a template.
     */
    public function createAgent(Request $request, AgentTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:agents,slug',
            'description' => 'nullable|string',
            'config' => 'nullable|array',
        ]);

        // Validate config against template schema
        $config = $validated['config'] ?? [];
        $errors = $template->validateConfig($config);

        if (! empty($errors)) {
            return response()->json([
                'message' => 'Configuration validation failed',
                'errors' => $errors,
            ], 422);
        }

        try {
            $agent = $template->createAgent([
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? null,
                'description' => $validated['description'] ?? null,
                ...$config,
            ]);

            Log::info('Agent created from template', [
                'agent_id' => $agent->id,
                'template_id' => $template->id,
            ]);

            return response()->json([
                'message' => 'Agent created successfully',
                'agent' => $agent,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create agent from template', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create agent: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Preview agent configuration from template.
     */
    public function preview(Request $request, AgentTemplate $template)
    {
        $config = $request->input('config', []);

        // Replace placeholders in system prompt
        $systemPrompt = $template->system_prompt_template;
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $systemPrompt = str_replace("{{{$key}}}", $value, $systemPrompt);
            }
        }

        return response()->json([
            'template' => $template->only(['name', 'slug', 'description', 'category']),
            'preview' => [
                'model' => $config['model'] ?? $template->default_model,
                'budget' => $config['max_budget_usd'] ?? $template->default_budget_usd,
                'requires_approval' => $config['requires_approval'] ?? $template->default_requires_approval,
                'tools' => $config['tools'] ?? $template->default_tools,
                'system_prompt' => $systemPrompt,
            ],
            'config_schema' => $template->config_schema,
        ]);
    }

    /**
     * Get template categories.
     */
    public function categories()
    {
        $categories = AgentTemplate::selectRaw('category, COUNT(*) as count')
            ->where('is_public', true)
            ->groupBy('category')
            ->orderBy('count', 'desc')
            ->get();

        return response()->json($categories);
    }

    /**
     * Create a new template (admin only).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:agent_templates,slug',
            'description' => 'nullable|string',
            'category' => 'required|string|max:100',
            'default_model' => 'nullable|string|in:opus,sonnet,haiku',
            'default_budget_usd' => 'nullable|numeric|min:0|max:100',
            'default_requires_approval' => 'nullable|boolean',
            'default_tools' => 'nullable|array',
            'system_prompt_template' => 'nullable|string',
            'config_schema' => 'nullable|array',
            'is_public' => 'nullable|boolean',
        ]);

        $template = AgentTemplate::create([
            ...$validated,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Template created successfully',
            'template' => $template,
        ], 201);
    }

    /**
     * Update a template.
     */
    public function update(Request $request, AgentTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category' => 'sometimes|string|max:100',
            'default_model' => 'nullable|string|in:opus,sonnet,haiku',
            'default_budget_usd' => 'nullable|numeric|min:0|max:100',
            'default_requires_approval' => 'nullable|boolean',
            'default_tools' => 'nullable|array',
            'system_prompt_template' => 'nullable|string',
            'config_schema' => 'nullable|array',
            'is_public' => 'nullable|boolean',
        ]);

        $template->update($validated);

        return response()->json([
            'message' => 'Template updated successfully',
            'template' => $template->fresh(),
        ]);
    }

    /**
     * Delete a template.
     */
    public function destroy(AgentTemplate $template)
    {
        $template->delete();

        return response()->json([
            'message' => 'Template deleted successfully',
        ]);
    }

    /**
     * Get featured/popular templates for marketplace.
     */
    public function featured(Request $request)
    {
        $limit = $request->integer('limit', 6);

        $featured = AgentTemplate::public()
            ->orderByDesc('usage_count')
            ->limit($limit)
            ->get();

        return response()->json([
            'featured' => $featured,
            'total_templates' => AgentTemplate::public()->count(),
            'total_categories' => AgentTemplate::public()->distinct('category')->count(),
        ]);
    }

    /**
     * Search templates.
     */
    public function search(Request $request)
    {
        $query = $request->string('q', '');
        $category = $request->string('category', '');

        $templates = AgentTemplate::public()
            ->when($query, function ($q) use ($query) {
                $q->where(function ($q2) use ($query) {
                    $q2->where('name', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%");
                });
            })
            ->when($category, function ($q) use ($category) {
                $q->where('category', $category);
            })
            ->orderByDesc('usage_count')
            ->get();

        return response()->json([
            'results' => $templates,
            'query' => $query,
            'count' => $templates->count(),
        ]);
    }

    /**
     * Create a template from an existing agent (share to marketplace).
     */
    public function createFromAgent(Request $request, Agent $agent)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:agent_templates,slug',
            'description' => 'nullable|string',
            'category' => 'required|string|max:100',
            'is_public' => 'nullable|boolean',
        ]);

        $template = AgentTemplate::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? $agent->description,
            'category' => $validated['category'],
            'default_model' => $agent->model,
            'default_budget_usd' => $agent->max_budget_usd,
            'default_requires_approval' => $agent->requires_approval,
            'default_tools' => $agent->allowed_tools,
            'system_prompt_template' => $agent->system_prompt,
            'is_public' => $validated['is_public'] ?? false,
            'created_by' => $request->user()?->id,
        ]);

        Log::info('Template created from agent', [
            'template_id' => $template->id,
            'agent_id' => $agent->id,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Template created from agent successfully',
            'template' => $template,
        ], 201);
    }

    /**
     * Duplicate an existing template.
     */
    public function duplicate(Request $request, AgentTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $newName = $validated['name'] ?? $template->name.' (Copy)';
        $baseSlug = \Illuminate\Support\Str::slug($newName);
        $slug = $baseSlug;
        $counter = 1;

        while (AgentTemplate::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        $duplicate = AgentTemplate::create([
            'name' => $newName,
            'slug' => $slug,
            'description' => $template->description,
            'category' => $template->category,
            'default_model' => $template->default_model,
            'default_budget_usd' => $template->default_budget_usd,
            'default_requires_approval' => $template->default_requires_approval,
            'default_tools' => $template->default_tools,
            'system_prompt_template' => $template->system_prompt_template,
            'config_schema' => $template->config_schema,
            'is_public' => false, // Duplicates start as private
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Template duplicated successfully',
            'template' => $duplicate,
        ], 201);
    }
}
