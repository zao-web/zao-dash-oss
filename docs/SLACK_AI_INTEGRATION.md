# Slack AI Integration

## Overview

The Slack AI integration provides intelligent processing of Slack messages to automatically:

1. **Auto-associate channels with clients** - Match Slack channels to their corresponding client records
2. **Detect action items** - Identify requests and tasks buried in conversations
3. **Track repeated requests** - Detect when clients ask the same thing multiple times (sentiment decay)
4. **Summarize threads** - Generate AI summaries for long conversation threads
5. **Create tasks** - Optionally auto-create tasks from high-confidence action items

## Architecture

### Processing Pipeline

```
Slack Events API (Webhook)
         ↓
SlackWebhookController
         ↓
    ┌────┴────┐
    ↓         ↓
Real-time    Batch (scheduled)
AnalyzeSlackMessageJob    ProcessSlackMessagesJob
    ↓         ↓
SlackMessageAnalyzerService
         ↓
    ┌────┴────┐
    ↓         ↓
AI Analysis  Task Creation
(Claude)     (optional)
```

### Real-time vs Batch Processing

| Mode | Trigger | Scope | Task Creation |
|------|---------|-------|---------------|
| Real-time | Webhook event | Single message | On demand (high confidence) |
| Batch | Scheduled hourly | Unprocessed messages | Optional flag |

## Key Components

### 1. SlackChannelMatcherService

**Location:** `app/Services/Slack/SlackChannelMatcherService.php`

Automatically matches Slack channels to clients using intelligent scoring:

#### Matching Algorithm

```php
$signals = [
    'exact_slug_match' => 90,      // Channel name exactly matches client slug
    'name_contains' => 70,          // Channel contains client name
    'word_overlap' => 30-50,        // Significant word overlap
    'external_domain_match' => 60,  // Channel has external domain matching client
];
```

#### Auto-Link Threshold

Channels with confidence >= 70 are automatically linked. Others generate suggestions.

#### Usage

```php
// Match single channel
$result = $matcher->matchChannel($channel);
// Returns: ['client' => $client, 'confidence' => 85, 'reason' => 'exact_slug_match']

// Auto-link all channels
$result = $matcher->autoLinkAllChannels();
// Returns: ['linked' => 5, 'suggestions' => [...]]
```

### 2. SlackMessageAnalyzerService

**Location:** `app/Services/Slack/SlackMessageAnalyzerService.php`

AI-powered message analysis using Claude Haiku for cost efficiency.

#### Capabilities

**Action Item Detection**
```php
$analysis = $analyzer->analyzeMessage($message);
// Returns:
[
    'has_action_item' => true,
    'action_description' => 'Update the homepage design',
    'confidence' => 0.87,
    'urgency' => 'normal', // low, normal, high, urgent
    'intent' => 'request',
]
```

**Repeated Request Detection**
```php
$repeated = $analyzer->checkForRepeatedRequest($message);
// Returns:
[
    'is_repeated' => true,
    'similar_messages' => [$msg1, $msg2],
    'similarity_score' => 0.92,
]
```

**Thread Summarization**
```php
$summary = $analyzer->summarizeThread($thread);
// Returns:
[
    'summary' => 'Discussion about homepage redesign...',
    'key_points' => ['Design approved', 'Launch by Friday'],
    'action_items' => ['Finalize mobile version', 'Send preview'],
    'participants' => ['Justin', 'Client Name'],
]
```

**Task Creation**
```php
$task = $analyzer->createTaskFromMessage($message, [
    'urgency' => 'high',
]);
// Creates task linked to client/project with source='slack'
```

### 3. ProcessSlackMessagesJob

**Location:** `app/Jobs/ProcessSlackMessagesJob.php`

Batch processing job for scheduled AI analysis.

#### Steps

1. **Auto-link channels** - Run channel matcher for unlinked channels
2. **Analyze messages** - Process unprocessed messages with AI
3. **Summarize threads** - Summarize threads with 5+ messages

