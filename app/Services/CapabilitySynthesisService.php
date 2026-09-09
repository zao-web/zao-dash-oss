<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\ContentSuggestion;
use App\Models\Email;
use App\Models\GitHubPullRequest;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\QboInvoice;
use App\Models\Task;

class CapabilitySynthesisService
{
    /**
     * Get all items that require human attention.
     * These are things that only a human can do.
     */
    public function getHumanRequiredItems(?int $userId = null): array
    {
        $items = collect();

        // 1. Pending Approvals - agents need human authorization
        $approvals = ApprovalRequest::with(['agentRun.agent'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($approvals as $approval) {
            $items->push([
                'type' => 'approval',
                'priority' => $this->mapRiskToPriority($approval->risk_level),
                'title' => "Approve: {$approval->action_type}",
                'description' => $approval->description,
                'agent' => $approval->agentRun?->agent?->name,
                'action_url' => '/approvals',
                'action_label' => 'Review',
                'created_at' => $approval->created_at,
                'metadata' => [
                    'approval_id' => $approval->id,
                    'risk_level' => $approval->risk_level ?? 'medium',
                ],
            ]);
        }

        // 2. Content Suggestions awaiting approval
        $suggestions = ContentSuggestion::where('status', 'pending')
            ->with('wordPressSite')
            ->latest()
            ->take(10)
            ->get();

        foreach ($suggestions as $suggestion) {
            $items->push([
                'type' => 'content_approval',
                'priority' => 'medium',
                'title' => "Review content: {$suggestion->title}",
                'description' => "AI-generated content for {$suggestion->wordPressSite?->name}",
                'agent' => 'Content Generator',
                'action_url' => '/settings/integrations',
                'action_label' => 'Review',
                'created_at' => $suggestion->created_at,
                'metadata' => ['suggestion_id' => $suggestion->id],
            ]);
        }

        // 3. Open Pull Requests needing review
        $prs = GitHubPullRequest::where('state', 'open')
            ->whereNull('merged_at')
            ->latest()
            ->take(10)
            ->get();

        foreach ($prs as $pr) {
            $items->push([
                'type' => 'pr_review',
                'priority' => 'medium',
                'title' => "Review PR: {$pr->title}",
                'description' => "#{$pr->number} in {$pr->repo?->full_name}",
                'agent' => null,
                'action_url' => $pr->html_url,
                'action_label' => 'Review on GitHub',
                'created_at' => $pr->created_at,
                'metadata' => ['pr_id' => $pr->id],
            ]);
        }

        // 4. Overdue invoices needing follow-up
        $overdueInvoices = QboInvoice::where('status', 'Overdue')
            ->where('balance', '>', 0)
            ->orderBy('due_date')
            ->take(10)
            ->get();

        foreach ($overdueInvoices as $invoice) {
            $items->push([
                'type' => 'overdue_invoice',
                'priority' => 'high',
                'title' => "Follow up: Invoice #{$invoice->doc_number}",
                'description' => "{$invoice->customer_name} - \${$invoice->balance} overdue",
                'agent' => null,
                'action_url' => '/settings/integrations', // Would link to QB
                'action_label' => 'Follow Up',
                'created_at' => $invoice->due_date,
                'metadata' => ['invoice_id' => $invoice->id],
            ]);
        }

        // 5. Leads needing follow-up (no contact in 7+ days)
        $staleLeads = Lead::whereIn('stage', ['new', 'qualified', 'proposal'])
            ->where(function ($q) {
                $q->whereNull('last_contacted_at')
                    ->orWhere('last_contacted_at', '<', now()->subDays(7));
            })
            ->orderBy('deal_value', 'desc')
            ->take(10)
            ->get();

        foreach ($staleLeads as $lead) {
            $items->push([
                'type' => 'lead_followup',
                'priority' => $lead->deal_value > 10000 ? 'high' : 'medium',
                'title' => "Follow up: {$lead->company_name}",
                'description' => "\${$lead->deal_value} deal - ".($lead->last_contacted_at ? "Last contact {$lead->last_contacted_at->diffForHumans()}" : 'Never contacted'),
                'agent' => null,
                'action_url' => '/leads',
                'action_label' => 'View Lead',
                'created_at' => $lead->created_at,
                'metadata' => ['lead_id' => $lead->id],
            ]);
        }

        $atRiskClients = Client::where('status', 'active')
            ->where('health_score', '<', 60)
            ->whereHas('projects', fn ($q) => $q->where('status', 'active'))
            ->orderBy('health_score')
            ->take(5)
            ->get();

        foreach ($atRiskClients as $client) {
            $healthFactors = $this->analyzeClientHealthFactors($client);

            $items->push([
                'type' => 'client_health',
                'priority' => $client->health_score < 40 ? 'critical' : 'high',
                'title' => "Client needs attention: {$client->name}",
                'description' => $healthFactors['summary'],
                'agent' => null,
                'action_url' => "/clients/{$client->slug}",
                'action_label' => 'View Client',
                'created_at' => now(),
                'metadata' => [
                    'client_id' => $client->id,
                    'health_score' => $client->health_score,
                    'factors' => $healthFactors['factors'],
                    'suggestions' => $healthFactors['suggestions'],
                ],
            ]);
        }

        $blockedTasks = Task::where('status', 'pending')
            ->whereNull('assigned_to')
            ->with('project.client')
            ->orderBy('priority')
            ->orderBy('due_date')
            ->take(10)
            ->get();

        foreach ($blockedTasks as $task) {
            $context = $task->project?->name ?? $task->project?->client?->name ?? 'No project';

            $items->push([
                'type' => 'task_decision',
                'priority' => $task->priority === 'urgent' ? 'high' : 'medium',
                'title' => "Assign: {$task->title}",
                'description' => "Unassigned task in {$context}",
                'agent' => null,
                'action_url' => "/tasks/{$task->id}?assign=1",
                'action_label' => 'Assign',
                'created_at' => $task->created_at,
                'metadata' => [
                    'task_id' => $task->id,
                    'project_id' => $task->project_id,
                    'client_id' => $task->project?->client_id,
                ],
            ]);
        }

        // 8. Slack action items needing attention (e.g., client requests with no project)
        $slackNotifications = Notification::where('type', 'slack_action_item')
            ->whereNull('dismissed_at')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        foreach ($slackNotifications as $notification) {
            $items->push([
                'type' => 'slack_action_item',
                'priority' => 'high',
                'title' => $notification->title,
                'description' => $notification->message,
                'agent' => 'Slack AI',
                'action_url' => $notification->action_url ?? '/tasks',
                'action_label' => $notification->action_label ?? 'View',
                'created_at' => $notification->created_at,
                'metadata' => $notification->metadata ?? [],
            ]);
        }

        // 9. Client emails requiring action
        $emailActions = Email::actionRequired()
            ->with('client')
            ->whereNotNull('client_id')
            ->orderByRaw("CASE urgency WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->orderBy('received_at', 'desc')
            ->take(10)
            ->get();

        foreach ($emailActions as $email) {
            $items->push([
                'type' => 'email_action',
                'priority' => $email->urgency === 'high' ? 'high' : 'medium',
                'title' => "Reply: {$email->subject}",
                'description' => $email->action_summary ?? "Email from {$email->from_name} ({$email->client?->name})",
                'agent' => 'Email AI',
                'action_url' => null,
                'action_label' => 'View Email',
                'created_at' => $email->received_at,
                'metadata' => [
                    'email_id' => $email->id,
                    'client_id' => $email->client_id,
                    'from' => $email->from_address,
                ],
            ]);
        }

        // Sort by priority then date
        return $items
            ->sortBy([
                fn ($a, $b) => $this->priorityOrder($a['priority']) <=> $this->priorityOrder($b['priority']),
                fn ($a, $b) => $b['created_at'] <=> $a['created_at'],
            ])
            ->values()
            ->toArray();
    }

    /**
     * Get a summary of system capabilities and what's automated vs manual.
     */
    public function getCapabilitySummary(): array
    {
        $agents = Agent::where('status', 'active')->get();

        return [
            'integrations' => $this->getIntegrationStatus(),
            'agents' => [
                'total' => $agents->count(),
                'active' => $agents->count(),
                'categories' => $agents->pluck('allowed_tools')->flatten()->unique()->values(),
            ],
            'automated' => [
                'email_parsing' => $agents->contains(fn ($a) => str_contains($a->system_prompt ?? '', 'email')),
                'calendar_sync' => true, // Google integration
                'time_tracking' => true, // Harvest integration
                'invoice_sync' => class_exists('App\Models\QuickBooksConnection'),
                'github_monitoring' => class_exists('App\Models\GitHubInstallation'),
                'slack_notifications' => class_exists('App\Models\SlackWorkspace'),
                'content_suggestions' => class_exists('App\Models\ContentSuggestion'),
            ],
            'manual_required' => [
                'approvals' => 'Agent actions requiring human authorization',
                'content_review' => 'AI-generated content before publishing',
                'client_calls' => 'Direct client communication',
                'strategic_decisions' => 'Business strategy and priorities',
                'contract_signing' => 'Legal document execution',
                'hiring' => 'Team member decisions',
            ],
        ];
    }

    /**
     * Generate a morning briefing of what needs human attention.
     */
    public function getMorningBriefing(): array
    {
        $humanItems = $this->getHumanRequiredItems();

        // Group by priority
        $critical = collect($humanItems)->where('priority', 'critical');
        $high = collect($humanItems)->where('priority', 'high');
        $medium = collect($humanItems)->where('priority', 'medium');

        // Count by type
        $byType = collect($humanItems)->groupBy('type')->map->count();

        return [
            'greeting' => $this->getTimeBasedGreeting(),
            'summary' => [
                'total_items' => count($humanItems),
                'critical' => $critical->count(),
                'high' => $high->count(),
                'medium' => $medium->count(),
            ],
            'by_type' => $byType,
            'top_priorities' => collect($humanItems)->take(5)->values()->toArray(),
            'recommendations' => $this->generateRecommendations($humanItems),
        ];
    }

    /**
     * Get what gaps exist in automation coverage.
     */
    public function getAutomationGaps(): array
    {
        $gaps = [];

        // Check for patterns that could be automated
        $pendingTasks = Task::where('status', 'pending')->count();
        if ($pendingTasks > 20) {
            $gaps[] = [
                'area' => 'Task Triage',
                'description' => 'Many pending tasks could use automated prioritization',
                'potential_agent' => 'Task Triage Agent',
            ];
        }

        $leads = Lead::whereIn('stage', ['new', 'qualified'])->count();
        if ($leads > 5) {
            $gaps[] = [
                'area' => 'Lead Nurturing',
                'description' => 'Leads could receive automated follow-up sequences',
                'potential_agent' => 'Lead Nurture Agent',
            ];
        }

        // Check for missing integrations that would help
        $gaps[] = [
            'area' => 'Document Generation',
            'description' => 'Proposals and contracts could be auto-generated',
            'potential_agent' => 'Document Generator Agent',
        ];

        return $gaps;
    }

    protected function getIntegrationStatus(): array
    {
        return [
            'google' => class_exists('App\Models\GoogleCredential'),
            'slack' => class_exists('App\Models\SlackWorkspace'),
            'github' => class_exists('App\Models\GitHubInstallation'),
            'harvest' => class_exists('App\Models\HarvestCredential'),
            'notion' => class_exists('App\Models\NotionConnection'),
            'wordpress' => class_exists('App\Models\WordPressSite'),
            'quickbooks' => class_exists('App\Models\QuickBooksConnection'),
        ];
    }

    protected function mapRiskToPriority(?string $risk): string
    {
        return match ($risk) {
            'high' => 'critical',
            'medium' => 'high',
            default => 'medium',
        };
    }

    protected function priorityOrder(string $priority): int
    {
        return match ($priority) {
            'critical' => 0,
            'high' => 1,
            'medium' => 2,
            default => 3,
        };
    }

    protected function getTimeBasedGreeting(): string
    {
        $hour = now()->hour;
        if ($hour < 12) {
            return 'Good morning';
        }
        if ($hour < 17) {
            return 'Good afternoon';
        }

        return 'Good evening';
    }

    protected function generateRecommendations(array $items): array
    {
        $recommendations = [];

        $criticalCount = collect($items)->where('priority', 'critical')->count();
        if ($criticalCount > 0) {
            $recommendations[] = "Handle {$criticalCount} critical approval(s) first - agents are waiting";
        }

        $overdueCount = collect($items)->where('type', 'overdue_invoice')->count();
        if ($overdueCount > 0) {
            $recommendations[] = "Follow up on {$overdueCount} overdue invoice(s) for cash flow";
        }

        $staleLeads = collect($items)->where('type', 'lead_followup')->count();
        if ($staleLeads > 0) {
            $recommendations[] = "Reach out to {$staleLeads} lead(s) who haven't heard from you";
        }

        if (empty($recommendations)) {
            $recommendations[] = 'No critical items - good time for strategic planning';
        }

        return $recommendations;
    }

    protected function analyzeClientHealthFactors(Client $client): array
    {
        $factors = [];
        $suggestions = [];
        $score = $client->health_score;

        $lastMeeting = $client->last_meeting_at;
        if (! $lastMeeting || $lastMeeting->lt(now()->subDays(30))) {
            $factors[] = 'No recent meetings';
            $suggestions[] = 'Schedule a check-in call';
        }

        $overdueInvoices = QboInvoice::where('customer_name', 'like', "%{$client->name}%")
            ->where('status', 'Overdue')
            ->count();
        if ($overdueInvoices > 0) {
            $factors[] = "{$overdueInvoices} overdue invoice(s)";
            $suggestions[] = 'Follow up on outstanding payments';
        }

        $openTasks = Task::whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->where('status', 'pending')
            ->where('due_date', '<', now())
            ->count();
        if ($openTasks > 0) {
            $factors[] = "{$openTasks} overdue task(s)";
            $suggestions[] = 'Address overdue deliverables';
        }

        $recentEmails = Email::where('client_id', $client->id)
            ->where('sentiment_label', 'negative')
            ->where('received_at', '>', now()->subDays(14))
            ->count();
        if ($recentEmails > 0) {
            $factors[] = 'Recent negative sentiment in emails';
            $suggestions[] = 'Review recent communications';
        }

        if (empty($factors)) {
            $factors[] = 'Low engagement signals';
            $suggestions[] = 'Review project status and reach out';
        }

        $summary = "Score: {$score}/100 - ".implode(', ', array_slice($factors, 0, 2));

        return [
            'factors' => $factors,
            'suggestions' => $suggestions,
            'summary' => $summary,
        ];
    }
}
