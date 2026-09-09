# Self-Healing System

## Overview

The self-healing system automatically detects production errors from Nightwatch alerts in Slack and uses Claude to attempt fixes.

## Architecture

```
Nightwatch → Slack Channel → Webhook → Detection → AI Fix → Deploy
             #ops-logs              ↓
                                        SelfHealingJob
                                              ↓
                                        Claude CLI Agent
                                              ↓
                                        Git commit/push
```

## Requirements

### 1. Environment Variables

```env
# Enable the system
SELF_HEALING_ENABLED=true

# Slack channel ID where Nightwatch posts errors
SELF_HEALING_SLACK_CHANNEL_ID=C00EXAMPLE03

# Nightwatch bot's Slack user/bot ID
NIGHTWATCH_BOT_ID=U0A45JRRN2F
```

### 2. Slack Bot Must Be in the Channel

**Critical**: The Slack bot must be a member of `#ops-logs` for Slack to send message events to our webhook.

To add the bot:
1. Open `#ops-logs` in Slack
2. Type `/invite @YourBotName` or click the channel settings
3. Add your Slack app's bot user to the channel

Without this, Slack won't send events and self-healing will never trigger.

### 3. Slack App Event Subscriptions

The Slack app must have these event subscriptions:
- `message.channels` - Messages in public channels
- `message.groups` - Messages in private channels (if using private channel)

## How It Works

### Detection Flow

1. **Webhook receives Slack event** from any workspace
2. **Channel check**: Is this from the configured self-healing channel?
3. **Bot ID check**: Is this from Nightwatch? (or has error attachments)
4. **Error markers check**: Does the message contain `Exception`, `Error`, `SQLSTATE`, or error attachments?
5. **Safety rails**: Check deduplication, rate limits, circuit breaker
6. **Dispatch job**: Queue `SelfHealingJob` with parsed error details

### Safety Rails

| Rail | Purpose | Config |
|------|---------|--------|
| Deduplication | Prevent fixing same error twice | `success_cooldown_hours: 24` |
| Rate limits | Prevent runaway fixes | `5/hour, 15/day` |
| Circuit breaker | Pause after consecutive failures | `3 failures → 60min pause` |
| Skip rules | Ignore infrastructure errors | Database connection, Redis, etc. |

### AI Fix Process

1. Job creates `SelfHealingAttempt` record
2. Posts "Analyzing error..." to Slack thread
3. Spawns Claude CLI with error context
4. Claude analyzes, proposes fix, implements
5. If changes made: commits and pushes
6. Updates Slack thread with result
7. If failed: escalates to human

## Debugging

### Enable Debug Logging

The system logs at `debug` level. Ensure your log level includes debug:

```env
LOG_LEVEL=debug
```

### Key Log Messages

```
# Every message check
Self-healing: Checking message {configured_channel, event_channel, event_bot_id}

# Channel mismatch (most common issue)
Self-healing: Channel mismatch {expected, got}

# Bot ID verification
Self-healing: isNightwatchEvent check {configured_bot_id, event_bot_id}
Self-healing: Bot ID mismatch and no attachments

# Error detection
Self-healing: Error markers check {has_error_markers, contains_*}

# Successful detection
Self-healing: Nightwatch error detected {exception, message, signature}

# Safety rails
Self-healing: Skipping fix attempt (safety rails)
Self-healing disabled
Self-healing: Already being fixed
Self-healing: Recently fixed
Self-healing: Rate limit exceeded
Self-healing: Circuit breaker open

# Job dispatch
Self-healing: Dispatched SelfHealingJob
```

### Common Issues

#### Events Not Reaching Webhook
1. **Bot not in channel**: Add bot to `#ops-logs`
2. **Missing event subscription**: Add `message.channels` to Slack app
3. **Wrong channel ID**: Verify `SELF_HEALING_SLACK_CHANNEL_ID` is correct

To find channel ID: Right-click channel in Slack → "Copy link" → ID is the `C...` part

#### Events Received But Not Triggering
1. Check `SELF_HEALING_ENABLED=true`
2. Verify `NIGHTWATCH_BOT_ID` matches actual bot
3. Check message contains error markers (Exception, Error, SQLSTATE)
4. Review debug logs for specific failure point

#### Rate Limiting
```bash
php artisan tinker
>>> app(App\Services\SelfHealing\SelfHealingService::class)->getRateLimitStatus();
>>> app(App\Services\SelfHealing\SelfHealingService::class)->getStats(24);
```

#### Circuit Breaker Open
If too many consecutive failures, the circuit breaker opens:
```bash
php artisan tinker
>>> app(App\Services\SelfHealing\SelfHealingService::class)->isCircuitBreakerOpen();
```

Wait for cooldown (default 60 minutes) or clear recent failed attempts.

## Testing

### Simulate an Error Event
```bash
php artisan tinker
>>> $event = [
    'type' => 'message',
    'channel' => config('self-healing.slack_channel_id'),
    'bot_id' => config('self-healing.nightwatch_bot_id'),
    'text' => 'QueryException: SQLSTATE[23000]: Integrity constraint violation',
    'ts' => now()->timestamp . '.000000',
];
>>> app(App\Services\SelfHealing\NightwatchErrorDetector::class)->isNightwatchEvent($event);
>>> app(App\Services\SelfHealing\NightwatchErrorDetector::class)->parseFromEvent($event);
```

### Manual Job Dispatch
```bash
php artisan tinker
>>> $error = new App\DTOs\NightwatchError(
    exceptionClass: 'QueryException',
    message: 'SQLSTATE[23000]: Integrity constraint violation',
    file: 'app/Services/Example.php',
    line: 42,
    slackMessageTs: '1234567890.000000',
    slackChannelId: config('self-healing.slack_channel_id'),
);
>>> App\Jobs\SelfHealingJob::dispatch($error);
```

## Configuration Reference

```php
// config/self-healing.php

return [
    'enabled' => env('SELF_HEALING_ENABLED', false),
    'slack_channel_id' => env('SELF_HEALING_SLACK_CHANNEL_ID', ''),
    'nightwatch_bot_id' => env('NIGHTWATCH_BOT_ID', ''),

    'rate_limits' => [
        'per_hour' => 5,
        'per_day' => 15,
    ],

    'circuit_breaker' => [
        'failure_threshold' => 3,
        'cooldown_minutes' => 60,
    ],

    'deduplication' => [
        'block_concurrent' => true,
        'success_cooldown_hours' => 24,
        'failure_cooldown_hours' => 6,
    ],

    'skip_rules' => [
        'exception_patterns' => [
            'ConnectionException',
            'RedisException',
            'PDOException.*Connection refused',
        ],
    ],
];
```

## Related Files

- `app/Http/Controllers/SlackWebhookController.php` - Webhook handler
- `app/Services/SelfHealing/NightwatchErrorDetector.php` - Error parsing
- `app/Services/SelfHealing/SelfHealingService.php` - Safety rails
- `app/Jobs/SelfHealingJob.php` - AI fix execution
- `app/DTOs/NightwatchError.php` - Error data structure
- `app/Models/SelfHealingAttempt.php` - Attempt tracking
