<?php

namespace App\Agents\Tools;

use App\Models\Project;
use App\Services\GitHub\CommitEffortEstimationService;
use App\Services\GitHub\GitHubApiService;
use App\Services\GitHub\GitHubUserApiService;

/**
 * Estimates assume a 10x senior engineer's pace - conservative for professional billing.
 * AI might complete work in 20 minutes that takes a junior 40 hours, but we bill
 * based on what a senior engineer would reasonably charge.
 */
class EstimateCommitEffortTool extends BaseTool
{
    private const SENIOR_ENGINEER_MULTIPLIER = 1.25;

    public function __construct(
        private GitHubApiService $github,
        private ?GitHubUserApiService $userApi = null
    ) {}

    public function category(): string
    {
        return 'github';
    }

    public function name(): string
    {
        return 'Estimate Commit Effort';
    }

    public function description(): string
    {
        return 'Analyze GitHub commits to estimate billable hours for a project. '.
            'Examines commit types (feature, bugfix, refactor, etc.), files changed, and lines of code. '.
            'Estimates assume a 10x senior engineer pace - conservative for professional billing. '.
            'Useful for suggesting time entries, validating estimates, or analyzing productivity.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'The project ID to analyze. Must have linked GitHub repos.',
                ],
                'project_name' => [
                    'type' => 'string',
                    'description' => 'Alternative: project name or slug to search for.',
                ],
                'include_commit_details' => [
                    'type' => 'boolean',
                    'description' => 'Include per-commit breakdown in results. Default: false (summary only).',
                ],
                'apply_senior_adjustment' => [
                    'type' => 'boolean',
                    'description' => 'Apply 1.25x senior engineer adjustment for conservative billing. Default: true.',
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $project = $this->resolveProject($params);

        if (! $project) {
            return [
                'success' => false,
                'error' => 'Project not found. Provide either project_id or project_name.',
            ];
        }

        if ($project->githubRepos->isEmpty()) {
            return [
                'success' => false,
                'error' => "Project '{$project->name}' has no linked GitHub repositories.",
                'suggestion' => 'Link a GitHub repo to this project first via Settings > GitHub Integration.',
            ];
        }

        $estimator = new CommitEffortEstimationService($this->github, $this->userApi);
        $result = $estimator->estimateProjectEffort($project, null);

        if (! $result['success']) {
            return $result;
        }

        $applyAdjustment = $params['apply_senior_adjustment'] ?? false;

        if ($applyAdjustment && $result['total_estimated_hours'] > 0) {
            $result = $this->applySeniorEngineerAdjustment($result);
        }

        $includeDetails = $params['include_commit_details'] ?? false;

        if (! $includeDetails) {
            foreach ($result['repos'] as &$repo) {
                unset($repo['commits']);
            }
        }

        $result['billing_guidance'] = $this->generateBillingGuidance($result);

        return $result;
    }

    private function resolveProject(array $params): ?Project
    {
        if (! empty($params['project_id'])) {
            return Project::with('githubRepos')->find($params['project_id']);
        }

        if (! empty($params['project_name'])) {
            return Project::with('githubRepos')
                ->where('name', 'like', '%'.$params['project_name'].'%')
                ->orWhere('slug', 'like', '%'.$params['project_name'].'%')
                ->first();
        }

        return null;
    }

    private function applySeniorEngineerAdjustment(array $result): array
    {
        $multiplier = self::SENIOR_ENGINEER_MULTIPLIER;

        $originalHours = $result['total_estimated_hours'];
        $result['total_estimated_hours'] = $this->roundToQuarter($originalHours * $multiplier);

        foreach ($result['breakdown'] as $type => $hours) {
            $result['breakdown'][$type] = $this->roundToQuarter($hours * $multiplier);
        }

        foreach ($result['repos'] as &$repo) {
            if (isset($repo['estimated_hours'])) {
                $repo['estimated_hours'] = $this->roundToQuarter($repo['estimated_hours'] * $multiplier);
            }

            if (isset($repo['commits'])) {
                foreach ($repo['commits'] as &$commit) {
                    if (isset($commit['hours'])) {
                        $commit['hours'] = $this->roundToQuarter($commit['hours'] * $multiplier);
                    }
                }
            }
        }

        $result['adjustment_applied'] = [
            'multiplier' => $multiplier,
            'original_hours' => $originalHours,
            'rationale' => 'Senior engineer pace (10x) with conservative billing adjustment',
        ];

        return $result;
    }

    private function roundToQuarter(float $hours): float
    {
        return round($hours * 4) / 4;
    }

    private function generateBillingGuidance(array $result): array
    {
        $hours = $result['total_estimated_hours'];
        $commits = $result['total_commits'];

        $guidance = [
            'estimated_hours' => $hours,
            'commit_count' => $commits,
        ];

        if ($commits === 0) {
            $guidance['recommendation'] = 'No commits found in the analysis period.';

            return $guidance;
        }

        $avgPerCommit = $commits > 0 ? round($hours / $commits, 2) : 0;
        $guidance['avg_hours_per_commit'] = $avgPerCommit;

        $breakdown = $result['breakdown'];
        $dominant = array_keys($breakdown, max($breakdown))[0] ?? 'mixed';
        $guidance['dominant_work_type'] = $dominant;

        if ($hours < 2) {
            $guidance['recommendation'] = "Minor work session: {$hours} hours of primarily {$dominant} work.";
        } elseif ($hours < 8) {
            $guidance['recommendation'] = "Solid work block: {$hours} hours. Consider billing as a half-day to full-day increment.";
        } elseif ($hours < 20) {
            $guidance['recommendation'] = "Significant effort: {$hours} hours across {$commits} commits. Bill as ~".ceil($hours / 8).' day(s).';
        } else {
            $guidance['recommendation'] = "Major development effort: {$hours} hours. Consider breaking into weekly invoices.";
        }

        if (! empty($result['last_time_entry'])) {
            $guidance['since_last_entry'] = $result['last_time_entry']['date'];
            $guidance['note'] = 'Estimates cover commits since last logged time entry.';
        } else {
            $guidance['note'] = 'No previous time entries found. Estimates cover all available commit history.';
        }

        return $guidance;
    }
}
