<?php

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Agent-specific channel - receive updates for a specific agent's runs.
 */
Broadcast::channel('agents.{agentId}', function ($user, $agentId) {
    // For now, allow all authenticated users to listen to agent channels
    // In production, you might want to check team membership
    return Agent::where('id', $agentId)->exists();
});

/**
 * Individual run channel - receive detailed updates for a specific run.
 */
Broadcast::channel('agent-runs.{runId}', function ($user, $runId) {
    // Allow if user can view this run
    return AgentRun::where('id', $runId)->exists();
});

/**
 * User-specific notifications channel.
 */
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

/**
 * User-specific channel for personal events (videos, etc).
 */
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

/**
 * Site Builder project channel - receive build progress and chat messages.
 */
Broadcast::channel('site-builder.{projectId}', function ($user, $projectId) {
    return \App\Models\SiteBuilderProject::where('id', $projectId)
        ->where('user_id', $user->id)
        ->exists();
});

/**
 * Website Builder project channel - unified channel for all website projects.
 */
Broadcast::channel('website-builder.{projectId}', function ($user, $projectId) {
    return \App\Models\WebsiteProject::where('id', $projectId)
        ->where('user_id', $user->id)
        ->exists();
});

/**
 * Meta Ads campaign channel - receive creative generation progress updates.
 */
Broadcast::channel('meta-ads.campaign.{campaignId}', function ($user, $campaignId) {
    // Allow all authenticated users to listen to campaign updates
    // In production, you might want to check if user owns the campaign's client
    return \App\Models\AdCampaign::where('id', $campaignId)->exists();
});

/**
 * User-specific interactions channel - receive agent interaction requests.
 */
Broadcast::channel('user.{userId}.interactions', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
