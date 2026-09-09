<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Console\Command;

/**
 * Import tasks from a CSV file (e.g., Notion export).
 *
 * Notion exports include columns like: Name, Status, Priority, Due Date, etc.
 * This command maps those to our Task model fields.
 */
class ImportTasksFromCsvCommand extends Command
{
    protected $signature = 'tasks:import-csv
        {file : Path to CSV file}
        {--project= : Project ID or slug to import into}
        {--dry-run : Preview import without creating tasks}
        {--status-map= : JSON status mapping, e.g. {"Done":"completed","In Progress":"in_progress"}}
        {--title-column=Name : CSV column name for task title}
        {--status-column=Status : CSV column name for status}
        {--description-column=Description : CSV column name for description}
        {--priority-column=Priority : CSV column name for priority}
        {--due-column= : CSV column name for due date}';

    protected $description = 'Import tasks from CSV (Notion export, etc.)';

    protected array $defaultStatusMap = [
        // Notion common statuses
        'Not started' => 'pending',
        'To Do' => 'pending',
        'Todo' => 'pending',
        'Backlog' => 'pending',
        'In progress' => 'in_progress',
        'In Progress' => 'in_progress',
        'Doing' => 'in_progress',
        'In Review' => 'review',
        'Review' => 'review',
        'Done' => 'completed',
        'Completed' => 'completed',
        'Complete' => 'completed',
        'Cancelled' => 'completed',
        'Archived' => 'completed',
    ];

    protected array $priorityMap = [
        'High' => 'high',
        'Medium' => 'medium',
        'Low' => 'low',
        'Urgent' => 'urgent',
        'Critical' => 'urgent',
        'Normal' => 'medium',
        '1' => 'urgent',
        '2' => 'high',
        '3' => 'medium',
        '4' => 'low',
    ];

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $dryRun = $this->option('dry-run');

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

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

        $this->info("Importing to project: {$project->name}");

        // Parse status map
        $statusMap = $this->defaultStatusMap;
        if ($customMap = $this->option('status-map')) {
            $decoded = json_decode($customMap, true);
            if ($decoded) {
                $statusMap = array_merge($statusMap, $decoded);
            }
        }

        // Read CSV
        $handle = fopen($filePath, 'r');
        $headers = fgetcsv($handle);

        if (! $headers) {
            $this->error('Could not read CSV headers');

            return 1;
        }

        $this->info('CSV columns found: '.implode(', ', $headers));

        // Map column names to indices
        $titleCol = $this->option('title-column');
        $statusCol = $this->option('status-column');
        $descCol = $this->option('description-column');
        $priorityCol = $this->option('priority-column');
        $dueCol = $this->option('due-column');

        $colMap = array_flip($headers);

        if (! isset($colMap[$titleCol])) {
            $this->error("Title column '{$titleCol}' not found. Available: ".implode(', ', $headers));

            return 1;
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $title = trim($row[$colMap[$titleCol]] ?? '');

            if (empty($title)) {
                $skipped++;

                continue;
            }

            // Get status
            $rawStatus = isset($colMap[$statusCol]) ? trim($row[$colMap[$statusCol]] ?? '') : '';
            $status = $statusMap[$rawStatus] ?? 'pending';

            // Get description
            $description = isset($colMap[$descCol]) ? trim($row[$colMap[$descCol]] ?? '') : '';

            // Get priority
            $rawPriority = isset($colMap[$priorityCol]) ? trim($row[$colMap[$priorityCol]] ?? '') : '';
            $priority = $this->priorityMap[$rawPriority] ?? 'medium';

            // Get due date
            $dueDate = null;
            if ($dueCol && isset($colMap[$dueCol])) {
                $rawDue = trim($row[$colMap[$dueCol]] ?? '');
                if ($rawDue) {
                    try {
                        $dueDate = \Carbon\Carbon::parse($rawDue);
                    } catch (\Exception $e) {
                        // Skip invalid dates
                    }
                }
            }

            $this->line("  [{$status}] {$title}".($rawStatus !== $status ? " (was: {$rawStatus})" : ''));

            if (! $dryRun) {
                Task::create([
                    'project_id' => $project->id,
                    'title' => $title,
                    'description' => $description ?: null,
                    'status' => $status,
                    'priority' => $priority,
                    'due_date' => $dueDate,
                    'source' => 'import',
                    'metadata' => ['imported_from' => 'csv', 'original_status' => $rawStatus],
                ]);
            }

            $imported++;
        }

        fclose($handle);

        if ($dryRun) {
            $this->info("DRY RUN: Would import {$imported} tasks, skip {$skipped} empty rows");
        } else {
            $this->info("Imported {$imported} tasks, skipped {$skipped} empty rows");
        }

        return 0;
    }
}
