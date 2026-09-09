# Claude OAuth Token Management

This document describes the automated Claude OAuth token management system that prevents agent failures due to expired tokens.

## Overview

The Claude CLI requires an OAuth token for authentication. These tokens typically expire after 90 days. When a token expires, all agents using the Claude CLI will fail with authentication errors.

This system provides:
- **Automatic detection** of expired token errors
- **Proactive monitoring** of token expiration
- **Automated refresh attempts** when tokens expire
- **Admin notifications** when manual intervention is required
- **Circuit breaker** to prevent repeated failures

## Architecture

### Components

1. **ClaudeTokenService** (`app/Services/Agents/ClaudeTokenService.php`)
   - Core service managing token lifecycle
   - Detects expired token errors
   - Manages token metadata in cache
   - Stores tokens in Vault
   - Provides token status information

2. **RefreshClaudeTokenJob** (`app/Jobs/RefreshClaudeTokenJob.php`)
   - Queue job that attempts token refresh
   - Validates existing tokens
   - Logs detailed instructions for manual refresh
   - Retries up to 3 times with 5-minute backoff

3. **AgentExecutor Integration** (`app/Services/Agents/AgentExecutor.php`)
   - Detects token errors after agent execution
   - Triggers refresh job when errors are detected
   - Pauses execution after repeated failures

4. **Artisan Commands**
   - `claude:token-status` - Check token status and expiration
   - `claude:check-token-expiration` - Proactive expiration check (scheduled daily)

5. **Scheduled Task** (`routes/console.php`)
   - Runs daily at 6:30 AM
   - Checks token expiration proactively
   - Triggers refresh when 7 days remain

## Token Lifecycle

### Normal Operation

```
Token Created (90 days)
    ↓
Daily Checks (claude:check-token-expiration)
    ↓
83 Days: Proactive Refresh Triggered
    ↓
Token Refreshed
    ↓
Cycle Repeats
```

### Expired Token Detection

```
Agent Execution
    ↓
Error: "Invalid API key · Please run /login"
    ↓
ClaudeTokenService detects error
    ↓
Mark token as expired
    ↓
Dispatch RefreshClaudeTokenJob
    ↓
Notify admins
    ↓
Manual refresh required
```

## Token Storage

Tokens are stored in two locations:

1. **Environment Variables** (`.env`)
   ```
   CLAUDE_CODE_OAUTH_TOKEN=your_token_here
   ```

2. **Vault** (encrypted database storage)
   - Provides encrypted storage
   - Tracks expiration dates
   - Audit logging of access

## Monitoring and Status

### Check Token Status

```bash
# View current token status
php artisan claude:token-status

# Get JSON output
php artisan claude:token-status --json

# Trigger manual refresh
php artisan claude:token-status --refresh
```

### Status Output Example

```
Claude OAuth Token Status

✓ Token found: Yes
✓ Token status: Valid
  Days until expiration: 45 days
✓ Needs refresh: No
✓ Agent execution: Active
  Last refreshed: 2026-01-01 12:00:00
  Expires at: 2026-03-31 12:00:00
```

### Proactive Expiration Check

```bash
# Manually run expiration check
php artisan claude:check-token-expiration

# Force refresh even if not needed
php artisan claude:check-token-expiration --force
```

## Error Detection Patterns

The system detects the following error patterns:
- `Invalid API key`
- `Please run /login`
- `authentication failed`
- `Unauthorized`
- `invalid_api_key`
- `authentication_error`
- `token expired`

## Automatic Refresh Process

### What Happens Automatically

1. **Detection**: System detects expired token error from agent execution
2. **Marking**: Token is marked as expired in cache
3. **Job Dispatch**: RefreshClaudeTokenJob is dispatched to queue
4. **Validation**: Job attempts to validate the token
5. **Logging**: Detailed logs are written to `laravel.log` and `security` channel
6. **Retries**: Job retries up to 3 times with 5-minute backoff

### What Requires Manual Intervention

Since Claude OAuth tokens require **interactive browser authentication**, the system **cannot fully automate token refresh**. Manual intervention is required when:

1. Token validation fails
2. 3 refresh attempts have been made
3. Admin notification is sent

## Manual Token Refresh

### When to Refresh Manually

You need to manually refresh when:
- You receive admin notification about token expiration
- `claude:token-status` shows "EXPIRED" or "PAUSED"
- Agent executions are failing with authentication errors
- Logs show "manual intervention required"

### How to Refresh

**Option 1: Via Claude CLI (Recommended)**

```bash
# SSH into the production server
ssh your-server

# Run the token setup command
claude setup-token

# Follow the browser authentication flow
# The new token will be displayed
```

**Option 2: Via Laravel Tinker**

```bash
# After obtaining the new token via CLI
php artisan tinker

# Store the token
$service = app(\App\Services\Agents\ClaudeTokenService::class);
$service->storeToken('your_new_token_here');
exit;
```

**Option 3: Update Environment Variable**

```bash
# Edit .env file
nano .env

# Update the token
CLAUDE_CODE_OAUTH_TOKEN=your_new_token_here

# Clear config cache
php artisan config:clear
```

### After Manual Refresh

1. Verify the token works:
   ```bash
   php artisan claude:token-status
   ```

2. Reset any circuit breakers if needed (agents automatically reset after 1 hour)

3. Test agent execution to confirm resolution

## Circuit Breaker Protection

### Purpose

Prevents repeated agent failures from overwhelming the system when token is expired.

### Behavior

- After 3 failed token refresh attempts, agent execution is **paused**
- Status shows: "Agent execution: PAUSED"
- All agent runs will be blocked until token is manually refreshed

