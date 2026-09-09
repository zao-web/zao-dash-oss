<?php

namespace App\Services\Slack;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\InteractionRequest;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\Task;

/**
 * Service for building Slack Block Kit responses.
 *
 * Centralizes all Block Kit formatting logic for consistent, rich Slack messages.
 */
class SlackBotResponseService
{
    /**
     * Build an ephemeral error response.
     *
     * @return array<string, mixed>
     */
    public function errorResponse(string $message): array
    {
        return [
            'response_type' => 'ephemeral',
            'text' => $message,
        ];
    }

    /**
     * Build a success response for task creation.
     *
     * @return array<string, mixed>
     */
    public function taskCreatedResponse(Task $task, Project $project): array
    {
        return [
            'response_type' => 'in_channel',
            'blocks' => [
                $this->section("✅ *Task created* in project *{$project->name}*"),
                $this->section("_{$task->title}_"),
                $this->context([
                    "Task ID: #{$task->id} | Priority: {$task->priority} | <".config('app.url')."/projects/{$project->id}|View in Dashboard>",
                ]),
            ],
        ];
    }

    /**
     * Build a success response for client note creation.
     *
     * @return array<string, mixed>
     */
    public function noteLoggedResponse(ClientNote $note, Client $client): array
    {
        return [
            'response_type' => 'in_channel',
            'blocks' => [
                $this->section("*Note logged* for client *{$client->name}*"),
                $this->section("_{$note->content}_"),
                $this->context([
                    "Note ID: #{$note->id} | <".config('app.url')."/clients/{$client->id}|View Client>",
                ]),
            ],
        ];
    }

    /**
     * Build a response listing available agents.
     *
     * @param  \Illuminate\Support\Collection<int, Agent>  $agents
     * @return array<string, mixed>
     */
    public function agentListResponse($agents): array
    {
        $agentList = $agents->map(fn (Agent $agent) => "- `{$agent->slug}` - {$agent->name}")->join("\n");

        return [
            'response_type' => 'ephemeral',
            'blocks' => [
                $this->header('Available Agents'),
                $this->section($agentList),
                $this->divider(),
                $this->context(['*Usage:* `/zao agent <slug> [task description]`']),
            ],
        ];
    }

    /**
     * Build a response for agent trigger.
     *
     * @return array<string, mixed>
     */
    public function agentTriggeredResponse(Agent $agent, AgentRun $run, ?string $task = null): array
    {
        $taskDescription = $task ? "\n\n_{$task}_" : '';

        return [
            'response_type' => 'in_channel',
            'blocks' => [
                $this->section("*Agent triggered:* {$agent->name}{$taskDescription}"),
                $this->context([
                    "Run ID: #{$run->id} | Status: {$run->status} | <".config('app.url')."/agents/{$agent->id}/runs/{$run->id}|View Progress>",
                ]),
            ],
        ];
    }

    /**
     * Build a system status response.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    public function statusResponse(array $metrics, ?SlackChannel $channel = null): array
    {
        $healthEmoji = $this->getHealthEmoji($metrics['health']);

        $blocks = [
            $this->header("{$healthEmoji} Zao Dash System Status"),
            [
                'type' => 'section',
                'fields' => [
                    $this->field('Health', $metrics['healthText']),
                    $this->field('Workspace', $metrics['workspaceName']),
                ],
            ],
            $this->divider(),
            $this->section('*Agents*'),
            [
                'type' => 'section',
                'fields' => [
                    $this->field('Active Agents', (string) $metrics['activeAgents']),
                    $this->field('Currently Running', (string) $metrics['runningAgents']),
                    $this->field('Failed (24h)', (string) $metrics['recentFailures']),
                    $this->field('Failed Jobs', (string) $metrics['failedJobs']),
                ],
            ],
            $this->divider(),
            $this->section('*Projects & Tasks*'),
            [
                'type' => 'section',
                'fields' => [
                    $this->field('Active Projects', (string) $metrics['activeProjects']),
                    $this->field('Pending Tasks', (string) $metrics['pendingTasks']),
                    $this->field('In Progress', (string) $metrics['inProgressTasks']),
                    $this->field('', ''),
                ],
            ],
        ];

        if ($channel) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*This Channel*');
            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    $this->field('Monitoring', $channel->monitoring_enabled ? 'Enabled' : 'Disabled'),
                    $this->field('Linked Client', $channel->client?->name ?? 'Not linked'),
                ],
            ];
        }

        $blocks[] = $this->context([
            '<'.config('app.url').'/dashboard|Open Dashboard> | <'.config('app.url').'/agents|View Agents> | <'.config('app.url').'/projects|View Projects>',
        ]);

        return [
            'response_type' => 'ephemeral',
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  array<string, mixed>  $briefing
     * @return array<int, array<string, mixed>>
     */
    public function focusBriefingBlocks(array $briefing, string $priorityFilter = 'all'): array
    {
        $summary = $briefing['summary'] ?? [];
        $recommendations = collect($briefing['recommendations'] ?? []);
        $topPriorities = collect($briefing['top_priorities'] ?? []);

        $title = $priorityFilter === 'all'
            ? 'What To Work On Today'
            : 'Priority Focus';

        $blocks = [
            $this->header($title),
            $this->section(($briefing['greeting'] ?? 'Hello').'. Here is the current human-needed work queue.'),
            [
                'type' => 'section',
                'fields' => [
                    $this->field('Total', (string) ($summary['total_items'] ?? $topPriorities->count())),
                    $this->field('Critical', (string) ($summary['critical'] ?? 0)),
                    $this->field('High', (string) ($summary['high'] ?? 0)),
                    $this->field('Medium', (string) ($summary['medium'] ?? 0)),
                ],
            ],
        ];

        if ($recommendations->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section("*Recommended next moves*\n".$recommendations->map(
                fn (string $recommendation) => "- {$recommendation}"
            )->implode("\n"));
        }

        $blocks[] = $this->divider();

        if ($topPriorities->isEmpty()) {
            $blocks[] = $this->section('No urgent human-required items are open right now. This is a good time for proactive planning or client outreach.');

            return $blocks;
        }

        $blocks[] = $this->section('*Top priorities*');

        foreach ($topPriorities->take(5) as $item) {
            $priorityLabel = strtoupper((string) ($item['priority'] ?? 'medium'));
            $description = (string) ($item['description'] ?? '');
            $actionUrl = (string) ($item['action_url'] ?? '');
            $actionLabel = (string) ($item['action_label'] ?? 'Open');
            $titleText = (string) ($item['title'] ?? 'Untitled item');

            $lines = ["*{$priorityLabel}* - {$titleText}"];

            if ($description !== '') {
                $lines[] = $description;
            }

            if ($actionUrl !== '') {
                $absoluteUrl = str_starts_with($actionUrl, 'http')
                    ? $actionUrl
                    : rtrim((string) config('app.url'), '/').'/'.ltrim($actionUrl, '/');
                $lines[] = "<{$absoluteUrl}|{$actionLabel}>";
            }

            $blocks[] = $this->section(implode("\n", $lines));
        }

        return $blocks;
    }

