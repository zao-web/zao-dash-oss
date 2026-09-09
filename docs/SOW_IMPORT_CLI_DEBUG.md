# SOW Import — Claude CLI Timeout Debug Report

## The Problem

The SOW Import feature uses the Claude CLI (`claude --print`) to extract structured project data from uploaded PDF documents. On production, the background job (`ProcessSowParseJob`) consistently **times out after ~5 minutes**, failing to return results.

The user's expectation: "If I dropped these PDFs into any LLM right now with the system prompt and desired output, it would take a minute or less."

## Architecture Overview

```
User uploads PDFs
  → SowImportController::parse()
    → Extracts text from PDFs (instant, ~61K chars combined)
    → Dispatches ProcessSowParseJob to queue
      → SowParsingService::extractWithAI()
        → ClaudeCliService::messageJson()
          → Runs: claude --print --output-format json --model sonnet ...
          → Pipes the user prompt via stdin
          → Parses JSON response
    → Result stored in Cache, polled by frontend
```

**Key files:**
- `app/Services/AI/ClaudeCliService.php` — Builds and executes the CLI command
- `app/Services/SowParsingService.php` — AI prompt and extraction logic
- `app/Jobs/ProcessSowParseJob.php` — Queue job wrapper

## What's Been Done

### 1. Timeout Increases (committed, deployed)
- Job timeout: 300s → **600s**
- CLI timeout: 180s → **540s** (leaves 60s buffer for job overhead)
- **Result:** Still fails. The CLI takes longer than 540s.

### 2. CLI Optimization Flags (committed as `5960dd8`, deployed)

The original command was:
```
claude --print --output-format json --model sonnet
```

This launches the **full Claude Code agent** with all tools, project scanning, and session persistence. Updated to:

```
claude --print --output-format json --model sonnet --tools "" --no-session-persistence --system-prompt '...'
```

Changes:
- **`--tools ""`** — Disables all built-in tools (Read, Edit, Bash, etc.) so the CLI acts as a simple API passthrough instead of an agent loop
- **`--no-session-persistence`** — Skips saving session state to disk
- **`--system-prompt`** — Passes system prompt as a proper CLI flag instead of inlining it in stdin with the user prompt. This ensures it's sent as an API-level system prompt.
- The `message()` method was updated to only pipe the **user prompt** via stdin (previously it concatenated system + user prompts together)

**Result:** Still fails on production.

### 3. CLAUDECODE Environment Variable Fix (committed as `189bcf4`)

When the CLI is invoked from within a Claude Code terminal session (e.g., during development or if the server process inherits the env), the `CLAUDECODE` environment variable is set, causing the CLI to refuse to run with: *"Claude Code cannot be launched inside another Claude Code session"*.

**Current code (BROKEN):**
```php
$env['CLAUDECODE'] = '';
```

**Why it's broken:** Setting the variable to an empty string still means the variable *exists* in the environment. The Claude CLI checks `if ('CLAUDECODE' in process.env)`, not whether it's truthy. Verified locally:
```bash
CLAUDECODE="" claude --print "hello"
# → "Claude Code cannot be launched inside another Claude Code session"

env -u CLAUDECODE claude --print "hello"
# → Works correctly
```

**Fix needed:** In Symfony Process (which Laravel's `Process` facade wraps), setting an env var to `false` removes it from the inherited environment:
```php
$env['CLAUDECODE'] = false;  // Actually unsets the variable
```

**Status: NOT YET FIXED in code.**

### 4. Local Debugging Findings

#### `num_turns: 11` Discovery
When running the CLI locally with `--output-format json`, the JSON response metadata showed:
```json
{
  "num_turns": 11,
  "duration_api_ms": 0
}
```

**11 agent loop turns** for what should be a single-shot prompt. Locally `duration_api_ms: 0` because API credits are exhausted, but on production each turn would be a real API call. If each API round-trip takes ~30s, that's **11 × 30s = 5.5 minutes** — exactly matching the observed timeout.

**Caveat:** The 11 turns observed locally may be retry/failure behavior caused by exhausted API credits, not necessarily what happens on production with valid credentials. With `--tools ""` and valid credits, the CLI *should* complete in 1 turn. This hasn't been verified.

#### Local Testing Limitations
- The local `ANTHROPIC_API_KEY` has no credits ("Credit balance is too low")
- No `CLAUDE_CODE_OAUTH_TOKEN` configured locally
- Cannot run end-to-end tests to verify actual behavior with working credentials

## Known Remaining Issues

### Issue 1: CLAUDECODE env var not actually unset
**File:** `app/Services/AI/ClaudeCliService.php`, `buildEnv()` method, line ~180

**Current:**
```php
$env['CLAUDECODE'] = '';
```

**Fix:**
```php
$env['CLAUDECODE'] = false;
```

This may or may not be the production issue — it depends on whether the production server's PHP process inherits a `CLAUDECODE` env var. If the job runs via Horizon/queue worker (which it does), and the worker was started from a normal shell (not from within Claude Code), this variable wouldn't be set anyway. But it's still wrong and should be fixed.

### Issue 2: Possible multi-turn behavior on production
The `num_turns: 11` finding suggests the CLI might be doing multiple API round-trips. If this is happening on production (not just a local artifact of exhausted credits), it would directly explain the timeout.

**Possible investigations:**
- Check production logs for `duration_api_ms` and `num_turns` in the CLI output
- Add logging in `ClaudeCliService::parseResponse()` to capture the full raw output before parsing
- Try the `--effort low` flag to potentially reduce turns
- Try `--output-format text` instead of `json` to see if JSON output mode affects turn behavior

### Issue 3: No way to verify locally
Without working API credentials locally, we can't confirm whether the optimizations (`--tools ""`, `--no-session-persistence`, `--system-prompt`) actually result in single-turn behavior on a real API call.

**Options:**
- Configure `CLAUDE_CODE_OAUTH_TOKEN` locally for testing
- Add sufficient API credits to the key
- Deploy and check production logs
- Write an artisan command that runs outside of Claude Code context to test

## CLI Flags Reference

From `claude --help`:
```
--tools <tools...>            Use "" to disable all tools
--no-session-persistence      Don't save sessions to disk (--print only)
--system-prompt <prompt>      System prompt for the session
--output-format <format>      "text", "json", or "stream-json" (--print only)
--model <model>               Model alias (e.g. "sonnet") or full name
--effort <level>              low, medium, high
--max-budget-usd <amount>     Max dollar spend (--print only)
--json-schema <schema>        JSON Schema for structured output validation
-p, --print                   Non-interactive mode
```

Notable: **There is no `--max-turns` flag.** Cannot directly limit agent loop iterations.

## Recommended Next Steps

1. **Fix the CLAUDECODE env var** — Change `''` to `false` in `buildEnv()`
2. **Add raw output logging** — Log the full CLI stdout before parsing so we can see `num_turns` and `duration_api_ms` on production
3. **Try `--effort low`** — May reduce internal iterations
4. **Try `--json-schema`** — Could replace `--output-format json` and force single-turn structured output
5. **Consider falling back to direct API calls** — If the CLI overhead is unavoidable, use the Anthropic API directly via HTTP for this specific use case (would require API credits, not Max subscription)
6. **Test with OAuth locally** — Configure `CLAUDE_CODE_OAUTH_TOKEN` for local end-to-end testing