### Recovery

1. Manually refresh the token (see above)
2. Verify with `claude:token-status`
3. Circuit breaker automatically clears when token is refreshed
4. Agent execution resumes normally

## Scheduled Monitoring

The system runs proactive checks daily:

```php
// routes/console.php
Schedule::command('claude:check-token-expiration')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->name('claude-token-check');
```

This ensures:
- Tokens are checked before expiration
- Refresh is triggered with 7 days remaining
- Reduces likelihood of unexpected failures

## Logging and Auditing

### Log Channels

**Standard Logging** (`storage/logs/laravel.log`)
- Token expiration detection
- Refresh job execution
- Status changes
- Validation results

**Security Channel** (`storage/logs/security.log` if configured)
- Critical token expiration events
- Manual intervention requirements
- Token access attempts
- Admin notifications

### Key Log Messages

```
Claude OAuth token has expired
  action: initiating_refresh

Starting Claude OAuth token refresh job
  attempt: 1
  max_tries: 3

Token validation failed - authentication error detected
  output: Invalid API key

Max token refresh attempts reached - manual intervention required
  attempts: 3
  instructions: [detailed steps]
```

## Admin Notifications

### When Notifications Are Sent

1. Token expires unexpectedly (not caught by proactive checks)
2. Detected from agent execution failure
3. Logged to security channel

### Who Gets Notified

- All users with `is_admin = true`
- Configured via `User` model

### Notification Content

- Token expiration details
- Impact on agent execution
- Manual refresh instructions
- Links to documentation

## Testing

### Run Token Service Tests

```bash
# Run all token service tests
php artisan test tests/Unit/Services/Agents/ClaudeTokenServiceTest.php

# Run specific test
php artisan test --filter="detects expired token errors"
```

### Test Coverage

- Error pattern detection
- Token expiration logic
- Metadata management
- Refresh attempt counting
- Circuit breaker logic
- Vault storage integration

## Configuration

### Token Expiration Settings

Located in `ClaudeTokenService`:

```php
protected const TOKEN_EXPIRATION_DAYS = 90;        // Token lifetime
protected const TOKEN_REFRESH_THRESHOLD_DAYS = 7;  // When to refresh proactively
```

### Cache Configuration

Token metadata is cached using Laravel's cache system:

```php
Cache::put('claude_token_metadata', $metadata, now()->addDays(30));
```

## Troubleshooting

### Token Status Shows "No token found"

**Solution:**
1. Check `.env` file for `CLAUDE_CODE_OAUTH_TOKEN`
2. Run `claude setup-token` to create new token
3. Verify with `claude:token-status`

### Token Shows Valid But Agents Still Fail

**Possible Causes:**
1. Token in cache is different from actual token
2. Environment config cache needs clearing
3. Token was recently revoked

**Solution:**
```bash
php artisan config:clear
php artisan cache:clear
php artisan claude:token-status --refresh
```

### "Agent execution: PAUSED"

**Solution:**
1. Manually refresh the token
2. Verify with `claude:token-status`
3. Circuit breaker will automatically clear
4. Test agent execution

### Refresh Job Fails Repeatedly

**Check:**
1. Claude CLI is installed: `which claude`
2. Token in environment is correct
3. Server can access Claude API
4. No network/firewall issues

**Debug:**
```bash
# View recent logs
tail -n 100 storage/logs/laravel.log | grep -i "claude.*token"

# Check queue worker status
php artisan queue:work --once --queue=default
```

## Best Practices

### For System Administrators

1. **Monitor Logs**: Check security logs weekly for token warnings
2. **Set Reminders**: Calendar reminder 80 days after last token refresh
3. **Test Refresh Flow**: Practice token refresh process quarterly
4. **Document Tokens**: Keep secure record of when tokens were last refreshed
5. **Enable Notifications**: Ensure admin users receive notifications

### For Developers

1. **Check Status First**: Always run `claude:token-status` when debugging agent issues
2. **Test Locally**: Test token expiration detection with mocked errors
3. **Review Logs**: Check logs after agent failures for token errors
4. **Update Tests**: Add tests when modifying token-related code

### For Deployment

1. **Verify Token**: Check token status after deployment
2. **Clear Caches**: Run `php artisan config:clear` after token updates
3. **Monitor First Run**: Watch agent execution closely after deployment
4. **Document Changes**: Note token refresh in deployment logs

## API Reference

### ClaudeTokenService

```php
// Check if error indicates token expiration
$service->isTokenExpiredError(string $error): bool

// Check if result contains token error
$service->isTokenExpiredFromResult(array $result): bool

// Handle expired token (dispatch job, notify admins)
$service->handleExpiredToken(): void

// Check if token needs refresh
$service->needsRefresh(): bool

// Get comprehensive token status
$service->getTokenStatus(): array

// Store new token in Vault
$service->storeToken(string $token, ?Carbon $expiresAt = null): bool

// Get current token from environment or Vault
$service->getCurrentToken(): ?string
```

### Artisan Commands

```bash
# Check token status
claude:token-status [--json] [--refresh]

# Check expiration (scheduled daily)
claude:check-token-expiration [--force]
```

## Related Documentation

- [Agent Execution](./AGENTIC_WORKFLOWS.md)
- [Vault System](./vault-system.md)
- [Error Handling](./error-handling.md)
- [Scheduled Tasks](./scheduled-tasks.md)

## Support

For issues or questions:
1. Check logs in `storage/logs/laravel.log`
2. Review this documentation
3. Run `claude:token-status --json` for detailed status
4. Contact system administrator if manual refresh is needed
