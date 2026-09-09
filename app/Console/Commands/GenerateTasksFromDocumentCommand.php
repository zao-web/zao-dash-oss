<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use App\Services\AI\ClaudeCliService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Generate tasks from a document (SOW, brief, requirements doc).
 *
 * Uses Claude to parse the document and extract actionable tasks.
 */
class GenerateTasksFromDocumentCommand extends Command
{
    protected $signature = 'tasks:generate-from-doc
        {file : Path to document (txt, md, or paste content)}
        {--project= : Project ID or slug}
        {--dry-run : Preview tasks without creating}
        {--milestone= : Optional milestone to assign tasks to}';

    protected $description = 'Generate tasks from SOW, brief, or requirements document using AI';

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $dryRun = $this->option('dry-run');

        // Read document content
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
        } else {
            $this->error("File not found: {$filePath}");

            return 1;
        }

        if (empty(trim($content))) {
            $this->error('Document is empty');

            return 1;
        }

        // Get project
        $projectRef = $this->option('project');
        if (! $projectRef) {
            $this->error('--project is required. Specify project ID or slug.');

            return 1;
        }

        $project = is_numeric($projectRef)
            ? Project::find($projectRef)
            : Project::where('slug', $projectRef)->first();

        if (! $project) {
            $this->error("Project not found: {$projectRef}");

            return 1;
        }

        $this->info("Generating tasks for project: {$project->name}");
        $this->info('Document length: '.strlen($content).' characters');
        $this->newLine();

        // Call Claude to extract tasks
        $this->info('Analyzing document with AI...');

        try {
            $tasks = $this->extractTasksWithClaude($content, $project);
        } catch (\Exception $e) {
            $this->error('AI extraction failed: '.$e->getMessage());

            return 1;
        }

        if (empty($tasks)) {
            $this->warn('No tasks extracted from document');

            return 0;
        }

        $this->info('Extracted '.count($tasks).' tasks:');
        $this->newLine();

        $milestoneId = $this->option('milestone');
        $created = 0;

        foreach ($tasks as $i => $taskData) {
            $num = $i + 1;
            $priority = $taskData['priority'] ?? 'medium';
            $estimate = $taskData['estimated_hours'] ?? null;

            $this->line("[{$num}] {$taskData['title']}");
            $this->line("    Priority: {$priority}".($estimate ? ", Estimate: {$estimate}h" : ''));
            if (! empty($taskData['description'])) {
                $desc = Str::limit($taskData['description'], 100);
                $this->line("    {$desc}");
            }
            $this->newLine();

            if (! $dryRun) {
                Task::create([
                    'project_id' => $project->id,
                    'milestone_id' => $milestoneId,
                    'title' => $taskData['title'],
                    'description' => $taskData['description'] ?? null,
                    'status' => 'pending',
                    'priority' => $priority,
                    'estimated_hours' => $estimate,
                    'source' => 'ai_generated',
                    'metadata' => [
                        'generated_from' => 'sow',
                        'generation_context' => $taskData['context'] ?? null,
                    ],
                ]);
                $created++;
            }
        }

        if ($dryRun) {
            $this->info('DRY RUN: Would create '.count($tasks).' tasks');
        } else {
            $this->info("Created {$created} tasks");
        }

        return 0;
    }

    protected function extractTasksWithClaude(string $content, Project $project): array
    {
        $cli = new ClaudeCliService;

        $systemPrompt = <<<'PROMPT'
You are a project manager extracting actionable tasks from project documents.

Analyze the document and extract discrete, actionable tasks. For each task provide:
- title: Clear, concise task title (action-oriented, starts with verb)
- description: Brief description with context and acceptance criteria
- priority: urgent, high, medium, or low
- estimated_hours: Rough estimate if possible (null if unclear)
- context: Which section/requirement this relates to

Focus on development, design, content, QA, and DevOps tasks.
Skip vague items, administrative overhead, or completed items.
PROMPT;

        $userPrompt = <<<PROMPT
Extract actionable tasks from this project document for "{$project->name}":

---
{$content}
---

Return a JSON array of tasks. Example format:
[
  {
    "title": "Implement user authentication flow",
    "description": "Add login/logout functionality with session management.",
    "priority": "high",
    "estimated_hours": 8,
    "context": "Section 2.1 - User Management"
  }
]
PROMPT;

        $tasks = $cli->messageJson($userPrompt, $systemPrompt, 'sonnet', 180);

        if (! $tasks || ! is_array($tasks)) {
            throw new \Exception('Could not parse AI response as JSON');
        }

        return $tasks;
    }
}
