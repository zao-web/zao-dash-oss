<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PromptLibraryController extends Controller
{
    /**
     * List all prompt templates.
     */
    public function index(Request $request)
    {
        $query = PromptTemplate::with(['agent:id,name,slug', 'createdBy:id,name'])
            ->withCount('runs');

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($agentId = $request->input('agent_id')) {
            $query->where('agent_id', $agentId);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereJsonContains('tags', $search);
            });
        }

        $templates = $query->orderByDesc('usage_count')->get();

        return Inertia::render('Prompts/Index', [
            'templates' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'description' => $t->description,
                'category' => $t->category,
                'content_preview' => Str::limit($t->content, 150),
                'variables' => $t->extractVariables(),
                'tags' => $t->tags ?? [],
                'agent' => $t->agent ? ['id' => $t->agent->id, 'name' => $t->agent->name] : null,
                'created_by' => $t->createdBy?->name,
                'is_active' => $t->is_active,
                'is_public' => $t->is_public,
                'usage_count' => $t->usage_count,
                'runs_count' => $t->runs_count,
                'metrics' => $t->getMetrics(),
            ]),
            'categories' => [
                'system' => 'System Prompts',
                'task' => 'Task Prompts',
                'analysis' => 'Analysis',
                'content' => 'Content Creation',
                'communication' => 'Communication',
                'code' => 'Code Generation',
            ],
            'agents' => Agent::select('id', 'name', 'slug')->get(),
            'filters' => [
                'category' => $request->input('category'),
                'agent_id' => $request->input('agent_id'),
                'search' => $request->input('search'),
            ],
        ]);
    }

    /**
     * Show a single prompt template with versions.
     */
    public function show(PromptTemplate $promptTemplate)
    {
        $promptTemplate->load(['versions.createdBy:id,name', 'agent:id,name,slug', 'createdBy:id,name']);

        return Inertia::render('Prompts/Show', [
            'template' => [
                'id' => $promptTemplate->id,
                'name' => $promptTemplate->name,
                'slug' => $promptTemplate->slug,
                'description' => $promptTemplate->description,
                'category' => $promptTemplate->category,
                'content' => $promptTemplate->content,
                'variables' => $promptTemplate->variables ?? $promptTemplate->extractVariables(),
                'tags' => $promptTemplate->tags ?? [],
                'agent' => $promptTemplate->agent,
                'created_by' => $promptTemplate->createdBy?->name,
                'is_active' => $promptTemplate->is_active,
                'is_public' => $promptTemplate->is_public,
                'usage_count' => $promptTemplate->usage_count,
                'created_at' => $promptTemplate->created_at->format('M d, Y'),
            ],
            'versions' => $promptTemplate->versions->map(fn ($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'description' => $v->description,
                'content_preview' => Str::limit($v->content, 200),
                'is_active' => $v->is_active,
                'ab_test_weight' => $v->ab_test_weight,
                'created_by' => $v->createdBy?->name,
                'created_at' => $v->created_at->format('M d, Y H:i'),
                'metrics' => $v->getMetrics(),
            ]),
            'metrics' => $promptTemplate->getMetrics(),
        ]);
    }

    /**
     * Create a new prompt template.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|in:system,task,analysis,content,communication,code',
            'content' => 'required|string',
            'variables' => 'nullable|array',
            'tags' => 'nullable|array',
            'agent_id' => 'nullable|exists:agents,id',
            'is_public' => 'boolean',
        ]);

        $template = PromptTemplate::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'],
            'category' => $validated['category'],
            'content' => $validated['content'],
            'variables' => $validated['variables'],
            'tags' => $validated['tags'],
            'agent_id' => $validated['agent_id'],
            'is_public' => $validated['is_public'] ?? false,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        // Create initial version
        $template->createVersion($validated['content'], 'Initial version');

        return redirect()->route('prompts.show', $template)
            ->with('success', 'Prompt template created');
    }

    /**
     * Update a prompt template.
     */
    public function update(Request $request, PromptTemplate $promptTemplate)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category' => 'sometimes|in:system,task,analysis,content,communication,code',
            'content' => 'sometimes|string',
            'variables' => 'nullable|array',
            'tags' => 'nullable|array',
            'agent_id' => 'nullable|exists:agents,id',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ]);

        // If content changed, create a new version
        $createVersion = isset($validated['content']) && $validated['content'] !== $promptTemplate->content;
        $versionDescription = $request->input('version_description');

        $promptTemplate->update($validated);

        if ($createVersion) {
            $promptTemplate->createVersion($validated['content'], $versionDescription);
        }

        return back()->with('success', 'Prompt template updated');
    }

    /**
     * Delete a prompt template.
     */
    public function destroy(PromptTemplate $promptTemplate)
    {
        $promptTemplate->delete();

        return redirect()->route('prompts.index')
            ->with('success', 'Prompt template deleted');
    }

    /**
     * Create a new version of a prompt.
     */
    public function createVersion(Request $request, PromptTemplate $promptTemplate)
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'description' => 'nullable|string',
        ]);

        $version = $promptTemplate->createVersion(
            $validated['content'],
            $validated['description']
        );

        // Update template content to match latest version
        $promptTemplate->update(['content' => $validated['content']]);

        return back()->with('success', "Version {$version->version_number} created");
    }

    /**
     * Activate a specific version.
     */
    public function activateVersion(PromptTemplate $promptTemplate, PromptVersion $version)
    {
        if ($version->prompt_template_id !== $promptTemplate->id) {
            abort(404);
        }

        $version->activate();

        // Update template content to active version
        $promptTemplate->update(['content' => $version->content]);

        return back()->with('success', "Version {$version->version_number} activated");
    }

    /**
     * Get version content for comparison.
     */
    public function getVersionContent(PromptTemplate $promptTemplate, PromptVersion $version)
    {
        if ($version->prompt_template_id !== $promptTemplate->id) {
            abort(404);
        }

        return response()->json([
            'version_number' => $version->version_number,
            'content' => $version->content,
            'variables' => $version->variables,
            'metrics' => $version->getMetrics(),
        ]);
    }

    /**
     * Compare two versions.
     */
    public function compareVersions(PromptTemplate $promptTemplate, Request $request)
    {
        $validated = $request->validate([
            'version_a' => 'required|exists:prompt_versions,id',
            'version_b' => 'required|exists:prompt_versions,id',
        ]);

        $versionA = PromptVersion::find($validated['version_a']);
        $versionB = PromptVersion::find($validated['version_b']);

        return response()->json($versionA->compareWith($versionB));
    }

    /**
     * Set A/B test weights for versions.
     */
    public function setAbTestWeights(PromptTemplate $promptTemplate, Request $request)
    {
        $validated = $request->validate([
            'weights' => 'required|array',
            'weights.*.version_id' => 'required|exists:prompt_versions,id',
            'weights.*.weight' => 'required|integer|min:0|max:100',
        ]);

        foreach ($validated['weights'] as $item) {
            PromptVersion::where('id', $item['version_id'])
                ->where('prompt_template_id', $promptTemplate->id)
                ->update(['ab_test_weight' => $item['weight']]);
        }

        return back()->with('success', 'A/B test weights updated');
    }

    /**
     * Preview rendered prompt with variables.
     */
    public function preview(Request $request, PromptTemplate $promptTemplate)
    {
        $variables = $request->input('variables', []);

        return response()->json([
            'rendered' => $promptTemplate->render($variables),
            'variables_used' => $promptTemplate->extractVariables(),
        ]);
    }

    /**
     * Duplicate a prompt template.
     */
    public function duplicate(PromptTemplate $promptTemplate)
    {
        $newTemplate = $promptTemplate->replicate();
        $newTemplate->name = $promptTemplate->name.' (Copy)';
        $newTemplate->slug = Str::slug($newTemplate->name).'-'.Str::random(6);
        $newTemplate->usage_count = 0;
        $newTemplate->created_by = auth()->id();
        $newTemplate->save();

        // Copy current version
        $newTemplate->createVersion($promptTemplate->content, 'Duplicated from '.$promptTemplate->name);

        return redirect()->route('prompts.show', $newTemplate)
            ->with('success', 'Prompt template duplicated');
    }
}