#### Configuration

```php
new ProcessSlackMessagesJob(
    workspaceId: null,        // Filter by workspace (optional)
    channelId: null,          // Filter by channel (optional)
    autoCreateTasks: false,   // Auto-create tasks from action items
    messageLimit: 100,        // Max messages per run
);
```

#### Scheduling

```php
// routes/console.php
Schedule::job(new ProcessSlackMessagesJob(autoCreateTasks: false))
    ->hourly()
    ->withoutOverlapping()
    ->name('process-slack-messages');
```

### 4. AnalyzeSlackMessageJob

**Location:** `app/Jobs/AnalyzeSlackMessageJob.php`

Real-time single message analysis triggered by webhooks.

#### Configuration

```php
new AnalyzeSlackMessageJob(
    messageId: $message->id,
    createTask: false,    // Auto-create task if high confidence
);
```

#### Retry Logic

- **Attempts:** 2
- **Backoff:** 30 seconds
- **On failure:** Marks message as processed to prevent infinite retries

## Database Schema

### Relevant Columns

**slack_messages table:**
```php
$table->text('ai_analysis')->nullable();       // Cached analysis JSON
$table->float('action_confidence')->nullable(); // Confidence score 0-1
$table->timestamp('processed_at')->nullable();  // When AI processed
$table->string('intent')->nullable();           // detected intent type
$table->boolean('has_action_item')->default(false);
```

**slack_threads table:**
```php
$table->text('summary')->nullable();            // AI-generated summary
$table->json('action_items')->nullable();       // Extracted action items
$table->timestamp('summarized_at')->nullable(); // When summarized
```

**slack_channels table:**
```php
$table->foreignId('client_id')->nullable();    // Auto-linked client
$table->string('classification')->nullable();   // internal, client, vendor
```

## Webhook Integration

### Events Handled

**message** - New messages in monitored channels
- Triggers real-time analysis for external user messages in client channels
- Stores message for batch processing

**reaction_added** - Reactions (for acknowledgment tracking)
- Future: Track message acknowledgments

**member_joined_channel** / **member_left_channel** - Membership changes
- Re-classifies channel (internal, client, vendor)

### Controller Flow

```php
// SlackWebhookController::handleMessageEvent()
1. Check for Nightwatch errors (self-healing)
2. Filter bot messages and subtypes
3. Find monitored channel
4. Store message via SlackApiService
5. Sync thread if reply
6. Queue analysis if external user + client channel
```

### Queue Configuration

Real-time messages use dedicated `slack-analysis` queue:

```php
AnalyzeSlackMessageJob::dispatch($message->id)
    ->onQueue('slack-analysis')
    ->delay(now()->addSeconds(2)); // Small batch window
```

## AI Prompts

### Action Item Detection Prompt

```
Analyze this Slack message for action items or requests.

Message: {content}
Context: Channel {channel_name}, from {user_name}

Identify:
1. Is this a request or action item? (yes/no)
2. If yes, what is being requested?
3. How urgent is this? (low/normal/high/urgent)
4. Confidence score (0-1)
5. Intent type (question, request, feedback, complaint, update)

Respond in JSON format.
```

### Thread Summary Prompt

```
Summarize this Slack thread conversation.

Thread messages:
{messages}

Provide:
1. Brief summary (1-2 sentences)
2. Key points (bullet list)
3. Action items mentioned
4. Decisions made

Focus on actionable information.
```

## Client Health Scoring

### Sentiment Decay Algorithm

When repeated requests are detected, the service updates client health:

```php
if ($analysis['is_repeated_request']) {
    $client->updateHealthMetric('communication', -5, 'repeated_request');

    // Log for Client Success dashboard
    ClientHealthEvent::create([
        'client_id' => $client->id,
        'event' => 'repeated_request',
        'impact' => -5,
        'context' => $analysis,
    ]);
}
```

### Health Impact

