<?php

use App\Enums\SlackActionType;
use App\Services\Agents\CompoundEngineeringSkillLoader;
use App\Services\Slack\SlackIntentDetectionService;

beforeEach(function () {
    $this->skillLoader = mock(CompoundEngineeringSkillLoader::class);
    $this->skillLoader->shouldReceive('parseInvocation')
        ->andReturn(['matched' => false])
        ->byDefault();

    $this->service = new SlackIntentDetectionService($this->skillLoader);
});

describe('detectIntent', function () {
    test('detects create task intent', function () {
        $result = $this->service->detectIntent('create a task to update the documentation');

        expect($result)->not->toBeNull();
        expect($result['type'])->toBe(SlackActionType::CreateTask->value);
        expect($result['title'])->toBe('update the documentation');
        expect($result['priority'])->toBe('medium');
    });

    test('detects create task with urgent priority', function () {
        $result = $this->service->detectIntent('create task: fix the urgent bug');

        expect($result['type'])->toBe(SlackActionType::CreateTask->value);
        expect($result['priority'])->toBe('urgent');
    });

    test('detects create task with high priority', function () {
        $result = $this->service->detectIntent('create task for high priority deployment');

        expect($result['type'])->toBe(SlackActionType::CreateTask->value);
        expect($result['priority'])->toBe('high');
    });

    test('detects log note intent', function () {
        $result = $this->service->detectIntent('log a note: client approved the design');

        expect($result)->not->toBeNull();
        expect($result['type'])->toBe(SlackActionType::LogNote->value);
        expect($result['content'])->toBe('client approved the design');
    });

    test('detects log that pattern', function () {
        $result = $this->service->detectIntent('log that the meeting was productive');

        expect($result['type'])->toBe(SlackActionType::LogNote->value);
        expect($result['content'])->toBe('the meeting was productive');
    });

    test('detects run agent intent', function () {
        $result = $this->service->detectIntent('run the dev-agent on this issue');

        expect($result)->not->toBeNull();
        expect($result['type'])->toBe(SlackActionType::TriggerAgent->value);
        expect($result['agent_slug'])->toBe('dev-agent');
        expect($result['task'])->toBe('this issue');
    });

    test('detects trigger agent intent', function () {
        $result = $this->service->detectIntent('trigger code-review-agent');

        expect($result['type'])->toBe(SlackActionType::TriggerAgent->value);
        expect($result['agent_slug'])->toBe('code-review-agent');
    });

    test('detects engineering issue intent for pull request flow', function () {
        $result = $this->service->detectIntent('work issue #42');

        expect($result)->not->toBeNull();
        expect($result['type'])->toBe(SlackActionType::TriggerEngineeringAgent->value);
        expect($result['issue_number'])->toBe(42);
        expect($result['delivery_target'])->toBe('pr');
        expect($result['agent_slug'])->toBe('dev-agent');
    });

    test('detects engineering issue intent for staging flow', function () {
        $result = $this->service->detectIntent('fix issue #42 on staging branch');

        expect($result)->not->toBeNull();
        expect($result['type'])->toBe(SlackActionType::TriggerEngineeringAgent->value);
        expect($result['issue_number'])->toBe(42);
        expect($result['delivery_target'])->toBe('staging');
        expect($result['branch_preference'])->toBe('staging');
    });

    test('detects show task intent', function () {
        $result = $this->service->detectIntent('show task #123');

        expect($result)->not->toBeNull();
        expect($result['type'])->toBe(SlackActionType::ManageTask->value);
        expect($result['operation'])->toBe('show');
        expect($result['task_id'])->toBe(123);
    });

    test('detects task review intent', function () {
        $result = $this->service->detectIntent('move task #123 to review');

        expect($result['type'])->toBe(SlackActionType::ManageTask->value);
        expect($result['operation'])->toBe('set_status');
        expect($result['status'])->toBe('review');
        expect($result['task_id'])->toBe(123);
    });

    test('detects task priority intent', function () {
        $result = $this->service->detectIntent('make task #55 urgent priority');

        expect($result['type'])->toBe(SlackActionType::ManageTask->value);
        expect($result['operation'])->toBe('set_priority');
        expect($result['priority'])->toBe('urgent');
        expect($result['task_id'])->toBe(55);
    });

    test('detects task agent run intent', function () {
        $result = $this->service->detectIntent('run dev-agent on task #88');

        expect($result['type'])->toBe(SlackActionType::ManageTask->value);
        expect($result['operation'])->toBe('run_agent');
        expect($result['agent_slug'])->toBe('dev-agent');
        expect($result['task_id'])->toBe(88);
    });

    test('detects channel context intent', function () {
        $result = $this->service->detectIntent('what project is this channel linked to?');

        expect($result['type'])->toBe(SlackActionType::GetChannelContext->value);
    });

    test('detects thread summary intent', function () {
        $result = $this->service->detectIntent('what are we working on in this thread?');

        expect($result['type'])->toBe(SlackActionType::GetThreadSummary->value);
    });

    test('detects request review deploy intent', function () {
        $result = $this->service->detectIntent('promote this PR to staging');

        expect($result['type'])->toBe(SlackActionType::RequestReviewDeploy->value);
    });

    test('detects request review deploy intent for a specific pr', function () {
        $result = $this->service->detectIntent('promote PR #17 to staging');

        expect($result['type'])->toBe(SlackActionType::RequestReviewDeploy->value)
            ->and($result['pr_number'])->toBe(17);
    });

    test('detects retry review deploy intent', function () {
        $result = $this->service->detectIntent('retry the review deploy');

        expect($result['type'])->toBe(SlackActionType::RetryReviewDeploy->value);
    });

    test('detects retry agent run intent', function () {
        $result = $this->service->detectIntent('reopen the failed run');

        expect($result['type'])->toBe(SlackActionType::RetryAgentRun->value);
    });

    test('detects workflow status intent for a specific pr', function () {
        $result = $this->service->detectIntent('what happened in CI for PR #17?');

        expect($result['type'])->toBe(SlackActionType::GetThreadSummary->value)
            ->and($result['focus'])->toBe('workflow')
            ->and($result['pr_number'])->toBe(17);
    });

    test('detects workflow failure detail intent', function () {
        $result = $this->service->detectIntent('show me the last CI failure details');

        expect($result['type'])->toBe(SlackActionType::GetThreadSummary->value)
            ->and($result['focus'])->toBe('workflow_failure');
    });

    test('detects open review build intent', function () {
        $result = $this->service->detectIntent('open the review build');

        expect($result['type'])->toBe(SlackActionType::GetThreadSummary->value)
            ->and($result['focus'])->toBe('review_build');
    });

    test('detects engineering issue intent with source pull request context', function () {
        $result = $this->service->detectIntent('work issue #42 from this PR');

        expect($result['type'])->toBe(SlackActionType::TriggerEngineeringAgent->value)
            ->and($result['issue_number'])->toBe(42)
            ->and($result['use_thread_pr'])->toBeTrue();
    });

    test('detects deploy status thread intent', function () {
        $result = $this->service->detectIntent("what's the deploy status on this thread?");

        expect($result['type'])->toBe(SlackActionType::GetThreadSummary->value);
    });

    test('detects integrations intent', function () {
        $result = $this->service->detectIntent('show integrations');

        expect($result['type'])->toBe(SlackActionType::GetIntegrations->value);
    });

    test('detects track watchlist intent', function () {
        $result = $this->service->detectIntent('track Acme, Beta, and Ops');

        expect($result['type'])->toBe(SlackActionType::ManageWatchlist->value)
            ->and($result['operation'])->toBe('track')
            ->and($result['query'])->toBe('Acme, Beta, and Ops');
    });

    test('detects list watchlist intent', function () {
        $result = $this->service->detectIntent('what is on my watchlist?');

        expect($result['type'])->toBe(SlackActionType::ListWatchlist->value);
    });

    test('detects list agent runs intent', function () {
        $result = $this->service->detectIntent('show active runs');

        expect($result['type'])->toBe(SlackActionType::ListAgentRuns->value)
            ->and($result['filter'])->toBe('active');
    });

    test('detects focus briefing intent', function () {
        $result = $this->service->detectIntent('what should I work on today?');

        expect($result['type'])->toBe(SlackActionType::GetFocus->value)
            ->and($result['priority_filter'])->toBe('all');
    });

    test('detects prioritize today phrasing', function () {
        $result = $this->service->detectIntent('help me prioritize today');

        expect($result['type'])->toBe(SlackActionType::GetFocus->value)
            ->and($result['priority_filter'])->toBe('all');
    });

    test('detects show agent run intent', function () {
        $result = $this->service->detectIntent('show run #123');

        expect($result['type'])->toBe(SlackActionType::ShowAgentRun->value)
            ->and($result['run_id'])->toBe(123);
    });

    test('detects cancel agent run intent by run id', function () {
        $result = $this->service->detectIntent('cancel run #123');

        expect($result['type'])->toBe(SlackActionType::CancelAgentRun->value)
            ->and($result['run_id'])->toBe(123);
    });

    test('detects cancel current thread run intent', function () {
        $result = $this->service->detectIntent('stop this run');

        expect($result['type'])->toBe(SlackActionType::CancelAgentRun->value);
    });

    test('detects staging status intent', function () {
        $result = $this->service->detectIntent('what secrets are missing for staging?');

        expect($result['type'])->toBe(SlackActionType::GetStagingStatus->value);
    });

    test('detects prepare staging secret intent', function () {
        $result = $this->service->detectIntent('add VERCEL_TOKEN for staging');

        expect($result['type'])->toBe(SlackActionType::PrepareStagingSecret->value)
            ->and($result['secret_name'])->toBe('VERCEL_TOKEN');
    });

    test('detects sync staging secrets intent', function () {
        $result = $this->service->detectIntent('sync staging secrets');

        expect($result['type'])->toBe(SlackActionType::SyncStagingSecrets->value);
    });

    test('detects publish staging intent', function () {
        $result = $this->service->detectIntent('publish this project to staging');

        expect($result['type'])->toBe(SlackActionType::PublishStaging->value);
    });

    test('detects link context intent', function () {
        $result = $this->service->detectIntent('link this channel to client 12 project 34');

        expect($result['type'])->toBe(SlackActionType::LinkContext->value);
        expect($result['client_id'])->toBe(12);
        expect($result['project_id'])->toBe(34);
    });

    test('detects integration sync intent', function () {
        $result = $this->service->detectIntent('sync github');

        expect($result['type'])->toBe(SlackActionType::RunIntegrationSync->value);
        expect($result['target'])->toBe('github');
    });

    test('detects show client intent', function () {
        $result = $this->service->detectIntent('show client 12');

        expect($result['type'])->toBe(SlackActionType::ShowClient->value);
        expect($result['id'])->toBe(12);
    });

    test('detects list clients intent', function () {
        $result = $this->service->detectIntent('list active clients');

        expect($result['type'])->toBe(SlackActionType::ListClients->value);
        expect($result['status'])->toBe('active');
    });

    test('detects list leads intent', function () {
        $result = $this->service->detectIntent('list qualified leads');

        expect($result['type'])->toBe(SlackActionType::ListLeads->value);
        expect($result['stage'])->toBe('qualified');
    });

    test('detects create lead intent', function () {
        $result = $this->service->detectIntent('create lead Acme Prospect with website https://acme.test with email sales@acme.test');

        expect($result['type'])->toBe(SlackActionType::CreateLead->value);
        expect($result['company_name'])->toBe('Acme Prospect');
        expect($result['website'])->toBe('https://acme.test');
        expect($result['contact_email'])->toBe('sales@acme.test');
    });

    test('detects update lead stage intent', function () {
        $result = $this->service->detectIntent('move lead #12 to proposal');

        expect($result['type'])->toBe(SlackActionType::UpdateLeadStage->value);
        expect($result['id'])->toBe(12);
        expect($result['stage'])->toBe('proposal');
    });

    test('detects list invoices intent', function () {
        $result = $this->service->detectIntent('list draft invoices for client 12');

        expect($result['type'])->toBe(SlackActionType::ListInvoices->value);
        expect($result['status'])->toBe('draft');
        expect($result['client_id'])->toBe(12);
    });

    test('detects create invoice intent', function () {
        $result = $this->service->detectIntent('create invoice for client 12 item Homepage redesign amount 2500 qty 2');

        expect($result['type'])->toBe(SlackActionType::CreateInvoice->value);
        expect($result['client_id'])->toBe(12);
        expect($result['subject'])->toBe('Homepage redesign');
        expect($result['items'][0]['quantity'])->toBe(2.0);
    });

    test('detects create client intent', function () {
        $result = $this->service->detectIntent('create client Acme Studio with website https://acme.test');

        expect($result['type'])->toBe(SlackActionType::CreateClient->value);
        expect($result['name'])->toBe('Acme Studio');
        expect($result['website'])->toBe('https://acme.test');
    });

    test('detects update client intent', function () {
        $result = $this->service->detectIntent('rename client 12 to Acme Labs');

        expect($result['type'])->toBe(SlackActionType::UpdateClient->value);
        expect($result['id'])->toBe(12);
        expect($result['name'])->toBe('Acme Labs');
    });

    test('detects show project intent', function () {
        $result = $this->service->detectIntent('show project 34');

        expect($result['type'])->toBe(SlackActionType::ShowProject->value);
        expect($result['id'])->toBe(34);
    });

    test('detects list projects intent', function () {
        $result = $this->service->detectIntent('list active projects for client 12');

        expect($result['type'])->toBe(SlackActionType::ListProjects->value);
        expect($result['status'])->toBe('active');
        expect($result['client_id'])->toBe(12);
    });

    test('detects show website project intent', function () {
        $result = $this->service->detectIntent('show website project 34');

        expect($result['type'])->toBe(SlackActionType::ShowWebsiteProject->value);
        expect($result['id'])->toBe(34);
    });

    test('detects list website projects intent', function () {
        $result = $this->service->detectIntent('list website projects status building type autonomous');

        expect($result['type'])->toBe(SlackActionType::ListWebsiteProjects->value);
        expect($result['status'])->toBe('building');
        expect($result['project_type'])->toBe('autonomous');
    });

    test('detects create project intent', function () {
        $result = $this->service->detectIntent('create project Platform Refresh for client 12 with github repo acme/platform');

        expect($result['type'])->toBe(SlackActionType::CreateProject->value);
        expect($result['client_id'])->toBe(12);
        expect($result['name'])->toBe('Platform Refresh');
        expect($result['github_repo'])->toBe('acme/platform');
    });

    test('detects update project intent', function () {
        $result = $this->service->detectIntent('set project 34 github repo to acme/platform');

        expect($result['type'])->toBe(SlackActionType::UpdateProject->value);
        expect($result['id'])->toBe(34);
        expect($result['github_repo'])->toBe('acme/platform');
    });

    test('detects create website project intent', function () {
        $result = $this->service->detectIntent('create website project Acme Relaunch type autonomous domain acme.test brief Rebuild the site');

        expect($result['type'])->toBe(SlackActionType::CreateWebsiteProject->value);
        expect($result['name'])->toBe('Acme Relaunch');
        expect($result['project_type'])->toBe('autonomous');
        expect($result['domain'])->toBe('acme.test');
    });

    test('detects update website project intent', function () {
        $result = $this->service->detectIntent('set website project 34 status to building');

        expect($result['type'])->toBe(SlackActionType::UpdateWebsiteProject->value);
        expect($result['id'])->toBe(34);
        expect($result['status'])->toBe('building');
    });

    test('detects search intent', function () {
        $result = $this->service->detectIntent('find all tasks about authentication');

        expect($result)->not->toBeNull();
        expect($result['type'])->toBe(SlackActionType::Search->value);
        expect($result['query'])->toBe('authentication');
        expect($result['search_type'])->toBe('tasks');
    });

    test('detects search all intent', function () {
        $result = $this->service->detectIntent('search for login issues');

        expect($result['type'])->toBe(SlackActionType::Search->value);
        expect($result['query'])->toBe('login issues');
        expect($result['search_type'])->toBe('all');
    });

    test('detects sow import intent from google doc links', function () {
        $result = $this->service->detectIntent('Please spin up this approved SOW from https://docs.google.com/document/d/abc123/edit and link this channel');

        expect($result)->not->toBeNull();
        expect($result['type'])->toBe(SlackActionType::ImportSow->value);
        expect($result['google_doc_urls'])->toBe(['https://docs.google.com/document/d/abc123/edit']);
        expect($result['link_to_channel'])->toBeTrue();
        expect($result['create_invoices'])->toBeTrue();
    });

    test('detects get status intent', function () {
        $result = $this->service->detectIntent("what's the status?");

        expect($result)->not->toBeNull();
        expect($result['type'])->toBe(SlackActionType::GetStatus->value);
    });

    test('detects status with variations', function () {
        expect($this->service->detectIntent('status')['type'])->toBe(SlackActionType::GetStatus->value);
        expect($this->service->detectIntent("how's it going?")['type'])->toBe(SlackActionType::GetStatus->value);
        expect($this->service->detectIntent('progress update')['type'])->toBe(SlackActionType::GetStatus->value);
    });

    test('returns null for unrecognized message', function () {
        $result = $this->service->detectIntent('hello there');

        expect($result)->toBeNull();
    });

    test('detects compound engineering skill', function () {
        $this->skillLoader->shouldReceive('parseInvocation')
            ->with('/workflows:plan')
            ->andReturn([
                'matched' => true,
                'skill' => 'workflows:plan',
                'args' => null,
            ]);

        $result = $this->service->detectIntent('/workflows:plan');

        expect($result)->not->toBeNull();
        expect($result['type'])->toBe(SlackActionType::CompoundEngineering->value);
        expect($result['skill'])->toBe('workflows:plan');
    });

    test('detects run command pattern for skills', function () {
        $this->skillLoader->shouldReceive('parseInvocation')
            ->with('run /lfg')
            ->andReturn(['matched' => false]);
        $this->skillLoader->shouldReceive('parseInvocation')
            ->with('/lfg')
            ->andReturn([
                'matched' => true,
                'skill' => 'lfg',
                'args' => null,
            ]);

        $result = $this->service->detectIntent('run /lfg');

        expect($result['type'])->toBe(SlackActionType::CompoundEngineering->value);
        expect($result['skill'])->toBe('lfg');
    });
});

