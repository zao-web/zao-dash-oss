<?php

namespace App\Providers;

use App\Events\ActionItemsExtracted;
use App\Events\SlackActionItemDetected;
use App\Events\VideoCommentAdded;
use App\Events\VideoViewed;
use App\Listeners\PersistActionItemsNotification;
use App\Listeners\SendVideoCommentNotification;
use App\Listeners\SendVideoViewedNotification;
use App\Listeners\TriggerDevAgentForSlackActionItem;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\IdealCustomerProfile;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
use App\Models\TimeEntry;
use App\Observers\AgentRunObserver;
use App\Observers\ApprovalRequestObserver;
use App\Observers\ClientObserver;
use App\Observers\LeadObserver;
use App\Observers\ProjectObserver;
use App\Observers\TimeEntryObserver;
use App\Services\AgentOutputRouter;
use App\Services\Agents\AgentExecutor;
use App\Services\Agents\ChainExecutor;
use App\Services\Agents\ClaudeAgentSdk;
use App\Services\Agents\ClaudeCliRunner;
use App\Services\GitHub\GitHubAppService;
use App\Services\Vault\VaultService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Claude Agent SDK (API-based execution)
        $this->app->singleton(ClaudeAgentSdk::class, function () {
            return new ClaudeAgentSdk;
        });

        // Register AgentExecutor with all dependencies
        $this->app->singleton(AgentExecutor::class, function ($app) {
            return new AgentExecutor(
                runner: $app->make(ClaudeCliRunner::class),
                sdk: $app->make(ClaudeAgentSdk::class),
                outputRouter: $app->make(AgentOutputRouter::class),
                vault: $app->make(VaultService::class),
                githubApp: $app->make(GitHubAppService::class),
            );
        });

        // Register ChainExecutor and wire it to AgentExecutor
        $this->app->singleton(ChainExecutor::class, function ($app) {
            $chainExecutor = new ChainExecutor($app->make(AgentExecutor::class));
            // Wire chain executor back to agent executor
            $app->make(AgentExecutor::class)->setChainExecutor($chainExecutor);

            return $chainExecutor;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::model('icp', IdealCustomerProfile::class);
        Route::model('rfp', \App\Models\RfpOpportunity::class);
        Route::model('spinupServer', SpinupWpServer::class);
        Route::model('spinupSite', SpinupWpSite::class);

        ApprovalRequest::observe(ApprovalRequestObserver::class);
        AgentRun::observe(AgentRunObserver::class);
        Client::observe(ClientObserver::class);
        Lead::observe(LeadObserver::class);
        Project::observe(ProjectObserver::class);
        TimeEntry::observe(TimeEntryObserver::class);
        \App\Models\Task::observe(\App\Observers\TaskObserver::class);

        // Register video notification listeners
        Event::listen(VideoViewed::class, SendVideoViewedNotification::class);
        Event::listen(VideoCommentAdded::class, SendVideoCommentNotification::class);
        Event::listen(ActionItemsExtracted::class, PersistActionItemsNotification::class);
        Event::listen(SlackActionItemDetected::class, TriggerDevAgentForSlackActionItem::class);
        Event::listen(SlackActionItemDetected::class, \App\Listeners\NotifySlackOnActionItem::class);
        Event::listen(\App\Events\AgentRunStatusChanged::class, \App\Listeners\NotifySlackOnAgentRunComplete::class);

        // SEO lead attribution logging
        Event::listen(\App\Events\LeadAttributedToSeoPage::class, \App\Listeners\LogLeadAttribution::class);

        // Rate limiter for Anthropic API calls (30k tokens/min = ~2-3 agent calls)
        // Allow 2 concurrent agent jobs per minute to stay under limit
        RateLimiter::for('anthropic-agents', function ($job) {
            return Limit::perMinute(3)->by('anthropic');
        });

        Mcp::local('zao-dash', \App\Mcp\Servers\ZaoDashServer::class);
    }
}