| Event | Health Impact |
|-------|---------------|
| Repeated request | -5 |
| Urgent escalation | -10 |
| Quick response (by us) | +2 |
| Positive feedback | +5 |

## Running Analysis

### Manual Processing

```bash
# Process all workspaces
php artisan tinker
>>> ProcessSlackMessagesJob::dispatchSync();

# Process specific workspace
>>> ProcessSlackMessagesJob::dispatchSync(workspaceId: 1);

# Process with task creation enabled
>>> ProcessSlackMessagesJob::dispatchSync(autoCreateTasks: true);
```

### Queue Workers

The application uses multiple queues. Run all of them with priority ordering:

```bash
# Recommended: All queues with priority
php artisan queue:work --queue=sync,integrations,slack-analysis,default --tries=3

# Queue breakdown:
#   sync           - Integration syncs (Slack, GitHub, Harvest, etc.)
#   integrations   - Webhook-triggered syncs (WordPress, QuickBooks)
#   slack-analysis - AI message analysis (can be slower)
#   default        - General jobs
```

## Cost Considerations

### Model Selection

Uses Claude Haiku for cost efficiency:
- ~$0.00025 per message analysis
- ~$0.001 per thread summary

### Rate Limiting

```php
// Built into AnthropicService
$this->anthropic->messages([
    'model' => 'claude-3-haiku-20240307',
    'max_tokens' => 500,  // Limit response size
    // ...
]);
```

### Batch Optimization

Messages are analyzed in batches of 10 to:
- Reduce API round-trips
- Enable context sharing
- Improve rate limit utilization

## Monitoring

### Logs

Key log entries:
```
[INFO] Slack event received: {type: message, team: T123}
[INFO] Processing Slack message: {channel: client-acme, ts: 123.456}
[INFO] AnalyzeSlackMessageJob: Analysis complete: {message_id, has_action_item, confidence}
[INFO] Slack message processing complete: {analyzed: 50, action_items: 12}
```

### Metrics Tracked

- Messages analyzed per hour
- Action items detected
- Tasks created
- Threads summarized
- Repeated requests detected
- Average confidence score

## Configuration

### Environment Variables

```env
# Slack Configuration
SLACK_SIGNING_SECRET=your_signing_secret
SLACK_BOT_TOKEN=xoxb-your-token

# AI Configuration
ANTHROPIC_API_KEY=your_anthropic_key

# Feature Flags
SLACK_AI_ENABLED=true
SLACK_AUTO_CREATE_TASKS=false
SLACK_MIN_CONFIDENCE=0.7
```

### Service Configuration

```php
// config/services.php
'slack' => [
    'signing_secret' => env('SLACK_SIGNING_SECRET'),
    'bot_token' => env('SLACK_BOT_TOKEN'),
    'ai_enabled' => env('SLACK_AI_ENABLED', true),
    'auto_create_tasks' => env('SLACK_AUTO_CREATE_TASKS', false),
    'min_confidence' => env('SLACK_MIN_CONFIDENCE', 0.7),
],
```

## Testing

### Unit Tests

```bash
php artisan test --filter=SlackMessageAnalyzerServiceTest
php artisan test --filter=SlackChannelMatcherServiceTest
php artisan test --filter=ProcessSlackMessagesJobTest
```

### Manual Testing

```bash
# Simulate webhook event
php artisan tinker
>>> $event = ['type' => 'message', 'text' => 'Can you update the homepage?', ...];
>>> app(SlackWebhookController::class)->events(new Request(['type' => 'event_callback', 'event' => $event]));

# Test channel matching
>>> app(SlackChannelMatcherService::class)->matchChannel(SlackChannel::first());
```

## Related Documentation

- [Integration Status](./INTEGRATIONS.md#slack)
- [AI System](./AI_INTEGRATION.md)
- [Task Management](./TASKS.md)
- [Client Health](./CLIENT_HEALTH.md)
- [Self-Healing System](./SELF_HEALING.md)