describe('extractPriority', function () {
    test('returns urgent for urgent keywords', function () {
        expect($this->service->extractPriority('this is urgent'))->toBe('urgent');
        expect($this->service->extractPriority('critical issue'))->toBe('urgent');
        expect($this->service->extractPriority('fix asap'))->toBe('urgent');
    });

    test('returns high for high priority', function () {
        expect($this->service->extractPriority('high priority task'))->toBe('high');
        expect($this->service->extractPriority('this is high'))->toBe('high');
    });

    test('returns low for low priority', function () {
        expect($this->service->extractPriority('low priority'))->toBe('low');
        expect($this->service->extractPriority('this is low'))->toBe('low');
    });

    test('returns medium by default', function () {
        expect($this->service->extractPriority('regular task'))->toBe('medium');
    });
});

describe('requiresConfirmation', function () {
    test('requires confirmation for trigger agent', function () {
        $action = ['type' => SlackActionType::TriggerAgent->value];

        expect($this->service->requiresConfirmation($action))->toBeTrue();
    });

    test('requires confirmation for compound engineering', function () {
        $action = ['type' => SlackActionType::CompoundEngineering->value];

        expect($this->service->requiresConfirmation($action))->toBeTrue();
    });

    test('requires confirmation for engineering issue runs', function () {
        $action = ['type' => SlackActionType::TriggerEngineeringAgent->value];

        expect($this->service->requiresConfirmation($action))->toBeTrue();
    });

    test('does not require confirmation for create task', function () {
        $action = ['type' => SlackActionType::CreateTask->value];

        expect($this->service->requiresConfirmation($action))->toBeFalse();
    });

    test('does not require confirmation for log note', function () {
        $action = ['type' => SlackActionType::LogNote->value];

        expect($this->service->requiresConfirmation($action))->toBeFalse();
    });

    test('does not require confirmation for manage task', function () {
        $action = ['type' => SlackActionType::ManageTask->value];

        expect($this->service->requiresConfirmation($action))->toBeFalse();
    });

    test('supports enum type values', function () {
        $action = ['type' => SlackActionType::TriggerAgent];

        expect($this->service->requiresConfirmation($action))->toBeTrue();
    });
});
