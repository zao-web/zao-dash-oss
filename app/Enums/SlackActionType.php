<?php

namespace App\Enums;

enum SlackActionType: string
{
    case CreateTask = 'create_task';
    case ManageTask = 'manage_task';
    case LogNote = 'log_note';
    case TriggerAgent = 'trigger_agent';
    case TriggerEngineeringAgent = 'trigger_engineering_agent';
    case ListAgentRuns = 'list_agent_runs';
    case ShowAgentRun = 'show_agent_run';
    case RetryAgentRun = 'retry_agent_run';
    case CancelAgentRun = 'cancel_agent_run';
    case RequestReviewDeploy = 'request_review_deploy';
    case RetryReviewDeploy = 'retry_review_deploy';
    case CompoundEngineering = 'compound_engineering';
    case Search = 'search';
    case GetStatus = 'get_status';
    case GetFocus = 'get_focus';
    case GetThreadSummary = 'get_thread_summary';
    case GetChannelContext = 'get_channel_context';
    case GetIntegrations = 'get_integrations';
    case GetStagingStatus = 'get_staging_status';
    case PrepareStagingSecret = 'prepare_staging_secret';
    case SyncStagingSecrets = 'sync_staging_secrets';
    case PublishStaging = 'publish_staging';
    case ManageWatchlist = 'manage_watchlist';
    case ListWatchlist = 'list_watchlist';
    case LinkContext = 'link_context';
    case RunIntegrationSync = 'run_integration_sync';
    case ShowClient = 'show_client';
    case ListClients = 'list_clients';
    case CreateClient = 'create_client';
    case UpdateClient = 'update_client';
    case ShowProject = 'show_project';
    case ListProjects = 'list_projects';
    case CreateProject = 'create_project';
    case UpdateProject = 'update_project';
    case ImportSow = 'import_sow';
    case ListLeads = 'list_leads';
    case CreateLead = 'create_lead';
    case UpdateLeadStage = 'update_lead_stage';
    case ListInvoices = 'list_invoices';
    case CreateInvoice = 'create_invoice';
    case ShowWebsiteProject = 'show_website_project';
    case ListWebsiteProjects = 'list_website_projects';
    case CreateWebsiteProject = 'create_website_project';
    case UpdateWebsiteProject = 'update_website_project';
}
