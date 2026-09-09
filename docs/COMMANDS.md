# Artisan Commands Reference

Quick reference for all Zao Dash CLI commands.

---

## Agent Commands

### `agents:sync`

Sync PHP agent definitions to the database. **Run after creating/modifying agents.**

```bash
php artisan agents:sync
```

This reads all `app/Agents/Definitions/*Agent.php` files and upserts them to the `agents` table.

---

### `agents:run-scheduled`

Execute agents whose schedule is due. Normally runs via cron every minute.

```bash
# Manual execution (checks all agent schedules)
php artisan agents:run-scheduled

# With verbosity
php artisan agents:run-scheduled -v
```

---

### `agents:chain`

Run or manage agent chains (multi-agent workflows).

```bash
# Run a chain
php artisan agents:chain run weekly-review

# List available chains
php artisan agents:chain list

# Show chain definition
php artisan agents:chain show weekly-review
```

---

### `agents:chain-status`

Check status of a running or completed chain.

```bash
php artisan agents:chain-status {chain_run_id}
```

---

### `make:agent`

Scaffold a new agent with best practices.

```bash
php artisan make:agent LeadScoringAgent

# Creates:
# - app/Agents/Definitions/LeadScoringAgent.php
# - storage/app/skills/lead-scoring/SKILL.md
```

---

### `agents:test-pipeline`

End-to-end test of agent execution pipeline. Useful for debugging.

```bash
php artisan agents:test-pipeline

# Test specific agent
php artisan agents:test-pipeline --agent=business-strategist
```

---

### `agents:test-sdk`

Test Claude Agent SDK (API-based execution) independently.

```bash
php artisan agents:test-sdk
```

---

## Health & Monitoring

### `health:process-escalations`

Process unacknowledged health alerts and escalate. Runs automatically every 15 minutes.

```bash
php artisan health:process-escalations
```

---

### `notifications:test`

Send a test notification to verify real-time broadcasting.

```bash
php artisan notifications:test

# Specify channel
php artisan notifications:test --channel=broadcast
```

---

## Common Operations

### Run a Specific Agent Manually

```bash
# Using tinker
php artisan tinker

>>> $agent = Agent::where('slug', 'business-strategist')->first();
>>> app(\App\Agents\ClaudeCliRunner::class)->execute($agent, 'Analyze current goal progress');
```

### Check Agent Status

```bash
php artisan tinker

>>> Agent::all(['slug', 'status', 'last_run_at', 'circuit_broken_at'])->toArray();
```

### Reset Circuit Breaker

```bash
php artisan tinker

>>> Agent::where('slug', 'your-agent')->update(['circuit_broken_at' => null]);
```

---

## Scheduled Jobs

These run automatically via Laravel's scheduler. Ensure cron is configured:

```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

| Job | Frequency | Purpose |
|-----|-----------|---------|
| `agents:run-scheduled` | Every minute | Trigger due agents |
| Expire approvals | Every 5 min | Expire old approval requests |
| `ProcessAgentTasksJob` | Every 5 min | Execute delegated agent tasks |
| `health:process-escalations` | Every 15 min | Escalate health alerts |
| Circuit breaker reset | Hourly | Reset old circuit breaks |
| `UpdateGoalProgressJob` | Hourly | Update goal period actuals |
| `CalculateFunnelMetricsJob` | Daily 11:55pm | Snapshot funnel metrics |

---

## Database Commands

### Run Migrations

```bash
php artisan migrate
```

### Fresh Install (Destroys Data)

```bash
php artisan migrate:fresh --seed
```

### Check Migration Status

```bash
php artisan migrate:status
```

---

## Queue Commands

### Process Jobs

```bash
# Single worker
php artisan queue:work

# With specific queue
php artisan queue:work --queue=agents,default

# Horizon (recommended for production)
php artisan horizon
```

### Failed Jobs

```bash
# List failed jobs
php artisan queue:failed

# Retry specific job
php artisan queue:retry {id}

# Retry all
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

---

## Cache Commands

```bash
# Clear all caches
php artisan optimize:clear

# Rebuild caches
php artisan optimize

# Clear specific caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

---

## Development Commands

### Start Development Server

```bash
# Laravel server
php artisan serve

# Vite dev server (separate terminal)
npm run dev

# Or use the combined script if available
composer run dev
```

### Generate IDE Helpers

```bash
php artisan ide-helper:generate
php artisan ide-helper:models -N
```

---

## Troubleshooting

### Agent Not Running

1. Check agent is synced: `php artisan agents:sync`
2. Check schedule is valid: Review `getSchedule()` in agent definition
3. Check circuit breaker: `Agent::where('slug', '...')->value('circuit_broken_at')`
4. Check for errors in `agent_runs` table

### Jobs Not Processing

1. Ensure queue worker is running: `php artisan queue:work`
2. Check Redis connection: `php artisan tinker` → `Redis::ping()`
3. Review failed jobs: `php artisan queue:failed`

### API Rate Limits

If seeing Anthropic rate limits:
1. Check agent budgets aren't exhausted
2. Review recent `agent_runs` for high token usage
3. Consider increasing delay between agent runs

---

## Quick Reference Card

```bash
# Daily operations
php artisan agents:sync              # After modifying agents
php artisan queue:work               # Process jobs
php artisan horizon                  # Production queue (if using Horizon)

# Debugging
php artisan agents:test-pipeline     # Test agent execution
php artisan notifications:test       # Test real-time notifications
php artisan tinker                   # Interactive shell

# Maintenance
php artisan optimize:clear           # Clear all caches
php artisan migrate                  # Run new migrations
php artisan schedule:list            # View scheduled tasks
```