    /**
     * Build a confirmation dialog for destructive actions.
     *
     * @param  array<string, mixed>  $confirmButton
     * @return array<string, mixed>
     */
    public function confirmationResponse(string $title, string $message, array $confirmButton, string $cancelText = 'Cancel'): array
    {
        return [
            'response_type' => 'ephemeral',
            'blocks' => [
                $this->header($title),
                $this->section($message),
                [
                    'type' => 'actions',
                    'elements' => [
                        [
                            'type' => 'button',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => $confirmButton['text'],
                            ],
                            'style' => $confirmButton['style'] ?? 'primary',
                            'action_id' => $confirmButton['action_id'],
                            'value' => $confirmButton['value'] ?? '',
                        ],
                        [
                            'type' => 'button',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => $cancelText,
                            ],
                            'action_id' => 'cancel_action',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build an agent run status update for posting to a thread.
     *
     * @return array<string, mixed>
     */
    public function agentRunStatusUpdate(AgentRun $run): array
    {
        $statusEmoji = match ($run->status) {
            'running' => ':hourglass_flowing_sand:',
            'completed' => ':white_check_mark:',
            'failed' => ':x:',
            'cancelled' => ':no_entry:',
            default => ':question:',
        };

        $blocks = [
            $this->section("{$statusEmoji} *Agent Run Update*"),
            [
                'type' => 'section',
                'fields' => [
                    $this->field('Agent', $run->agent->name),
                    $this->field('Status', ucfirst($run->status)),
                ],
            ],
        ];

        if ($run->status === 'completed' && $run->output) {
            $output = is_array($run->output) ? ($run->output['summary'] ?? json_encode($run->output)) : $run->output;
            $truncated = strlen($output) > 500 ? substr($output, 0, 500).'...' : $output;
            $blocks[] = $this->section("*Output:*\n```{$truncated}```");
        }

        if ($run->status === 'failed' && $run->error) {
            $blocks[] = $this->section("*Error:*\n```{$run->error}```");
        }

        $blocks[] = $this->context([
            "Run ID: #{$run->id} | <".config('app.url')."/agents/{$run->agent_id}/runs/{$run->id}|View Details>",
        ]);

        return ['blocks' => $blocks];
    }

    /**
     * @param  iterable<int, AgentRun>  $runs
     * @return array<int, array<string, mixed>>
     */
    public function agentRunListBlocks(iterable $runs, string $projectName, string $filter = 'active'): array
    {
        $runItems = collect($runs);
        $title = ucfirst(str_replace('_', ' ', $filter)).' Runs';

        $blocks = [
            $this->header($title),
            $this->context(["Project: {$projectName}"]),
        ];

        if ($runItems->isEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section(':information_source: No agent runs matched this filter for the linked project.');

            return $blocks;
        }

        foreach ($runItems as $run) {
            $blocks[] = $this->divider();
            $blocks = array_merge($blocks, $this->agentRunDetailBlocks($run, null, false));
        }

        return $blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function agentRunDetailBlocks(AgentRun $run, ?string $notice = null, bool $includeHeader = true): array
    {
        $engineering = $run->context['engineering'] ?? [];
        $output = is_array($run->output) ? $run->output : [];
        $deliveryTarget = $engineering['delivery_target'] ?? null;
        $issueNumber = $engineering['issue_number'] ?? null;
        $repo = $engineering['repo'] ?? ($run->context['project']['github_repo'] ?? null);
        $branch = $output['branch'] ?? $output['branch_name'] ?? null;
        $workflowUrl = $output['workflow_url'] ?? null;
        $reviewUrl = $output['staging_url'] ?? $output['preview_url'] ?? null;
        $prUrl = $output['pr_url'] ?? $output['pull_request_url'] ?? null;

        $blocks = [];

        if ($notice) {
            $blocks[] = $this->section(":white_check_mark: {$notice}");
            $blocks[] = $this->divider();
        }

        if ($includeHeader) {
            $blocks[] = $this->header('Agent Run');
        }

        $headline = "*Run #{$run->id}* ".($run->agent?->name ?? 'Agent');

        if ($run->task) {
            $headline .= "\n_{$run->task}_";
        }

        $blocks[] = $this->section($headline);
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Status', ucfirst(str_replace('_', ' ', $run->status))),
                $this->field('Source', ucfirst(str_replace('_', ' ', (string) $run->invocation_source))),
                $this->field('Issue', $issueNumber ? '#'.$issueNumber : 'n/a'),
                $this->field('Target', $deliveryTarget ? ucfirst((string) $deliveryTarget) : 'General'),
            ],
        ];

        $contextElements = array_values(array_filter([
            $repo,
            $branch ? 'Branch: '.$branch : null,
            ! empty($output['pr_number']) ? 'PR #'.$output['pr_number'] : null,
            ! empty($output['deployment_status']) ? 'Deploy: '.str_replace('_', ' ', (string) $output['deployment_status']) : null,
        ]));

        if ($contextElements !== []) {
            $blocks[] = $this->context($contextElements);
        }

        if ($run->status === AgentRun::STATUS_FAILED && $run->error) {
            $error = strlen($run->error) > 300 ? substr($run->error, 0, 300).'...' : $run->error;
            $blocks[] = $this->section("*Error:*\n```{$error}```");
        }

        if ($run->status === AgentRun::STATUS_CANCELLED && $run->error) {
            $reason = strlen($run->error) > 300 ? substr($run->error, 0, 300).'...' : $run->error;
            $blocks[] = $this->section("*Cancellation:*\n```{$reason}```");
        }

        $actions = [];

        if ($prUrl) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Open PR',
                ],
                'url' => $prUrl,
            ];
        }

        if ($reviewUrl) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Open Review Build',
                ],
                'style' => 'primary',
                'url' => $reviewUrl,
            ];
        }

        if ($workflowUrl) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Open Workflow',
                ],
                'url' => $workflowUrl,
            ];
        }

        if ($run->agent_id) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'View Run',
                ],
                'url' => config('app.url')."/agents/{$run->agent_id}/runs/{$run->id}",
            ];
        }

        if (in_array($run->status, [
            'pending',
            AgentRun::STATUS_RUNNING,
            AgentRun::STATUS_PENDING_APPROVAL,
            AgentRun::STATUS_AWAITING_INPUT,
        ], true)) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Cancel Run',
                ],
                'style' => 'danger',
                'action_id' => 'cancel_agent_run',
                'value' => json_encode([
                    'run_id' => $run->id,
                ]),
            ];
        }

        if ($actions !== []) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => array_slice($actions, 0, 5),
            ];
        }

        return $blocks;
    }

    /**
     * Build a modal view for task creation.
     *
     * @param  array<int, Project>  $projects
     * @return array<string, mixed>
     */
    public function taskCreationModal(array $projects, ?string $prefillTitle = null, ?string $prefillDescription = null): array
    {
        $projectOptions = array_map(fn (array $p) => [
            'text' => ['type' => 'plain_text', 'text' => $p['name']],
            'value' => (string) $p['id'],
        ], $projects);

        return [
            'type' => 'modal',
            'callback_id' => 'create_task_modal',
            'title' => [
                'type' => 'plain_text',
                'text' => 'Create Task',
            ],
            'submit' => [
                'type' => 'plain_text',
                'text' => 'Create',
            ],
            'close' => [
                'type' => 'plain_text',
                'text' => 'Cancel',
            ],
            'blocks' => [
                [
                    'type' => 'input',
                    'block_id' => 'title_block',
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'task_title',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Enter task title'],
                        'initial_value' => $prefillTitle ?? '',
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Title'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'description_block',
                    'optional' => true,
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'task_description',
                        'multiline' => true,
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Enter description (optional)'],
                        'initial_value' => $prefillDescription ?? '',
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Description'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'project_block',
                    'element' => [
                        'type' => 'static_select',
                        'action_id' => 'project_select',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Select a project'],
                        'options' => $projectOptions,
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Project'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'priority_block',
                    'element' => [
                        'type' => 'static_select',
                        'action_id' => 'priority_select',
                        'initial_option' => [
                            'text' => ['type' => 'plain_text', 'text' => 'Medium'],
                            'value' => 'medium',
                        ],
                        'options' => [
                            ['text' => ['type' => 'plain_text', 'text' => 'Low'], 'value' => 'low'],
                            ['text' => ['type' => 'plain_text', 'text' => 'Medium'], 'value' => 'medium'],
                            ['text' => ['type' => 'plain_text', 'text' => 'High'], 'value' => 'high'],
                            ['text' => ['type' => 'plain_text', 'text' => 'Urgent'], 'value' => 'urgent'],
                        ],
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Priority'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'due_date_block',
                    'optional' => true,
                    'element' => [
                        'type' => 'datepicker',
                        'action_id' => 'due_date',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Select a date'],
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Due Date'],
                ],
            ],
        ];
    }

    /**
     * Build the Log Note modal for the global shortcut.
     *
     * @param  array<int, array{id: int, name: string}>  $clients
     * @return array<string, mixed>
     */
    public function logNoteModal(array $clients, ?string $prefillNote = null): array
    {
        $clientOptions = array_map(fn (array $c) => [
            'text' => ['type' => 'plain_text', 'text' => $c['name']],
            'value' => (string) $c['id'],
        ], $clients);

        return [
            'type' => 'modal',
            'callback_id' => 'log_note_modal',
            'title' => [
                'type' => 'plain_text',
                'text' => 'Log a Note',
            ],
            'submit' => [
                'type' => 'plain_text',
                'text' => 'Save Note',
            ],
            'close' => [
                'type' => 'plain_text',
                'text' => 'Cancel',
            ],
            'blocks' => [
                [
                    'type' => 'input',
                    'block_id' => 'client_block',
                    'element' => [
                        'type' => 'static_select',
                        'action_id' => 'client_select',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Select a client'],
                        'options' => $clientOptions,
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Client'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'note_block',
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'note_content',
                        'multiline' => true,
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Meeting notes, decisions, updates...'],
                        'initial_value' => $prefillNote ?? '',
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Note'],
                ],
            ],
        ];
    }

    /**
     * Build the Create Invoice modal for the global shortcut.
     *
     * @param  array<int, array{id: int, name: string}>  $clients
     * @param  array<int, array{id: int, name: string}>  $projects
     * @return array<string, mixed>
     */
    public function createInvoiceModal(array $clients, array $projects): array
    {
        $clientOptions = array_map(fn (array $c) => [
            'text' => ['type' => 'plain_text', 'text' => $c['name']],
            'value' => (string) $c['id'],
        ], $clients);

        $projectOptions = array_map(fn (array $p) => [
            'text' => ['type' => 'plain_text', 'text' => $p['name']],
            'value' => (string) $p['id'],
        ], $projects);

        $blocks = [
            [
                'type' => 'input',
                'block_id' => 'client_block',
                'element' => [
                    'type' => 'static_select',
                    'action_id' => 'client_select',
                    'placeholder' => ['type' => 'plain_text', 'text' => 'Select a client'],
                    'options' => $clientOptions,
                ],
                'label' => ['type' => 'plain_text', 'text' => 'Client'],
            ],
            [
                'type' => 'input',
                'block_id' => 'subject_block',
                'element' => [
                    'type' => 'plain_text_input',
                    'action_id' => 'invoice_subject',
                    'placeholder' => ['type' => 'plain_text', 'text' => 'e.g., Website redesign - March 2026'],
                ],
                'label' => ['type' => 'plain_text', 'text' => 'Subject'],
            ],
            [
                'type' => 'input',
                'block_id' => 'description_block',
                'element' => [
                    'type' => 'plain_text_input',
                    'action_id' => 'line_description',
                    'placeholder' => ['type' => 'plain_text', 'text' => 'Line item description'],
                ],
                'label' => ['type' => 'plain_text', 'text' => 'Line Item Description'],
            ],
            [
                'type' => 'input',
                'block_id' => 'amount_block',
                'element' => [
                    'type' => 'plain_text_input',
                    'action_id' => 'line_amount',
                    'placeholder' => ['type' => 'plain_text', 'text' => '1500.00'],
                ],
                'label' => ['type' => 'plain_text', 'text' => 'Amount ($)'],
            ],
            [
                'type' => 'input',
                'block_id' => 'quantity_block',
                'optional' => true,
                'element' => [
                    'type' => 'plain_text_input',
                    'action_id' => 'line_quantity',
                    'placeholder' => ['type' => 'plain_text', 'text' => '1'],
                    'initial_value' => '1',
                ],
                'label' => ['type' => 'plain_text', 'text' => 'Quantity'],
            ],
            [
                'type' => 'input',
                'block_id' => 'due_days_block',
                'optional' => true,
                'element' => [
                    'type' => 'plain_text_input',
                    'action_id' => 'due_days',
                    'placeholder' => ['type' => 'plain_text', 'text' => '30'],
                    'initial_value' => '30',
                ],
                'label' => ['type' => 'plain_text', 'text' => 'Payment Terms (days)'],
            ],
        ];

        if (count($projectOptions) > 0) {
            array_splice($blocks, 1, 0, [[
                'type' => 'input',
                'block_id' => 'project_block',
                'optional' => true,
                'element' => [
                    'type' => 'static_select',
                    'action_id' => 'project_select',
                    'placeholder' => ['type' => 'plain_text', 'text' => 'Select a project (optional)'],
                    'options' => $projectOptions,
                ],
                'label' => ['type' => 'plain_text', 'text' => 'Project'],
            ]]);
        }

        return [
            'type' => 'modal',
            'callback_id' => 'create_invoice_modal',
            'title' => [
                'type' => 'plain_text',
                'text' => 'Create Invoice',
            ],
            'submit' => [
                'type' => 'plain_text',
                'text' => 'Create',
            ],
            'close' => [
                'type' => 'plain_text',
                'text' => 'Cancel',
            ],
            'blocks' => $blocks,
        ];
    }

    /**
     * Build the Create Lead modal for the message shortcut.
     *
     * @return array<string, mixed>
     */
    public function createLeadModal(?string $prefillCompany = null, ?string $prefillNotes = null): array
    {
        return [
            'type' => 'modal',
            'callback_id' => 'create_lead_modal',
            'title' => [
                'type' => 'plain_text',
                'text' => 'Create Lead',
            ],
            'submit' => [
                'type' => 'plain_text',
                'text' => 'Create',
            ],
            'close' => [
                'type' => 'plain_text',
                'text' => 'Cancel',
            ],
            'blocks' => [
                [
                    'type' => 'input',
                    'block_id' => 'company_block',
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'company_name',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Company name'],
                        'initial_value' => $prefillCompany ?? '',
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Company Name'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'contact_block',
                    'optional' => true,
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'contact_name',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Primary contact name'],
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Contact Name'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'email_block',
                    'optional' => true,
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'contact_email',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'email@company.com'],
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Email'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'website_block',
                    'optional' => true,
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'lead_website',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'https://company.com'],
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Website'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'deal_value_block',
                    'optional' => true,
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'deal_value',
                        'placeholder' => ['type' => 'plain_text', 'text' => '5000'],
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Estimated Deal Value ($)'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'notes_block',
                    'optional' => true,
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'lead_notes',
                        'multiline' => true,
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Notes about this lead...'],
                        'initial_value' => $prefillNotes ?? '',
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Notes'],
                ],
            ],
        ];
    }

    /**
     * Build a message with interactive buttons for actions.
     *
     * @param  array<int, array<string, mixed>>  $buttons
     * @return array<string, mixed>
     */
    public function messageWithActions(string $text, array $buttons): array
    {
        $elements = array_map(fn (array $btn) => [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => $btn['text']],
            'action_id' => $btn['action_id'],
            'value' => $btn['value'] ?? '',
            'style' => $btn['style'] ?? null,
        ], $buttons);

        $elements = array_map(function ($el) {
            if ($el['style'] === null) {
                unset($el['style']);
            }

            return $el;
        }, $elements);

        return [
            'blocks' => [
                $this->section($text),
                [
                    'type' => 'actions',
                    'elements' => array_values($elements),
                ],
            ],
        ];
    }

    /**
     * Build an action item detected notification.
     *
     * @return array<string, mixed>
     */
    public function actionItemDetectedResponse(string $summary, float $confidence, ?string $suggestedAction = null): array
    {
        $confidenceEmoji = $confidence >= 0.7 ? ':high_brightness:' : ($confidence >= 0.4 ? ':low_brightness:' : ':grey_question:');
        $confidenceText = round($confidence * 100).'%';

        $blocks = [
            $this->section("{$confidenceEmoji} *Potential Action Item Detected*"),
            $this->section($summary),
            $this->context(["Confidence: {$confidenceText}"]),
        ];

        if ($suggestedAction) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section("*Suggested Action:* {$suggestedAction}");
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Create Task'],
                        'action_id' => 'create_task_from_action_item',
                        'style' => 'primary',
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Dismiss'],
                        'action_id' => 'dismiss_action_item',
                    ],
                ],
            ];
        }

        return [
            'response_type' => 'ephemeral',
            'blocks' => $blocks,
        ];
    }

    /**
     * Create a header block.
     *
     * @return array<string, mixed>
     */
    public function header(string $text): array
    {
        return [
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => $text,
            ],
        ];
    }

    /**
     * Create a section block with mrkdwn text.
     *
     * @return array<string, mixed>
     */
    public function section(string $text): array
    {
        return [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => $text,
            ],
        ];
    }

    /**
     * Create a context block.
     *
     * @param  array<int, string>  $elements
     * @return array<string, mixed>
     */
    public function context(array $elements): array
    {
        return [
            'type' => 'context',
            'elements' => array_map(fn (string $text) => [
                'type' => 'mrkdwn',
                'text' => $text,
            ], $elements),
        ];
    }

    /**
     * Create a divider block.
     *
     * @return array<string, string>
     */
    public function divider(): array
    {
        return ['type' => 'divider'];
    }

    /**
     * Create a field for section blocks.
     *
     * @return array<string, string>
     */
    public function field(string $label, string $value): array
    {
        return [
            'type' => 'mrkdwn',
            'text' => "*{$label}:*\n{$value}",
        ];
    }

    /**
     * Build an interactive agent question message for Slack.
     *
     * This creates a Slack message with interactive buttons/inputs
     * that allow users to respond to agent questions.
     *
     * @return array<string, mixed>
     */
    public function interactionQuestionMessage(InteractionRequest $interaction): array
    {
        $agentRun = $interaction->agentRun;
        $agent = $agentRun?->agent;
        $agentName = $agent?->name ?? 'Agent';

        $blocks = [
            $this->header(':robot_face: Agent Needs Your Input'),
            $this->section("*{$agentName}* is asking:"),
            $this->section($interaction->question_content),
        ];

        // Add context info
        $context = $interaction->context ?? [];
        if (isset($context['header'])) {
            $blocks[] = $this->context([$context['header']]);
        }

        $blocks[] = $this->divider();

        // Build response interface based on question type
        if ($interaction->question_type === InteractionRequest::TYPE_CONFIRM) {
            // Yes/No buttons
            $blocks[] = [
                'type' => 'actions',
                'block_id' => "interaction_{$interaction->id}",
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Yes'],
                        'action_id' => 'interaction_respond_yes',
                        'value' => json_encode([
                            'interaction_id' => $interaction->id,
                            'response' => 'yes',
                        ]),
                        'style' => 'primary',
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'No'],
                        'action_id' => 'interaction_respond_no',
                        'value' => json_encode([
                            'interaction_id' => $interaction->id,
                            'response' => 'no',
                        ]),
                    ],
                ],
            ];
        } elseif ($interaction->question_type === InteractionRequest::TYPE_SELECT && $interaction->options) {
            // Build select menu with options
            $options = array_map(fn ($opt) => [
                'text' => ['type' => 'plain_text', 'text' => $opt['label'] ?? $opt],
                'value' => json_encode([
                    'interaction_id' => $interaction->id,
                    'response' => $opt['label'] ?? $opt,
                ]),
            ], $interaction->options);

            $blocks[] = [
                'type' => 'actions',
                'block_id' => "interaction_{$interaction->id}",
                'elements' => [
                    [
                        'type' => 'static_select',
                        'action_id' => 'interaction_respond_select',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Choose an option...'],
                        'options' => array_slice($options, 0, 100), // Slack limit
                    ],
                ],
            ];

            // Add descriptions for options if available
            $descriptions = [];
            foreach ($interaction->options as $opt) {
                if (isset($opt['description']) && $opt['description']) {
                    $descriptions[] = "• *{$opt['label']}*: {$opt['description']}";
                }
            }
            if (! empty($descriptions)) {
                $blocks[] = $this->section(implode("\n", $descriptions));
            }
        } else {
            // Text input - show a link to respond in dashboard
            // Slack doesn't support inline text input, so we provide a button
            $blocks[] = $this->section(
                ':keyboard: This question requires a text response. '.
                'Click below to respond:'
            );
            $blocks[] = [
                'type' => 'actions',
                'block_id' => "interaction_{$interaction->id}",
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Open Response Dialog'],
                        'action_id' => 'interaction_open_modal',
                        'value' => json_encode([
                            'interaction_id' => $interaction->id,
                        ]),
                        'style' => 'primary',
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Skip'],
                        'action_id' => 'interaction_skip',
                        'value' => json_encode([
                            'interaction_id' => $interaction->id,
                        ]),
                    ],
                ],
            ];
        }

        // Add expiration warning
        $expiresIn = $interaction->expires_at->diffForHumans();
        $blocks[] = $this->context([
            ":alarm_clock: Expires {$expiresIn} | Run ID: #{$agentRun?->id}",
        ]);

        return [
            'blocks' => $blocks,
        ];
    }

    /**
     * Build a modal for text input responses to agent questions.
     *
     * @return array<string, mixed>
     */
    public function interactionTextInputModal(InteractionRequest $interaction, ?int $threadContextId = null): array
    {
        $agentName = $interaction->agentRun?->agent?->name ?? 'Agent';

        return [
            'type' => 'modal',
            'callback_id' => 'interaction_text_response',
            'private_metadata' => json_encode(array_filter([
                'interaction_id' => $interaction->id,
                'context_id' => $threadContextId,
            ], fn ($value) => ! is_null($value) && $value !== '')),
            'title' => [
                'type' => 'plain_text',
                'text' => 'Respond to Agent',
            ],
            'submit' => [
                'type' => 'plain_text',
                'text' => 'Submit Response',
            ],
            'close' => [
                'type' => 'plain_text',
                'text' => 'Cancel',
            ],
            'blocks' => [
                $this->section("*{$agentName}* is asking:"),
                $this->section($interaction->question_content),
                $this->divider(),
                [
                    'type' => 'input',
                    'block_id' => 'response_block',
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'response_input',
                        'multiline' => true,
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Type your response...'],
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Your Response'],
                ],
            ],
        ];
    }

    /**
     * Build a response acknowledged message.
     *
     * @param  string|null  $response  The response that was given
     * @return array<string, mixed>
     */
    public function interactionResponseAcknowledged(?InteractionRequest $interaction = null, ?string $response = null): array
    {
        $contextElements = $interaction
            ? ["Interaction #{$interaction->id} | Responded via Slack"]
            : ['Response submitted'];

        return [
            'blocks' => [
                $this->section(
                    ":white_check_mark: *Response recorded!*\n".
                    'The agent will continue with your input.'
                ),
                $this->context($contextElements),
            ],
        ];
    }

    /**
     * Build an interaction expired message.
     *
     * @return array<string, mixed>
     */
    public function interactionExpiredMessage(?InteractionRequest $interaction = null): array
    {
        $contextElements = $interaction
            ? ["Interaction #{$interaction->id}"]
            : ['This interaction is no longer available'];

        return [
            'blocks' => [
                $this->section(
                    ":hourglass: *This interaction has expired.*\n".
                    'The agent may have timed out or continued without input.'
                ),
                $this->context($contextElements),
            ],
        ];
    }

    /**
     * Build an interaction already responded message.
     *
     * @return array<string, mixed>
     */
    public function interactionAlreadyRespondedMessage(?InteractionRequest $interaction = null): array
    {
        if (! $interaction) {
            return [
                'blocks' => [
                    $this->section(':information_source: *Already responded*'),
                ],
            ];
        }

        $respondedBy = $interaction->respondedBy?->name ?? 'someone';
        $via = $interaction->responded_via ?? 'dashboard';

        return [
            'blocks' => [
                $this->section(
                    ":information_source: *Already responded*\n".
                    "{$respondedBy} already responded to this question via {$via}."
                ),
                $this->context([
                    "Interaction #{$interaction->id} | Response: _{$interaction->response}_",
                ]),
            ],
        ];
    }

    /**
     * Get health emoji based on status.
     */
    private function getHealthEmoji(string $health): string
    {
        return match ($health) {
            'healthy' => ':large_green_circle:',
            'warning' => ':large_yellow_circle:',
            'degraded' => ':red_circle:',
            default => ':white_circle:',
        };
    }

    /**
     * Build confirmation blocks for agent trigger.
     *
     * @param  array<string, mixed>  $action
     * @return array<int, array<string, mixed>>
     */
    public function agentConfirmationBlocks(array $action): array
    {
        $agentSlug = $action['agent_slug'] ?? '';
        $task = $action['task'] ?? 'No specific task provided';

        $agent = Agent::where('slug', $agentSlug)->first();
        $agentName = $agent?->name ?? $agentSlug;

        return [
            $this->section(":robot_face: *Trigger Agent: {$agentName}*\n\nTask: _{$task}_"),
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Confirm'],
                        'style' => 'primary',
                        'action_id' => 'confirm_agent_trigger',
                        'value' => json_encode([
                            'agent_slug' => $agentSlug,
                            'task' => $action['task'] ?? null,
                        ]),
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Cancel'],
                        'action_id' => 'cancel_action',
                    ],
                ],
            ],
        ];
    }

    /**
     * Build confirmation blocks for issue-driven engineering runs.
     *
     * @param  array<string, mixed>  $action
     * @return array<int, array<string, mixed>>
     */
    public function engineeringIssueConfirmationBlocks(array $action): array
    {
        $issueNumber = (int) ($action['issue_number'] ?? 0);
        $deliveryTarget = $action['delivery_target'] ?? 'pr';
        $branchPreference = $action['branch_preference'] ?? null;

        $details = [
            ":github: *Work GitHub issue #{$issueNumber}*",
            'Agent: _Dev Agent_',
            'Delivery: _'.($deliveryTarget === 'staging' ? 'staging review flow' : 'pull request review').'_',
        ];

        if ($branchPreference) {
            $details[] = "Branch preference: _{$branchPreference}_";
        }

        return [
            $this->section(implode("\n", $details)),
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Start Work'],
                        'style' => 'primary',
                        'action_id' => 'confirm_agent_trigger',
                        'value' => json_encode([
                            'agent_slug' => 'dev-agent',
                            'issue_number' => $issueNumber,
                            'delivery_target' => $deliveryTarget,
                            'branch_preference' => $branchPreference,
                            'task' => $action['task'] ?? null,
                        ]),
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Cancel'],
                        'action_id' => 'cancel_action',
                    ],
                ],
            ],
        ];
    }

    /**
     * Build preview blocks for a SOW import confirmation.
     *
     * @param  array<string, mixed>  $preview
     * @return array<int, array<string, mixed>>
     */
    public function sowImportPreviewBlocks(array $preview, string $pendingActionId, int $contextId): array
    {
        $client = $preview['client'] ?? [];
        $project = $preview['project'] ?? [];
        $milestones = collect($preview['milestones'] ?? []);
        $invoices = collect($preview['invoices'] ?? []);
        $billing = $preview['billing']['recurring_invoice'] ?? [];

        $blocks = [
            $this->header('SOW Import Preview'),
            $this->section('*Client:* '.($client['name'] ?? 'Unknown')."\n*Project:* ".($project['name'] ?? 'Unknown')),
            [
                'type' => 'section',
                'fields' => [
                    $this->field('Type', ucfirst((string) ($project['type'] ?? 'project'))),
                    $this->field('Milestones', (string) $milestones->count()),
                    $this->field('Invoices', (string) $invoices->count()),
                    $this->field('Link Channel', ! empty($preview['link_to_channel']) ? 'Yes' : 'No'),
                ],
            ],
        ];

        if (! empty($project['start_date']) || ! empty($project['end_date'])) {
            $blocks[] = $this->context(array_values(array_filter([
                ! empty($project['start_date']) ? 'Start: '.$project['start_date'] : null,
                ! empty($project['end_date']) ? 'End: '.$project['end_date'] : null,
                ! empty($project['budget']) ? 'Budget: $'.number_format((float) $project['budget'], 2) : null,
            ])));
        }

        if ($milestones->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Milestones*');

            foreach ($milestones->take(3) as $milestone) {
                $taskCount = count((array) ($milestone['tasks'] ?? []));
                $lines = ["*{$milestone['name']}* - {$taskCount} task".($taskCount === 1 ? '' : 's')];

                if (! empty($milestone['due_date'])) {
                    $lines[] = 'Due: '.$milestone['due_date'];
                }

                $blocks[] = $this->section(implode("\n", $lines));
            }
        }

        if ($invoices->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Invoices*');

            foreach ($invoices->take(3) as $invoice) {
                $summary = collect([
                    $invoice['subject'] ?? 'Invoice',
                    isset($invoice['amount']) ? '$'.number_format((float) $invoice['amount'], 2) : null,
                    $invoice['due_date'] ?? null,
                ])->filter()->implode(' | ');

                $blocks[] = $this->section($summary);
            }
        }

        if (! empty($billing)) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Recurring Billing*');
            $blocks[] = $this->context(array_values(array_filter([
                ! empty($billing['enabled']) ? 'Enabled' : 'Disabled',
                isset($billing['amount']) ? '$'.number_format((float) $billing['amount'], 2) : null,
                isset($billing['day']) ? 'Day '.$billing['day'] : null,
                ! empty($billing['auto_send']) ? 'Auto-send' : null,
            ])));
        }

        $blocks[] = $this->divider();
        $blocks[] = $this->section('Confirm to create the client, project, milestones, tasks, and invoices from this SOW.');
        $blocks[] = [
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Confirm'],
                    'style' => 'primary',
                    'action_id' => 'confirm_sow_import',
                    'value' => json_encode([
                        'context_id' => $contextId,
                        'pending_action_id' => $pendingActionId,
                    ]),
                ],
                [
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Cancel'],
                    'action_id' => 'cancel_sow_import',
                    'value' => json_encode([
                        'context_id' => $contextId,
                        'pending_action_id' => $pendingActionId,
                    ]),
                ],
            ],
        ];

        return $blocks;
    }

    /**
     * Build blocks for a completed SOW import.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function sowImportResultBlocks(array $result, ?string $notice = null): array
    {
        $parsed = $result['parsed'] ?? [];
        $provisioned = $result['provisioned'] ?? [];
        $summary = $provisioned['summary'] ?? [];

        $blocks = [];

        if ($notice) {
            $blocks[] = $this->section(':white_check_mark: '.$notice);
            $blocks[] = $this->divider();
        }

        $blocks[] = $this->header('SOW Imported');
        $blocks[] = $this->section('*'.($parsed['client_name'] ?? 'Unknown Client').'* / *'.($parsed['project_name'] ?? 'Unknown Project').'*');
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Contacts', (string) ($summary['contacts_created'] ?? 0)),
                $this->field('Milestones', (string) ($summary['milestones_created'] ?? 0)),
                $this->field('Tasks', (string) ($summary['tasks_created'] ?? 0)),
                $this->field('Invoices', (string) ($summary['invoices_created'] ?? 0)),
            ],
        ];

        $projectId = $provisioned['project_id'] ?? null;
        $clientId = $provisioned['client_id'] ?? null;

        $contextItems = array_values(array_filter([
            $clientId ? '<'.config('app.url')."/clients/{$clientId}|Open Client>" : null,
            $projectId ? '<'.config('app.url')."/projects/{$projectId}|Open Project>" : null,
            ! empty($provisioned['channel_link']) ? 'Linked to Slack channel' : null,
        ]));

        if ($contextItems !== []) {
            $blocks[] = $this->context($contextItems);
        }

        return $blocks;
    }

    /**
     * Build blocks for task created response.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function taskCreatedBlocks(array $result): array
    {
        return [
            $this->section(':white_check_mark: *Task Created*'),
            $this->section($result['message'] ?? 'Task created successfully'),
            $this->context(['<'.config('app.url')."/tasks/{$result['task_id']}|View Task>"]),
        ];
    }

    /**
     * Build blocks for a client summary or detail payload.
     *
     * @param  array<string, mixed>  $client
     * @return array<int, array<string, mixed>>
     */
    public function clientSummaryBlocks(array $client, ?string $notice = null): array
    {
        $blocks = [];

        if ($notice) {
            $blocks[] = $this->section(':white_check_mark: '.$notice);
            $blocks[] = $this->divider();
        }

        $blocks[] = $this->header('Client #'.($client['id'] ?? '?'));
        $blocks[] = $this->section('*'.($client['name'] ?? 'Unknown Client').'*'.(! empty($client['description']) ? "\n{$client['description']}" : ''));
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Status', ucfirst((string) ($client['status'] ?? 'unknown'))),
                $this->field('Health', isset($client['health_score']) ? (string) $client['health_score'] : 'n/a'),
                $this->field('Website', $client['website'] ?? 'Not set'),
                $this->field('Projects', (string) (count($client['projects'] ?? []) ?: ($client['projects_count'] ?? 0))),
            ],
        ];

        $projects = collect($client['projects'] ?? [])->take(5);
        if ($projects->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Projects*');
            $blocks[] = $this->section($projects->map(function (array $project): string {
                $status = $project['status'] ?? 'unknown';

                return "- *#{$project['id']}* {$project['name']} _({$status})_";
            })->implode("\n"));
        }

        $recentNotes = collect($client['recent_notes'] ?? [])->take(3);
        if ($recentNotes->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Recent Notes*');
            $blocks[] = $this->section($recentNotes->map(function (array $note): string {
                $suffix = collect([
                    $note['user'] ?? null,
                    $note['created_at'] ?? null,
                ])->filter()->implode(' • ');

                return '- '.$note['content'].($suffix !== '' ? " _({$suffix})_" : '');
            })->implode("\n"));
        }

        if (isset($client['id'])) {
            $blocks[] = $this->context([
                '<'.config('app.url')."/clients/{$client['id']}|Open Client>",
            ]);
        }

        return $blocks;
    }

    /**
     * Build blocks for a list of clients.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function clientListBlocks(array $result, string $title = 'Clients'): array
    {
        $clients = collect($result['clients'] ?? [])->values();
        $blocks = [$this->header($title)];

        if ($clients->isEmpty()) {
            $blocks[] = $this->section('No matching clients found.');

            return $blocks;
        }

        foreach ($clients->take(10) as $client) {
            $summary = collect([
                $client['status'] ?? null,
                isset($client['health_score']) ? 'health '.$client['health_score'] : null,
                isset($client['projects_count']) ? $client['projects_count'].' projects' : null,
                ! empty($client['primary_contact']) ? 'primary: '.$client['primary_contact'] : null,
            ])->filter()->implode(' | ');

            $blocks[] = $this->section("*#{$client['id']}* {$client['name']}".($summary !== '' ? "\n_{$summary}_" : ''));
        }

        $blocks[] = $this->context(['Showing up to 10 clients']);

        return $blocks;
    }

    /**
     * Build blocks for a project summary or detail payload.
     *
     * @param  array<string, mixed>  $project
     * @return array<int, array<string, mixed>>
     */
    public function projectSummaryBlocks(array $project, ?string $notice = null): array
    {
        $blocks = [];

        if ($notice) {
            $blocks[] = $this->section(':white_check_mark: '.$notice);
            $blocks[] = $this->divider();
        }

        $clientName = is_array($project['client'] ?? null)
            ? ($project['client']['name'] ?? 'Unknown Client')
            : ($project['client'] ?? 'Unknown Client');
        $taskSummary = $project['task_summary'] ?? [];

        $blocks[] = $this->header('Project #'.($project['id'] ?? '?'));
        $blocks[] = $this->section('*'.($project['name'] ?? 'Unknown Project').'*'.(! empty($project['description']) ? "\n{$project['description']}" : ''));
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Status', ucfirst(str_replace('_', ' ', (string) ($project['status'] ?? 'unknown')))),
                $this->field('Client', $clientName),
                $this->field('Type', ucfirst((string) ($project['type'] ?? 'unknown'))),
                $this->field('Budget', isset($project['budget']) && $project['budget'] !== null ? '$'.number_format((float) $project['budget'], 2) : 'n/a'),
            ],
        ];

        if ($taskSummary !== []) {
            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    $this->field('Pending', (string) ($taskSummary['pending'] ?? 0)),
                    $this->field('In Progress', (string) ($taskSummary['in_progress'] ?? 0)),
                    $this->field('Review', (string) ($taskSummary['review'] ?? 0)),
                    $this->field('Completed', (string) ($taskSummary['completed'] ?? 0)),
                ],
            ];
        }

        if (! empty($project['github_repo'])) {
            $blocks[] = $this->context(["GitHub: `{$project['github_repo']}`"]);
        }

        $tasks = collect($project['tasks'] ?? [])->take(5);
        if ($tasks->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Tasks*');
            $blocks[] = $this->section($tasks->map(function (array $task): string {
                $summary = collect([
                    $task['status'] ?? null,
                    $task['priority'] ?? null,
                    ! empty($task['assignee']) ? 'assignee: '.$task['assignee'] : null,
                ])->filter()->implode(' | ');

                return "- *#{$task['id']}* {$task['title']}".($summary !== '' ? " _({$summary})_" : '');
            })->implode("\n"));
        }

        if (isset($project['id'])) {
            $blocks[] = $this->context([
                '<'.config('app.url')."/projects/{$project['id']}|Open Project>",
            ]);
        }

        return $blocks;
    }

    /**
     * Build blocks for a list of projects.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function projectListBlocks(array $result, string $title = 'Projects'): array
    {
        $projects = collect($result['projects'] ?? [])->values();
        $blocks = [$this->header($title)];

        if ($projects->isEmpty()) {
            $blocks[] = $this->section('No matching projects found.');

            return $blocks;
        }

        foreach ($projects->take(10) as $project) {
            $summary = collect([
                $project['status'] ?? null,
                ! empty($project['client']) ? 'client: '.$project['client'] : null,
                isset($project['completion_pct']) ? $project['completion_pct'].'% complete' : null,
            ])->filter()->implode(' | ');

            $blocks[] = $this->section("*#{$project['id']}* {$project['name']}".($summary !== '' ? "\n_{$summary}_" : ''));
        }

        $blocks[] = $this->context(['Showing up to 10 projects']);

        return $blocks;
    }

    /**
     * Build blocks for a lead summary.
     *
     * @param  array<string, mixed>  $lead
     * @return array<int, array<string, mixed>>
     */
    public function leadSummaryBlocks(array $lead, ?string $notice = null): array
    {
        $blocks = [];

        if ($notice) {
            $blocks[] = $this->section(':white_check_mark: '.$notice);
            $blocks[] = $this->divider();
        }

        $blocks[] = $this->header('Lead #'.($lead['id'] ?? '?'));
        $blocks[] = $this->section('*'.($lead['company_name'] ?? 'Unknown Lead').'*');
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Stage', ucfirst((string) ($lead['new_stage'] ?? $lead['stage'] ?? 'unknown'))),
                $this->field('Source', $lead['source'] ?? 'n/a'),
                $this->field('Deal Value', isset($lead['deal_value']) && $lead['deal_value'] !== null ? '$'.number_format((float) $lead['deal_value'], 2) : 'n/a'),
                $this->field('Probability', isset($lead['probability']) && $lead['probability'] !== null ? $lead['probability'].'%' : 'n/a'),
            ],
        ];

        $contextItems = array_values(array_filter([
            ! empty($lead['contact_name']) ? 'Contact: '.$lead['contact_name'] : null,
            ! empty($lead['contact_email']) ? 'Email: '.$lead['contact_email'] : null,
            ! empty($lead['website']) ? 'Website: '.$lead['website'] : null,
        ]));

        if ($contextItems !== []) {
            $blocks[] = $this->context($contextItems);
        }

        $blocks[] = $this->context([
            '<'.config('app.url').'/leads|Open Leads>',
        ]);

        return $blocks;
    }

    /**
     * Build blocks for a list of leads.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function leadListBlocks(array $result, string $title = 'Leads'): array
    {
        $leads = collect($result['leads'] ?? [])->values();
        $blocks = [$this->header($title)];

        if ($leads->isEmpty()) {
            $blocks[] = $this->section('No matching leads found.');

            return $blocks;
        }

        foreach ($leads->take(10) as $lead) {
            $summary = collect([
                $lead['stage'] ?? null,
                isset($lead['deal_value']) && $lead['deal_value'] !== null ? '$'.number_format((float) $lead['deal_value'], 2) : null,
                isset($lead['probability']) && $lead['probability'] !== null ? $lead['probability'].'%' : null,
                ! empty($lead['assignee']) ? 'assignee: '.$lead['assignee'] : null,
            ])->filter()->implode(' | ');

            $blocks[] = $this->section("*#{$lead['id']}* {$lead['company_name']}".($summary !== '' ? "\n_{$summary}_" : ''));
        }

        $stats = $result['stats'] ?? [];
        $blocks[] = $this->context(array_values(array_filter([
            isset($result['pipeline_value']) ? 'Pipeline: $'.number_format((float) $result['pipeline_value'], 2) : null,
            isset($result['weighted_value']) ? 'Weighted: $'.number_format((float) $result['weighted_value'], 2) : null,
            isset($stats['overdue_count']) ? 'Overdue: '.$stats['overdue_count'] : null,
        ])));

        return $blocks;
    }

    /**
     * Build blocks for an invoice summary.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<int, array<string, mixed>>
     */
    public function invoiceSummaryBlocks(array $invoice, ?string $notice = null): array
    {
        $blocks = [];

        if ($notice) {
            $blocks[] = $this->section(':white_check_mark: '.$notice);
            $blocks[] = $this->divider();
        }

        $label = $invoice['number'] ?? ($invoice['id'] ?? '?');

        $blocks[] = $this->header("Invoice #{$label}");
        $blocks[] = $this->section('*'.($invoice['client_name'] ?? $invoice['client'] ?? 'Unknown Client').'*'.(! empty($invoice['subject']) ? "\n{$invoice['subject']}" : ''));
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Status', ucfirst((string) ($invoice['status'] ?? 'unknown'))),
                $this->field('Total', isset($invoice['total']) ? '$'.number_format((float) $invoice['total'], 2) : (isset($invoice['amount']) ? '$'.number_format((float) $invoice['amount'], 2) : 'n/a')),
                $this->field('Due Date', $invoice['due_date'] ?? 'n/a'),
                $this->field('Lines', isset($invoice['line_count']) ? (string) $invoice['line_count'] : 'n/a'),
            ],
        ];

        $contextItems = array_values(array_filter([
            isset($invoice['public_url']) ? 'Public: '.$invoice['public_url'] : null,
            isset($invoice['id']) ? '<'.config('app.url')."/invoices/{$invoice['id']}|Open Invoice>" : null,
        ]));

        if ($contextItems !== []) {
            $blocks[] = $this->context($contextItems);
        }

        return $blocks;
    }

    /**
     * Build blocks for a list of invoices.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function invoiceListBlocks(array $result, string $title = 'Invoices'): array
    {
        $invoices = collect($result['invoices'] ?? [])->values();
        $blocks = [$this->header($title)];

        if ($invoices->isEmpty()) {
            $blocks[] = $this->section('No matching invoices found.');

            return $blocks;
        }

        foreach ($invoices->take(10) as $invoice) {
            $summary = collect([
                $invoice['status'] ?? null,
                isset($invoice['amount_due']) ? '$'.number_format((float) $invoice['amount_due'], 2).' due' : null,
                $invoice['due_date'] ?? null,
                ! empty($invoice['is_overdue']) ? 'overdue' : null,
            ])->filter()->implode(' | ');

            $blocks[] = $this->section("*#{$invoice['number']}* ".($invoice['client'] ?? 'Unknown Client').($summary !== '' ? "\n_{$summary}_" : ''));
        }

        $stats = $result['stats'] ?? [];
        $blocks[] = $this->context(array_values(array_filter([
            isset($stats['total_outstanding']) ? 'Outstanding: $'.number_format((float) $stats['total_outstanding'], 2) : null,
            isset($stats['overdue_count']) ? 'Overdue: '.$stats['overdue_count'] : null,
            isset($stats['pending_count']) ? 'Pending: '.$stats['pending_count'] : null,
        ])));

        return $blocks;
    }

    /**
     * Build blocks for a website project summary.
     *
     * @param  array<string, mixed>  $project
     * @return array<int, array<string, mixed>>
     */
    public function websiteProjectSummaryBlocks(array $project, ?string $notice = null): array
    {
        $blocks = [];

        if ($notice) {
            $blocks[] = $this->section(':white_check_mark: '.$notice);
            $blocks[] = $this->divider();
        }

        $blocks[] = $this->header('Website Project #'.($project['id'] ?? '?'));
        $blocks[] = $this->section('*'.($project['name'] ?? 'Unknown Website Project').'*');
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Status', ucfirst((string) ($project['current_status'] ?? $project['status'] ?? 'unknown'))),
                $this->field('Type', ucfirst((string) ($project['project_type'] ?? 'unknown'))),
                $this->field('Progress', isset($project['overall_progress']) ? $project['overall_progress'].'%' : 'n/a'),
                $this->field('Domain', $project['domain'] ?? 'n/a'),
            ],
        ];

        $contextItems = array_values(array_filter([
            ! empty($project['staging_url']) ? 'Staging: '.$project['staging_url'] : null,
            ! empty($project['production_url']) ? 'Production: '.$project['production_url'] : null,
            isset($project['id']) ? '<'.config('app.url')."/website-builder/{$project['id']}|Open Website Project>" : null,
        ]));

        if ($contextItems !== []) {
            $blocks[] = $this->context($contextItems);
        }

        if (! empty($project['last_error'])) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section("*Last Error*\n{$project['last_error']}");
        }

        return $blocks;
    }

    /**
     * Build blocks for a list of website projects.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function websiteProjectListBlocks(array $result, string $title = 'Website Projects'): array
    {
        $projects = collect($result['website_projects'] ?? [])->values();
        $blocks = [$this->header($title)];

        if ($projects->isEmpty()) {
            $blocks[] = $this->section('No matching website projects found.');

            return $blocks;
        }

        foreach ($projects->take(10) as $project) {
            $summary = collect([
                $project['status'] ?? null,
                $project['project_type'] ?? null,
                isset($project['overall_progress']) ? $project['overall_progress'].'%' : null,
                $project['domain'] ?? null,
            ])->filter()->implode(' | ');

            $blocks[] = $this->section("*#{$project['id']}* {$project['name']}".($summary !== '' ? "\n_{$summary}_" : ''));
        }

        $blocks[] = $this->context(['Showing up to 10 website projects']);

        return $blocks;
    }

    /**
     * Build blocks for search results.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function searchResultsBlocks(array $result): array
    {
        $summary = $result['message'] ?? ('Found '.($result['total_results'] ?? 0).' results');
        $blocks = [$this->section(":mag: *Search Results*\n{$summary}")];

        foreach ($result['results'] ?? [] as $type => $items) {
            if (empty($items)) {
                continue;
            }

            $itemText = collect($items)->map(function ($item) {
                $name = $item['title'] ?? $item['name'];
                $status = $item['status'] ?? '';

                return "- {$name}".($status ? " _({$status})_" : '');
            })->join("\n");

            $blocks[] = $this->section('*'.ucfirst($type)."*\n{$itemText}");
        }

        return $blocks;
    }

    /**
     * Build blocks for channel/project status.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function channelStatusBlocks(array $result): array
    {
        $status = $result['status'] ?? [];
        $blocks = [$this->header('Channel Status')];

        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Channel', $status['channel'] ?? 'Unknown'),
                $this->field('Monitoring', ($status['monitoring'] ?? false) ? 'Enabled' : 'Disabled'),
            ],
        ];

        if (isset($status['client'])) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section("*Client: {$status['client']['name']}*");

            if (! empty($status['projects'])) {
                foreach ($status['projects'] as $project) {
                    $blocks[] = [
                        'type' => 'section',
                        'fields' => [
                            $this->field('Project', $project['name']),
                            $this->field('Pending', (string) ($project['pending_tasks'] ?? 0)),
                            $this->field('In Progress', (string) ($project['in_progress_tasks'] ?? 0)),
                        ],
                    ];
                }
            }
        }

        return $blocks;
    }

    /**
     * Build blocks for a Slack thread execution summary.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function threadSummaryBlocks(array $result): array
    {
        $thread = $result['thread'] ?? [];
        $channelContext = $result['channel_context'] ?? [];
        $client = $channelContext['client'] ?? null;
        $project = $channelContext['project'] ?? null;
        $staging = $result['staging'] ?? null;
        $task = $result['task'] ?? null;
        $run = $result['run'] ?? null;
        $approvals = collect($result['approvals'] ?? []);
        $pendingInteraction = $result['pending_interaction'] ?? null;
        $invoice = $result['invoice'] ?? null;
        $websiteProject = $result['website_project'] ?? null;

        $blocks = [
            $this->header('Thread Status'),
            [
                'type' => 'section',
                'fields' => [
                    $this->field('State', ucfirst(str_replace('_', ' ', (string) ($thread['current_state'] ?? 'idle')))),
                    $this->field('Last Activity', $thread['last_interaction_at'] ?? 'n/a'),
                    $this->field('Pending Actions', (string) ($thread['pending_actions_count'] ?? 0)),
                    $this->field('History', (string) ($thread['history_count'] ?? 0)),
                ],
            ],
        ];

        if ($client || $project) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Linked Context*');
            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    $this->field('Client', $client['name'] ?? 'Not linked'),
                    $this->field('Project', $project['name'] ?? 'Not linked'),
                    $this->field('Project Status', ucfirst((string) ($project['status'] ?? 'n/a'))),
                    $this->field('Repo', $project['github_repo'] ?? 'n/a'),
                ],
            ];
        }

        if (is_array($staging) && $staging !== []) {
            $deployment = $staging['deployment'] ?? [];
            $summary = $staging['summary'] ?? [];
            $workflow = $staging['workflow_run'] ?? [];
            $statusLabel = ucfirst(str_replace('_', ' ', (string) ($workflow['deployment_status'] ?? ($staging['ready_to_publish'] ?? false ? 'ready' : 'needs setup'))));

            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Staging Workflow*');
            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    $this->field('Workflow', $deployment['workflow_identifier'] ?? 'Not configured'),
                    $this->field('Branch', $deployment['publish_branch'] ?? 'n/a'),
                    $this->field('Secrets Ready', ($summary['configured'] ?? 0).'/'.($summary['required'] ?? 0)),
                    $this->field('Status', $statusLabel),
                ],
            ];

            $stagingContext = array_values(array_filter([
                ! empty($deployment['staging_url']) ? 'Staging: '.$deployment['staging_url'] : null,
                ! empty($workflow['workflow_run_id']) ? 'Run #'.$workflow['workflow_run_id'] : null,
                ! empty($workflow['workflow_status']) ? 'Workflow: '.str_replace('_', ' ', (string) $workflow['workflow_status']) : null,
            ]));

            if ($stagingContext !== []) {
                $blocks[] = $this->context($stagingContext);
            }

            if (! empty($workflow['workflow_failure_summary'])) {
                $blocks[] = $this->section('Failure details: '.$workflow['workflow_failure_summary']);
            }

            $stagingActions = [];

            $firstMissingSecret = collect($staging['required_secrets'] ?? [])->firstWhere('sync_status', 'missing');
            $stagingActions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Add Secret',
                ],
                'action_id' => 'staging_open_secret_modal',
                'value' => json_encode(array_filter([
                    'secret_name' => $firstMissingSecret['name'] ?? null,
                ], fn ($value) => $value !== null && $value !== '')),
                'style' => 'primary',
            ];

            if (($summary['configured'] ?? 0) > 0) {
                $stagingActions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Sync Secrets',
                    ],
                    'action_id' => 'staging_sync_secrets',
                    'value' => 'sync-staging-secrets',
                ];
            }

            if ($staging['ready_to_publish'] ?? false) {
                $stagingActions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Publish to Staging',
                    ],
                    'action_id' => 'staging_publish',
                    'value' => 'publish-staging',
                ];
            }

            if (! empty($workflow['workflow_url'])) {
                $stagingActions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Workflow',
                    ],
                    'url' => $workflow['workflow_url'],
                ];
            }

            if (! empty($workflow['staging_url'])) {
                $stagingActions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Staging',
                    ],
                    'url' => $workflow['staging_url'],
                ];
            }

            if ($stagingActions !== []) {
                $blocks[] = [
                    'type' => 'actions',
                    'elements' => array_slice($stagingActions, 0, 5),
                ];
            }
        }

        if ($task) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section("*Task #{$task['id']}* {$task['title']}");
            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    $this->field('Status', ucfirst(str_replace('_', ' ', (string) ($task['status'] ?? 'unknown')))),
                    $this->field('Priority', ucfirst((string) ($task['priority'] ?? 'unknown'))),
                    $this->field('Client', $task['client_name'] ?? 'n/a'),
                    $this->field('Project', $task['project_name'] ?? 'n/a'),
                ],
            ];
        }

        if ($run) {
            $blocks[] = $this->divider();
            $headline = "*Run #{$run['id']}* ".($run['agent_name'] ?? 'Agent');

            if (! empty($run['task'])) {
                $headline .= "\n_{$run['task']}_";
            }

            $blocks[] = $this->section($headline);
            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    $this->field('Status', ucfirst(str_replace('_', ' ', (string) ($run['status'] ?? 'unknown')))),
                    $this->field('Issue', isset($run['issue_number']) ? '#'.$run['issue_number'] : 'n/a'),
                    $this->field('Branch', $run['branch'] ?? 'n/a'),
                    $this->field('Deploy', ucfirst((string) ($run['deployment_status'] ?? 'n/a'))),
                ],
            ];

            $runContext = array_values(array_filter([
                $run['repo'] ?? null,
                isset($run['pr_number']) ? 'PR #'.$run['pr_number'] : null,
                ! empty($run['workflow_name']) ? 'Workflow: '.$run['workflow_name'] : null,
                ! empty($run['awaiting_input']) ? 'Awaiting input' : null,
                ! empty($run['needs_approval']) ? 'Pending approval' : null,
            ]));

            if ($runContext !== []) {
                $blocks[] = $this->context($runContext);
            }

            $actions = [];

            if (! empty($run['pr_url'])) {
                $actions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open PR',
                    ],
                    'url' => $run['pr_url'],
                ];
            }

            if (! empty($run['review_url'])) {
                $actions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Review Build',
                    ],
                    'style' => 'primary',
                    'url' => $run['review_url'],
                ];
            }

            if (! empty($run['workflow_url'])) {
                $actions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Open Workflow',
                    ],
                    'url' => $run['workflow_url'],
                ];
            }

            if (! empty($run['id']) && ! empty($run['agent_id'])) {
                $actions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'View Run',
                    ],
                    'url' => config('app.url')."/agents/{$run['agent_id']}/runs/{$run['id']}",
                ];
            }

            $canControlReviewDeploy = ! empty($run['id'])
                && ! empty($run['repo'])
                && ! empty($run['pr_number'])
                && ! empty($run['branch'])
                && ! empty($thread['context_id']);
            $deploymentStatus = strtolower((string) ($run['deployment_status'] ?? ''));
            $canRetryReviewDeploy = in_array($deploymentStatus, ['failed', 'failure', 'cancelled', 'timed_out', 'action_required'], true);

            if ($canControlReviewDeploy && ! in_array($deploymentStatus, ['queued', 'requested', 'in_progress', 'deploying'], true)) {
                $actions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $deploymentStatus === 'deployed' ? 'Redeploy Review Build' : 'Promote to Staging',
                    ],
                    'action_id' => 'thread_request_review_deploy',
                    'value' => json_encode([
                        'run_id' => $run['id'],
                        'context_id' => $thread['context_id'],
                    ]),
                ];
            }

            if ($canControlReviewDeploy && $canRetryReviewDeploy && ! empty($run['workflow_run_id'])) {
                $actions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Retry Review Deploy',
                    ],
                    'action_id' => 'thread_retry_review_deploy',
                    'value' => json_encode([
                        'run_id' => $run['id'],
                        'context_id' => $thread['context_id'],
                    ]),
                ];
            }

            if (! empty($run['id']) && ! empty($thread['context_id']) && in_array($run['status'] ?? '', ['completed', 'failed'], true)) {
                $actions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => ($run['status'] ?? '') === 'failed' ? 'Retry Run' : 'Run Again',
                    ],
                    'action_id' => 'thread_retry_run',
                    'value' => json_encode([
                        'run_id' => $run['id'],
                        'context_id' => $thread['context_id'],
                    ]),
                ];
            }

            if (! empty($run['id']) && ! empty($thread['context_id']) && in_array($run['status'] ?? '', ['pending', 'running', 'pending_approval', 'awaiting_input'], true)) {
                $actions[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Cancel Run',
                    ],
                    'style' => 'danger',
                    'action_id' => 'cancel_agent_run',
                    'value' => json_encode([
                        'run_id' => $run['id'],
                        'context_id' => $thread['context_id'],
                    ]),
                ];
            }

            if ($actions !== []) {
                $blocks[] = [
                    'type' => 'actions',
                    'elements' => $actions,
                ];
            }
        }

        if ($pendingInteraction) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Awaiting Response*');
            $blocks[] = $this->section($pendingInteraction['question_content'] ?? 'The agent is waiting for input.');

            $interactionElements = $this->threadInteractionElements($pendingInteraction, $thread['context_id'] ?? null);

            if ($interactionElements !== []) {
                $blocks[] = [
                    'type' => 'actions',
                    'elements' => $interactionElements,
                ];
            }

            if (! empty($pendingInteraction['expires_at'])) {
                $blocks[] = $this->context([
                    'Expires: '.$pendingInteraction['expires_at'],
                ]);
            }
        }

        if ($approvals->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Pending Approvals*');

            foreach ($approvals as $approval) {
                $summary = collect([
                    $approval['risk_level'] ?? null,
                    $approval['action_type'] ?? null,
                    $approval['expires_at'] ?? null,
                ])->filter()->implode(' | ');

                $blocks[] = $this->section("*#{$approval['id']}* {$approval['description']}".($summary !== '' ? "\n_{$summary}_" : ''));

                if (! empty($thread['context_id'])) {
                    $blocks[] = [
                        'type' => 'actions',
                        'elements' => [
                            [
                                'type' => 'button',
                                'text' => [
                                    'type' => 'plain_text',
                                    'text' => 'Approve',
                                ],
                                'style' => 'primary',
                                'action_id' => 'approval_approve',
                                'value' => json_encode([
                                    'approval_id' => $approval['id'],
                                    'context_id' => $thread['context_id'],
                                ]),
                            ],
                            [
                                'type' => 'button',
                                'text' => [
                                    'type' => 'plain_text',
                                    'text' => 'Reject',
                                ],
                                'style' => 'danger',
                                'action_id' => 'approval_reject',
                                'value' => json_encode([
                                    'approval_id' => $approval['id'],
                                    'context_id' => $thread['context_id'],
                                ]),
                            ],
                        ],
                    ];
                }
            }
        }

        if ($websiteProject) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Website Project #'.($websiteProject['id'] ?? '?').'* '.($websiteProject['name'] ?? 'Unknown'));
            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    $this->field('Status', ucfirst((string) ($websiteProject['current_status'] ?? $websiteProject['status'] ?? 'unknown'))),
                    $this->field('Type', ucfirst((string) ($websiteProject['project_type'] ?? 'unknown'))),
                    $this->field('Domain', $websiteProject['domain'] ?? 'n/a'),
                    $this->field('Progress', isset($websiteProject['overall_progress']) ? $websiteProject['overall_progress'].'%' : 'n/a'),
                ],
            ];
        }

        if ($invoice) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section("*Invoice #{$invoice['number']}* ".($invoice['client_name'] ?? 'Unknown Client'));
            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    $this->field('Status', ucfirst((string) ($invoice['status'] ?? 'unknown'))),
                    $this->field('Total', isset($invoice['total']) ? '$'.number_format((float) $invoice['total'], 2) : 'n/a'),
                    $this->field('Amount Due', isset($invoice['amount_due']) ? '$'.number_format((float) $invoice['amount_due'], 2) : 'n/a'),
                    $this->field('Due Date', $invoice['due_date'] ?? 'n/a'),
                ],
            ];
        }

        $pendingTypes = collect($thread['pending_action_types'] ?? [])->filter()->values();
        if ($pendingTypes->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->context([
                'Pending action types: '.$pendingTypes->implode(', '),
            ]);
        }

        return $blocks;
    }

    /**
     * Build thread update blocks for a staging workflow run.
     *
     * @param  array<string, mixed>  $workflow
     * @return array<int, array<string, mixed>>
     */
    public function stagingWorkflowUpdateBlocks(array $workflow): array
    {
        $status = (string) ($workflow['workflow_status'] ?? '');
        $deploymentStatus = (string) ($workflow['deployment_status'] ?? '');
        $headline = match ($status) {
            'requested' => ':hourglass_flowing_sand: *GitHub Actions queued the staging publish for this project.*',
            'in_progress' => ':hammer_and_wrench: *GitHub Actions is running the staging publish now.*',
            default => in_array($deploymentStatus, ['deployed', 'success'], true)
                ? ':rocket: *Staging publish completed and is ready for review.*'
                : ':x: *GitHub Actions reported a staging publish failure.*',
        };

        $blocks = [
            $this->section($headline),
            [
                'type' => 'section',
                'fields' => [
                    $this->field('Repo', (string) ($workflow['repo'] ?? 'Unknown')),
                    $this->field('Branch', (string) ($workflow['branch'] ?? 'Unknown')),
                    $this->field('Workflow', (string) ($workflow['workflow_identifier'] ?? 'Unknown')),
                    $this->field('Status', ucfirst(str_replace('_', ' ', $deploymentStatus !== '' ? $deploymentStatus : $status))),
                ],
            ],
        ];

        if (! empty($workflow['workflow_failure_summary'])) {
            $blocks[] = $this->section('Failure details: '.$workflow['workflow_failure_summary']);
        } elseif (! empty($workflow['staging_url']) && in_array($deploymentStatus, ['deployed', 'success'], true)) {
            $blocks[] = $this->section('Review URL: <'.$workflow['staging_url'].'|'.$workflow['staging_url'].'>');
        }

        $actions = [];

        if (! empty($workflow['workflow_url'])) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Open Workflow',
                ],
                'url' => $workflow['workflow_url'],
            ];
        }

        if (! empty($workflow['staging_url']) && in_array($deploymentStatus, ['deployed', 'success'], true)) {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Open Staging',
                ],
                'style' => 'primary',
                'url' => $workflow['staging_url'],
            ];
        }

        if ($actions !== []) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => $actions,
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $interaction
     * @return array<int, array<string, mixed>>
     */
    private function threadInteractionElements(array $interaction, ?int $contextId = null): array
    {
        $interactionId = (int) ($interaction['id'] ?? 0);

        if (! $interactionId) {
            return [];
        }

        $payload = array_filter([
            'interaction_id' => $interactionId,
            'context_id' => $contextId,
        ], fn ($value) => ! is_null($value) && $value !== '');

        return match ($interaction['question_type'] ?? null) {
            InteractionRequest::TYPE_CONFIRM => [
                [
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Yes'],
                    'style' => 'primary',
                    'action_id' => 'interaction_respond_yes',
                    'value' => json_encode(array_merge($payload, ['response' => 'yes'])),
                ],
                [
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'No'],
                    'action_id' => 'interaction_respond_no',
                    'value' => json_encode(array_merge($payload, ['response' => 'no'])),
                ],
                [
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Skip'],
                    'action_id' => 'interaction_skip',
                    'value' => json_encode($payload),
                ],
            ],
            InteractionRequest::TYPE_SELECT => [
                [
                    'type' => 'static_select',
                    'action_id' => 'interaction_respond_select',
                    'placeholder' => ['type' => 'plain_text', 'text' => 'Choose an option...'],
                    'options' => collect($interaction['options'] ?? [])
                        ->take(100)
                        ->map(fn ($option) => [
                            'text' => [
                                'type' => 'plain_text',
                                'text' => $option['label'] ?? $option,
                            ],
                            'value' => json_encode(array_merge($payload, [
                                'response' => $option['label'] ?? $option,
                            ])),
                        ])->values()->all(),
                ],
            ],
            default => [
                [
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Respond'],
                    'style' => 'primary',
                    'action_id' => 'interaction_open_modal',
                    'value' => json_encode($payload),
                ],
                [
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Skip'],
                    'action_id' => 'interaction_skip',
                    'value' => json_encode($payload),
                ],
            ],
        };
    }

    /**
     * Build blocks for an operations context summary.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function operationsContextBlocks(array $result): array
    {
        $workspace = $result['workspace'] ?? [];
        $channel = $result['channel'] ?? [];
        $client = $result['client'] ?? null;
        $project = $result['project'] ?? null;
        $integrations = $result['integrations'] ?? [];

        $blocks = [
            $this->header('Channel Operations Context'),
            [
                'type' => 'section',
                'fields' => [
                    $this->field('Workspace', $workspace['name'] ?? 'Unknown'),
                    $this->field('Channel', '#'.($channel['name'] ?? 'unknown')),
                    $this->field('Classification', ucfirst($channel['classification'] ?? 'unknown')),
                    $this->field('Monitoring', ($channel['monitoring_enabled'] ?? false) ? 'Enabled' : 'Disabled'),
                ],
            ],
        ];

        if ($client) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section("*Client:* {$client['name']}");
            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    $this->field('Status', ucfirst($client['status'] ?? 'unknown')),
                    $this->field('Health', (string) ($client['health_score'] ?? 'n/a')),
                ],
            ];
        }

        if ($project) {
            $summary = $project['task_summary'] ?? [];

            $blocks[] = $this->divider();
            $blocks[] = $this->section("*Project:* {$project['name']}");
            $blocks[] = [
                'type' => 'section',
                'fields' => [
                    $this->field('Status', ucfirst($project['status'] ?? 'unknown')),
                    $this->field('Type', ucfirst($project['type'] ?? 'unknown')),
                    $this->field('Pending', (string) ($summary['pending'] ?? 0)),
                    $this->field('In Progress', (string) ($summary['in_progress'] ?? 0)),
                ],
            ];

            if (! empty($project['github_repo'])) {
                $blocks[] = $this->context(["GitHub: `{$project['github_repo']}`"]);
            }
        }

        $githubRepos = $integrations['github_repos'] ?? [];
        $pmConnections = $integrations['pm_connections'] ?? [];
        $wordPressSites = $integrations['wordpress_sites'] ?? [];

        $blocks[] = $this->divider();
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('GitHub Repos', (string) count($githubRepos)),
                $this->field('PM Connections', (string) count($pmConnections)),
                $this->field('WordPress Sites', (string) count($wordPressSites)),
                $this->field('Recent Agent Runs', (string) count($result['recent_agent_runs'] ?? [])),
            ],
        ];

        return $blocks;
    }

    /**
     * Build blocks for Slack watchlist updates.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function watchlistResultBlocks(array $result): array
    {
        $items = collect($result['items'] ?? []);
        $unresolved = collect($result['unresolved'] ?? []);
        $ambiguous = collect($result['ambiguous'] ?? []);
        $operation = (string) ($result['operation'] ?? 'track');

        $title = match ($operation) {
            'untrack' => 'Removed From Watchlist',
            'clear' => 'Watchlist Cleared',
            default => 'Slack Watchlist Updated',
        };

        $blocks = [
            $this->header($title),
            $this->section((string) ($result['message'] ?? 'Updated watchlist.')),
        ];

        if ($items->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Saved channels*');

            foreach ($items->take(10) as $item) {
                $lines = [
                    '*'.($item['label'] ?? $item['channel_name'] ?? 'Unknown channel').'*',
                ];

                $meta = array_filter([
                    ! empty($item['client']['name']) ? 'Client: '.$item['client']['name'] : null,
                    ! empty($item['project']['name']) ? 'Project: '.$item['project']['name'] : null,
                    ! empty($item['channel_id']) ? '#'.$item['channel_id'] : null,
                ]);

                if ($meta !== []) {
                    $lines[] = implode(' | ', $meta);
                }

                $blocks[] = $this->section(implode("\n", $lines));
            }
        }

        if ($unresolved->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Unresolved*');
            $blocks[] = $this->section($unresolved->map(fn (array $item): string => '- '.$item['query'])->implode("\n"));
        }

        if ($ambiguous->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Needs disambiguation*');

            foreach ($ambiguous->take(5) as $item) {
                $lines = ["*{$item['query']}*"];
                $candidates = collect($item['candidates'] ?? [])->take(5)->map(function (array $candidate): string {
                    $parts = [$candidate['channel_name'] ?? 'unknown'];

                    if (! empty($candidate['client']['name'])) {
                        $parts[] = $candidate['client']['name'];
                    }

                    if (! empty($candidate['project']['name'])) {
                        $parts[] = $candidate['project']['name'];
                    }

                    return '- '.implode(' | ', $parts);
                })->implode("\n");

                if ($candidates !== '') {
                    $lines[] = $candidates;
                }

                $blocks[] = $this->section(implode("\n", $lines));
            }
        }

        return $blocks;
    }

    /**
     * Build blocks for the current watchlist.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function watchlistBlocks(array $result): array
    {
        $items = collect($result['items'] ?? []);

        $blocks = [
            $this->header('Slack Watchlist'),
            $this->section((string) ($result['message'] ?? 'Your private Slack watchlist.')),
        ];

        if ($items->isEmpty()) {
            $blocks[] = $this->section('You are not currently tracking any channels.');

            return $blocks;
        }

        foreach ($items->take(10) as $item) {
            $lines = [
                '*'.($item['label'] ?? $item['channel_name'] ?? 'Unknown channel').'*',
            ];

            $meta = array_filter([
                ! empty($item['client']['name']) ? 'Client: '.$item['client']['name'] : null,
                ! empty($item['project']['name']) ? 'Project: '.$item['project']['name'] : null,
                ! empty($item['channel_id']) ? '#'.$item['channel_id'] : null,
            ]);

            if ($meta !== []) {
                $lines[] = implode(' | ', $meta);
            }

            $blocks[] = $this->section(implode("\n", $lines));
        }

        return $blocks;
    }

    /**
     * Build blocks for a list of tasks.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function taskListBlocks(array $result, string $title = 'Tasks'): array
    {
        $tasks = collect($result['tasks'] ?? [])->values();
        $blocks = [$this->header($title)];

        if ($tasks->isEmpty()) {
            $blocks[] = $this->section('No matching tasks found.');

            return $blocks;
        }

        foreach ($tasks->take(10) as $task) {
            $suffix = collect([
                $task['status'] ?? null,
                $task['priority'] ?? null,
                $task['assignee'] ? "assignee: {$task['assignee']}" : null,
            ])->filter()->implode(' | ');

            $blocks[] = $this->section("*#{$task['id']}* {$task['title']}".($suffix ? "\n_{$suffix}_" : ''));
            $actions = $this->taskLifecycleActions($task);

            if ($actions !== []) {
                $blocks[] = [
                    'type' => 'actions',
                    'elements' => $actions,
                ];
            }
        }

        $blocks[] = $this->context(['Showing up to 10 tasks']);

        return $blocks;
    }

    /**
     * Build blocks for a single task with lifecycle actions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function taskDetailBlocks(Task $task, ?string $notice = null): array
    {
        $task->loadMissing(['project.client', 'latestAgentTask.agent']);

        $assignee = $task->assignee_info['name'] ?? 'Unassigned';
        $agentStatus = $task->latestAgentTask
            ? "{$task->latestAgentTask->agent?->name} ({$task->latestAgentTask->status})"
            : 'None';

        $blocks = [];

        if ($notice) {
            $blocks[] = $this->section($notice);
            $blocks[] = $this->divider();
        }

        $blocks[] = $this->header("Task #{$task->id}");
        $blocks[] = $this->section("*{$task->title}*".($task->description ? "\n{$task->description}" : ''));
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Status', str_replace('_', ' ', $task->status)),
                $this->field('Priority', $task->priority),
                $this->field('Assignee', $assignee),
                $this->field('Agent Flow', $agentStatus),
            ],
        ];

        if ($task->project) {
            $projectLabel = $task->project->client
                ? "{$task->project->client->name} / {$task->project->name}"
                : $task->project->name;

            $blocks[] = $this->context([$projectLabel]);
        }

        $actions = $this->taskLifecycleActions([
            'id' => $task->id,
            'status' => $task->status,
            'title' => $task->title,
            'has_active_agent_task' => $task->hasActiveAgentTask(),
        ]);

        if ($actions !== []) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => $actions,
            ];
        }

        $blocks[] = $this->context([
            '<'.config('app.url')."/tasks/{$task->id}|Open in Dashboard>",
        ]);

        return $blocks;
    }

    /**
     * Build blocks for a channel's integration overview.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $status
     * @return array<int, array<string, mixed>>
     */
    public function integrationOverviewBlocks(array $context, array $status): array
    {
        $integrations = $context['integrations'] ?? [];
        $globalIntegrations = $status['integrations'] ?? [];

        $githubRepos = collect($integrations['github_repos'] ?? []);
        $pmConnections = collect($integrations['pm_connections'] ?? []);
        $wordPressSites = collect($integrations['wordpress_sites'] ?? []);
        $spinupSites = collect($integrations['spinup_sites'] ?? []);
        $harvestProject = $integrations['harvest_project'] ?? null;

        $blocks = [
            $this->header('Channel Integrations'),
            [
                'type' => 'section',
                'fields' => [
                    $this->field('GitHub', ($globalIntegrations['github']['connected'] ?? false) ? 'Connected' : 'Not connected'),
                    $this->field('Harvest', ($globalIntegrations['harvest']['connected'] ?? false) ? 'Connected' : 'Not connected'),
                    $this->field('ClickUp', $pmConnections->isNotEmpty() ? 'Linked' : 'None'),
                    $this->field('WordPress', $wordPressSites->isNotEmpty() ? 'Linked' : 'None'),
                ],
            ],
        ];

        if ($githubRepos->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*GitHub Repos*');
            $blocks[] = $this->section($githubRepos->take(5)->map(
                fn (array $repo): string => "- `{$repo['full_name']}`"
            )->implode("\n"));
        }

        if ($pmConnections->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*Project Management*');
            $blocks[] = $this->section($pmConnections->take(5)->map(
                fn (array $connection): string => "- {$connection['platform']}: {$connection['workspace_name']}"
            )->implode("\n"));
        }

        if ($harvestProject) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section("*Harvest*\n- {$harvestProject['name']}");
        }

        if ($wordPressSites->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*WordPress Sites*');
            $blocks[] = $this->section($wordPressSites->take(5)->map(
                fn (array $site): string => "- {$site['name']} ({$site['url']})"
            )->implode("\n"));
        }

        if ($spinupSites->isNotEmpty()) {
            $blocks[] = $this->divider();
            $blocks[] = $this->section('*SpinupWP Sites*');
            $blocks[] = $this->section($spinupSites->take(5)->map(
                fn (array $site): string => "- {$site['domain']} _({$site['status']})_"
            )->implode("\n"));
        }

        $actions = [];

        if ($githubRepos->isNotEmpty()) {
            $actions[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Sync GitHub'],
                'action_id' => 'integration_sync_github',
                'value' => 'sync-github',
                'style' => 'primary',
            ];
        }

        if ($pmConnections->isNotEmpty()) {
            $actions[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Sync ClickUp'],
                'action_id' => 'integration_sync_clickup',
                'value' => 'sync-clickup',
            ];
        }

        if ($globalIntegrations['harvest']['connected'] ?? false) {
            $actions[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Sync Harvest'],
                'action_id' => 'integration_sync_harvest',
                'value' => 'sync-harvest',
            ];
        }

        if ($wordPressSites->isNotEmpty()) {
            $actions[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Sync WordPress'],
                'action_id' => 'integration_sync_wordpress',
                'value' => 'sync-wordpress',
            ];
        }

        if ($actions !== []) {
            $blocks[] = $this->divider();
            $blocks[] = [
                'type' => 'actions',
                'elements' => array_slice($actions, 0, 5),
            ];
        }

        return $blocks;
    }

    /**
     * Build blocks for an integration action result.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function integrationActionResultBlocks(array $result): array
    {
        $icon = ($result['success'] ?? false) ? ':white_check_mark:' : ':warning:';
        $blocks = [
            $this->section("{$icon} ".($result['message'] ?? 'Integration action processed.')),
        ];

        if (isset($result['results']) && is_array($result['results'])) {
            $summary = collect($result['results'])
                ->map(function (array $item): string {
                    $status = ($item['success'] ?? false) ? 'queued' : 'skipped';

                    return "- {$item['action']}: {$status}";
                })
                ->implode("\n");

            if ($summary !== '') {
                $blocks[] = $this->section($summary);
            }
        }

        return $blocks;
    }

    /**
     * Build blocks for the staging setup workflow.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function stagingStatusBlocks(array $result, ?string $notice = null): array
    {
        $client = $result['client'] ?? null;
        $project = $result['project'] ?? [];
        $repo = $result['repo'] ?? [];
        $deployment = $result['deployment'] ?? [];
        $summary = $result['summary'] ?? [];
        $requiredSecrets = collect($result['required_secrets'] ?? []);

        $blocks = [];

        if ($notice) {
            $blocks[] = $this->section(":white_check_mark: {$notice}");
            $blocks[] = $this->divider();
        }

        $blocks[] = $this->header('Staging Workflow');
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Client', $client['name'] ?? 'Unknown'),
                $this->field('Project', $project['name'] ?? 'Unknown'),
                $this->field('Repo', $repo['full_name'] ?? 'Unknown'),
                $this->field('Branch', $deployment['publish_branch'] ?? ($repo['default_branch'] ?? 'main')),
            ],
        ];
        $blocks[] = [
            'type' => 'section',
            'fields' => [
                $this->field('Workflow', $deployment['workflow_identifier'] ?? 'Not configured'),
                $this->field('Deploy Type', ucfirst((string) ($deployment['deployment_type'] ?? 'unknown'))),
                $this->field('Secrets Ready', ($summary['configured'] ?? 0).'/'.($summary['required'] ?? 0)),
                $this->field('GitHub Synced', ($summary['synced'] ?? 0).'/'.($summary['required'] ?? 0)),
            ],
        ];

        if (! empty($deployment['staging_url'])) {
            $blocks[] = $this->context(["Staging URL: {$deployment['staging_url']}"]);
        }

        if ($requiredSecrets->isNotEmpty()) {
            $secretLines = $requiredSecrets->map(function (array $secret): string {
                $icon = match ($secret['sync_status'] ?? 'missing') {
                    'synced' => ':white_check_mark:',
                    'pending_sync' => ':hourglass_flowing_sand:',
                    default => ':warning:',
                };

                $targetScope = ($secret['target_scope'] ?? 'environment') === 'repository'
                    ? 'repo secret'
                    : 'staging env';

                return "{$icon} `{$secret['name']}` ({$targetScope})";
            })->implode("\n");

            $blocks[] = $this->divider();
            $blocks[] = $this->section("*Required Secrets*\n{$secretLines}");
        } else {
            $blocks[] = $this->divider();
            $blocks[] = $this->section(':information_source: No required workflow secrets are currently tracked for this repo.');
        }

        $actions = [];
        $firstMissingSecret = $requiredSecrets->firstWhere('sync_status', 'missing');

        $actions[] = [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => 'Add Secret'],
            'action_id' => 'staging_open_secret_modal',
            'value' => json_encode(array_filter([
                'secret_name' => $result['prefill_secret_name'] ?? ($firstMissingSecret['name'] ?? null),
            ], fn ($value) => $value !== null && $value !== '')),
            'style' => 'primary',
        ];

        if (($summary['configured'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Sync Secrets'],
                'action_id' => 'staging_sync_secrets',
                'value' => 'sync-staging-secrets',
            ];
        }

        if ($result['ready_to_publish'] ?? false) {
            $actions[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Publish to Staging'],
                'action_id' => 'staging_publish',
                'value' => 'publish-staging',
                'style' => 'primary',
            ];
        }

        if ($actions !== []) {
            $blocks[] = $this->divider();
            $blocks[] = [
                'type' => 'actions',
                'elements' => array_slice($actions, 0, 5),
            ];
        }

        return $blocks;
    }

    /**
     * Build a modal for secure staging secret entry.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function stagingSecretModal(array $result, ?string $prefillSecretName = null): array
    {
        $repo = $result['repo']['full_name'] ?? 'linked repo';
        $project = $result['project']['name'] ?? 'Linked Project';

        return [
            'type' => 'modal',
            'callback_id' => 'staging_secret_modal',
            'title' => [
                'type' => 'plain_text',
                'text' => 'Add Staging Secret',
            ],
            'submit' => [
                'type' => 'plain_text',
                'text' => 'Save Secret',
            ],
            'close' => [
                'type' => 'plain_text',
                'text' => 'Cancel',
            ],
            'blocks' => [
                $this->section("*{$project}*\nSave a credential for `{$repo}` and sync it to GitHub Actions."),
                [
                    'type' => 'input',
                    'block_id' => 'secret_name_block',
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'secret_name',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'VERCEL_TOKEN'],
                        'initial_value' => $prefillSecretName ?? '',
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Secret Name'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'target_scope_block',
                    'element' => [
                        'type' => 'static_select',
                        'action_id' => 'target_scope',
                        'initial_option' => [
                            'text' => ['type' => 'plain_text', 'text' => 'Staging Environment'],
                            'value' => 'environment',
                        ],
                        'options' => [
                            [
                                'text' => ['type' => 'plain_text', 'text' => 'Staging Environment'],
                                'value' => 'environment',
                            ],
                            [
                                'text' => ['type' => 'plain_text', 'text' => 'Repository Secret'],
                                'value' => 'repository',
                            ],
                        ],
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'GitHub Target'],
                ],
                [
                    'type' => 'input',
                    'block_id' => 'secret_value_block',
                    'element' => [
                        'type' => 'plain_text_input',
                        'action_id' => 'secret_value',
                        'multiline' => true,
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Paste the credential value'],
                    ],
                    'label' => ['type' => 'plain_text', 'text' => 'Secret Value'],
                ],
            ],
        ];
    }

    /**
     * Build a private success view for secret save completion.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function stagingSecretSavedModal(array $result): array
    {
        $summary = $result['summary'] ?? [];
        $syncResult = $result['sync_result'] ?? [];
        $storedSecret = $result['stored_secret'] ?? 'Secret';

        $lines = [
            ':white_check_mark: '.($result['message'] ?? 'Secret saved.'),
            "Secret: `{$storedSecret}`",
            'Configured: '.($summary['configured'] ?? 0).'/'.($summary['required'] ?? 0),
            'GitHub synced: '.($summary['synced'] ?? 0).'/'.($summary['required'] ?? 0),
        ];

        if (! empty($syncResult['failed'])) {
            $lines[] = 'Failed sync: '.implode(', ', $syncResult['failed']);
        }

        if ($result['ready_to_publish'] ?? false) {
            $lines[] = 'The staging workflow is ready to publish.';
        }

        return [
            'type' => 'modal',
            'title' => [
                'type' => 'plain_text',
                'text' => 'Secret Saved',
            ],
            'close' => [
                'type' => 'plain_text',
                'text' => 'Done',
            ],
            'blocks' => [
                $this->section(implode("\n", $lines)),
            ],
        ];
    }

    /**
     * Build blocks for a list of pending approvals.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    public function approvalQueueBlocks(array $result, string $title = 'Pending Approvals'): array
    {
        $approvals = collect($result['approvals'] ?? [])->values();
        $blocks = [$this->header($title)];

        if ($approvals->isEmpty()) {
            $blocks[] = $this->section('No matching approvals found.');

            return $blocks;
        }

        foreach ($approvals->take(5) as $approval) {
            $meta = collect([
                isset($approval['risk_level']) ? "risk: {$approval['risk_level']}" : null,
                isset($approval['action_type']) ? "type: {$approval['action_type']}" : null,
                isset($approval['agent']['name']) ? "agent: {$approval['agent']['name']}" : null,
                isset($approval['run']['id']) ? "run: #{$approval['run']['id']}" : null,
            ])->filter()->implode(' | ');

            $blocks[] = $this->section(
                "*#{$approval['id']}* {$approval['description']}".
                ($meta ? "\n_{$meta}_" : '')
            );

            if (($approval['status'] ?? 'pending') === 'pending') {
                $blocks[] = [
                    'type' => 'actions',
                    'elements' => [
                        [
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => 'Approve'],
                            'style' => 'primary',
                            'action_id' => 'approval_approve',
                            'value' => (string) $approval['id'],
                        ],
                        [
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => 'Reject'],
                            'style' => 'danger',
                            'action_id' => 'approval_reject',
                            'value' => (string) $approval['id'],
                        ],
                    ],
                ];
            }
        }

        $blocks[] = $this->context(['Showing up to 5 approvals']);

        return $blocks;
    }

    /**
     * Build blocks for an approval decision acknowledgement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function approvalDecisionBlocks(ApprovalRequest $approval): array
    {
        $statusText = match ($approval->status) {
            'approved' => ':white_check_mark: *Approval approved*',
            'rejected' => ':x: *Approval rejected*',
            default => ':information_source: *Approval updated*',
        };

        $context = collect([
            "Approval #{$approval->id}",
            $approval->action_type ? "type: {$approval->action_type}" : null,
            $approval->decidedBy?->name ? "by: {$approval->decidedBy->name}" : null,
        ])->filter()->values()->all();

        $blocks = [
            $this->section($statusText),
            $this->section($approval->description),
        ];

        if ($approval->decision_note) {
            $blocks[] = $this->section("_{$approval->decision_note}_");
        }

        $blocks[] = $this->context($context);

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<int, array<string, mixed>>
     */
    private function taskLifecycleActions(array $task): array
    {
        $taskId = (int) ($task['id'] ?? 0);
        $status = (string) ($task['status'] ?? 'pending');

        if (! $taskId) {
            return [];
        }

        $buttons = [];

        if ($status !== 'in_progress') {
            $buttons[] = $this->taskButton('Start', 'task_mark_in_progress', ['task_id' => $taskId], 'primary');
        }

        if ($status !== 'review') {
            $buttons[] = $this->taskButton('Review', 'task_mark_review', ['task_id' => $taskId]);
        }

        if ($status !== 'completed') {
            $buttons[] = $this->taskButton('Complete', 'task_mark_completed', ['task_id' => $taskId]);
        }

        if (! ($task['has_active_agent_task'] ?? false)) {
            $buttons[] = $this->taskButton('Run Dev Agent', 'task_run_agent', [
                'task_id' => $taskId,
                'agent_slug' => 'dev-agent',
            ]);
        }

        $buttons[] = [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => 'Open'],
            'url' => config('app.url')."/tasks/{$taskId}",
        ];

        return array_slice($buttons, 0, 5);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function taskButton(string $label, string $actionId, array $value, ?string $style = null): array
    {
        $button = [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => $label],
            'action_id' => $actionId,
            'value' => json_encode($value),
        ];

        if ($style) {
            $button['style'] = $style;
        }

        return $button;
    }
}
