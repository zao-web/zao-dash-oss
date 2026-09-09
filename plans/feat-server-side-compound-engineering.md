# feat: Server-Side Compound Engineering Integration

Transform Zao Dash into a web-accessible Claude Code environment with real-time interactive capabilities via Laravel Reverb.

---

## Enhancement Summary

**Deepened on:** 2026-01-19
**Sections enhanced:** 5 phases + architecture
**Research agents used:** 12 (architecture-strategist, performance-oracle, security-sentinel, code-simplicity-reviewer, data-integrity-guardian, kieran-typescript-reviewer, julik-frontend-races-reviewer, agent-native-reviewer, best-practices-researcher, framework-docs-researcher, Context7 queries)

### Key Improvements Discovered

1. **Checkpoint-Resume Pattern** - Instead of blocking queue workers with Redis pub/sub (which ties up workers for 60+ minutes), use a checkpoint-resume pattern where jobs save state and exit, then new jobs are dispatched when responses arrive. This dramatically improves worker utilization.

2. **Symfony InputStream Class** - The solution to stdin piping is using `Symfony\Component\Process\InputStream` directly rather than Laravel's Process facade. This allows writing to stdin after a process has started.

3. **Claude CLI Stream Format** - Confirmed that `--output-format stream-json` emits JSONL with `tool_use` blocks BEFORE tool execution, allowing detection and pause of `AskUserQuestion` calls.

4. **State Machine for Frontend** - Race conditions between tabs and Slack require a proper state machine (PENDING → SUBMITTING → SUBMITTED → RESPONDED_ELSEWHERE/EXPIRED) with `BroadcastChannel` API for tab coordination.

5. **MCP Tools for Agent-Native** - Need `respond-to-interaction` and `list-pending-interactions` MCP tools so other Claude sessions can interact with running agents.

### Critical Discoveries

- **Row-Level Locking Required**: Use `lockForUpdate()` inside transactions to prevent race conditions when Slack and Dashboard respond simultaneously
- **Idempotency Keys**: Client should generate idempotency keys to handle network retries safely
- **450+ LOC Reduction**: Can reuse `ApprovalRequest` model patterns and existing broadcast event structures
- **Worker Pool Sizing**: 10 concurrent sessions at 30MB each = 300MB baseline memory; need dedicated queue for interactive jobs

---

## Overview

Enable triggering Compound Engineering workflows (`/workflows:plan`, `/workflows:work`, `/workflows:review`, `/lfg`) from Slack, the dashboard, and MCP tools. When Claude needs user input (via `AskUserQuestion`), broadcast the question as a real-time popup to the dashboard, collect the response, and pipe it back to the running process.

## Problem Statement

Currently, Claude Code and the Compound Engineering Plugin only work interactively in a terminal. This limits:
- **Accessibility**: Users must have local Claude Code setup
- **Collaboration**: No visibility into agent progress for team members
- **Mobile/Remote**: Can't trigger or interact with agents from phone or remote
- **Integration**: Existing Slack bot can trigger simple agents but can't handle interactive Claude Code sessions

The goal is to bring the full power of Compound Engineering to the web interface while maintaining the interactive question/answer flow that makes it effective.

## Proposed Solution

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           ENTRY POINTS                                       │
├─────────────────────────────────────────────────────────────────────────────┤
│  Slack @mention          Dashboard Button         MCP trigger-agent         │
│  "run /workflows:plan"   CommandPalette          External Claude Code       │
└────────────┬─────────────────────┬─────────────────────┬────────────────────┘
             │                     │                     │
             ▼                     ▼                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    ORCHESTRATION LAYER (Laravel)                             │
├─────────────────────────────────────────────────────────────────────────────┤
│  CompoundEngineeringService                                                  │
│  ├── parseSkillInvocation(prompt) -> skill, args                            │
│  ├── loadSkillDefinition(skill) -> system prompt, tools                     │
│  ├── createAgentRun(agent, config, channel_context)                         │
│  └── dispatchInteractiveJob(run)                                            │
└────────────────────────────────────┬────────────────────────────────────────┘
                                     │
                                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    EXECUTION LAYER (Queue Worker)                            │
├─────────────────────────────────────────────────────────────────────────────┤
│  RunInteractiveAgentJob (CHECKPOINT-RESUME PATTERN)                          │
│  ├── InteractiveClaudeRunner                                                 │
│  │   ├── startProcess() with Symfony InputStream                            │
│  │   ├── parseStreamingOutput() for tool_use detection                      │
│  │   └── handleAskUserQuestion() -> checkpoint, broadcast, EXIT JOB         │
│  └── ResumeInteractiveAgentJob dispatched when response arrives             │
└────────────────────────────────────┬────────────────────────────────────────┘
                                     │
              ┌──────────────────────┴──────────────────────┐
              ▼                                              ▼
┌────────────────────────────┐              ┌────────────────────────────────┐
│     REVERB (WebSocket)     │              │         SLACK API              │
│  Private channel:          │              │  Post question to thread       │
│  user.{id}.interactions    │              │  with interactive buttons      │
└──────────────┬─────────────┘              └───────────────┬────────────────┘
               │                                             │
               ▼                                             ▼
┌────────────────────────────┐              ┌────────────────────────────────┐
│    DASHBOARD (Vue.js)      │              │      SLACK CLIENT              │
│  InteractionModal.vue      │              │  Button click callback         │
│  State Machine Pattern     │              │  routes to API                 │
└──────────────┬─────────────┘              └───────────────┬────────────────┘
               │                                             │
               └──────────────────────┬──────────────────────┘
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    RESPONSE HANDLER (Laravel)                                │
├─────────────────────────────────────────────────────────────────────────────┤
│  InteractionResponseController                                               │
│  ├── validateResponse(interaction_id, response, idempotency_key)            │
│  ├── lockForUpdate() + transaction for race prevention                      │
│  ├── storeResponse(interaction, response, responded_by)                     │
│  ├── broadcastResponseReceived()                                            │
│  └── dispatchResumeJob(interaction.agent_run_id, response)                  │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Research Insight: Checkpoint-Resume vs. Blocking

**Original Approach (Problematic):**
```php
// ❌ This blocks a queue worker for 60+ minutes
$process->start();
while (!$interaction->fresh()->responded_at) {
    Redis::subscribe(['interaction.'.$id], fn() => /* wake up */);
    usleep(100000);
}
```

**Improved Approach (Checkpoint-Resume):**
```php
// ✅ Job saves state and exits; new job resumes
class RunInteractiveAgentJob implements ShouldQueue
{
    public function handle()
    {
        $runner = new InteractiveClaudeRunner($this->run);
        $result = $runner->runUntilInteractionOrComplete();

        if ($result->needsInteraction) {
            // Save checkpoint and exit - worker is freed
            $this->run->update([
                'status' => 'awaiting_input',
                'checkpoint' => $result->checkpoint,
            ]);
            InteractionRequestCreated::dispatch($result->interaction);
            return; // Job completes, worker freed
        }

        // Process completed normally
        $this->run->update(['status' => 'completed', 'output' => $result->output]);
    }
}

class ResumeInteractiveAgentJob implements ShouldQueue
{
    public function __construct(public InteractionRequest $interaction) {}

    public function handle()
    {
        $runner = InteractiveClaudeRunner::fromCheckpoint(
            $this->interaction->agentRun->checkpoint,
            $this->interaction->response
        );
        // Continue execution...
    }
}
```

---

## Technical Approach

### Phase 1: Foundation - Interactive Process Runner

**Goal:** Enable Claude CLI to run interactively with stdin/stdout pipes using Symfony InputStream.

#### Tasks

- [ ] **Create InteractionRequest model** (`app/Models/InteractionRequest.php`)
  - Fields: `id`, `agent_run_id`, `responded_by_id` (FK), `question_type` (text, select, confirm), `question_content`, `options` (nullable JSON), `context` (nullable JSON), `response`, `responded_at`, `expires_at`, `responded_via` (dashboard, slack), `idempotency_key` (unique, nullable)
  - Relationships: `belongsTo(AgentRun)`, `belongsTo(User, 'responded_by_id')`
  - Scopes: `pending()`, `expired()`, `awaitingResponse()`

- [ ] **Create migration** (`database/migrations/xxx_create_interaction_requests_table.php`)
  ```php
  Schema::create('interaction_requests', function (Blueprint $table) {
      $table->id();
      $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
      $table->foreignId('responded_by_id')->nullable()->constrained('users')->nullOnDelete();
      $table->string('question_type')->default('text'); // text, select, confirm
      $table->text('question_content');
      $table->json('options')->nullable(); // for select type
      $table->json('context')->nullable(); // additional context for display
      $table->text('response')->nullable();
      $table->timestamp('responded_at')->nullable();
      $table->string('responded_via')->nullable(); // dashboard, slack
      $table->string('idempotency_key')->nullable()->unique();
      $table->timestamp('expires_at');
      $table->timestamps();

      // Research Insight: Index for common queries
      $table->index(['agent_run_id', 'responded_at']);
      $table->index(['expires_at', 'responded_at']); // For cleanup job
  });
  ```

- [ ] **Create InteractiveClaudeRunner** (`app/Services/Agents/InteractiveClaudeRunner.php`)

  **Research Insight: Use Symfony InputStream for stdin piping**
  ```php
  use Symfony\Component\Process\Process;
  use Symfony\Component\Process\InputStream;

  class InteractiveClaudeRunner
  {
      private Process $process;
      private InputStream $input;
      private string $outputBuffer = '';

      public function start(string $prompt, array $options = []): void
      {
          $this->input = new InputStream();

          $command = [
              'claude',
              '--print',
              '--output-format', 'stream-json',
              '--dangerously-skip-permissions', // Research: Needs security review
          ];

          $this->process = new Process($command);
          $this->process->setInput($this->input);
          $this->process->setTimeout(null); // No timeout, we manage it
          $this->process->start();

          // Write initial prompt
          $this->input->write($prompt . "\n");
      }

      public function runUntilInteractionOrComplete(): RunResult
      {
          while ($this->process->isRunning()) {
              $output = $this->process->getIncrementalOutput();
              $this->outputBuffer .= $output;

              // Parse JSONL lines for tool_use
              foreach ($this->parseJsonLines($output) as $event) {
                  if ($this->isAskUserQuestion($event)) {
                      return RunResult::needsInteraction(
                          $this->createCheckpoint(),
                          $this->parseQuestionFromEvent($event)
                      );
                  }
              }

              usleep(10000); // 10ms polling
          }

          return RunResult::completed($this->outputBuffer);
      }

      public function resumeWithResponse(string $response): void
      {
          // Research: Write response to stdin
          $this->input->write($response . "\n");
      }

      public function close(): void
      {
          $this->input->close();
      }
  }
  ```

- [ ] **Create broadcast events**
  - `InteractionRequestCreated` - pushes question to frontend
  - `InteractionResponseReceived` - acknowledges answer received, notifies other tabs

- [ ] **Add status to AgentRun** - new status `awaiting_input` in addition to running, completed, failed

- [ ] **Add checkpoint column to AgentRun**
  ```php
  $table->json('checkpoint')->nullable(); // Stores process state for resume
  ```

#### Research Insights for Phase 1

**Best Practices:**
- Use `InputStream` class for stdin - it allows writing after process starts
- Stream-json format emits `tool_use` blocks BEFORE execution, allowing interception
- Store checkpoints as JSON with: prompt history, current output, tool call context

**Performance Considerations:**
- 10 concurrent sessions × 30MB each = 300MB baseline memory
- Use dedicated queue (`interactive-agents`) with `--memory=512` workers
- Monitor with `$process->getIncrementalOutput()` to avoid memory bloat

**Edge Cases:**
- Process dies unexpectedly: Detect via `$process->isRunning()`, mark run as failed
- Multiple `AskUserQuestion` in rapid succession: Queue them, process sequentially
- Network disconnect during response: Idempotency key handles retry

**Security Considerations:**
- `--dangerously-skip-permissions` bypasses safety - needs audit
- Sanitize user responses before piping to stdin
- Consider allowlist for tool permissions per skill

---

### Phase 2: Real-Time Broadcasting & Frontend

**Goal:** Display interactive questions as dashboard modals with proper race condition handling.

#### Tasks

- [ ] **Create InteractionRequestCreated event** (`app/Events/InteractionRequestCreated.php`)
  ```php
  class InteractionRequestCreated implements ShouldBroadcast
  {
      public function __construct(
          public InteractionRequest $interaction,
          public int $userId,
      ) {}

      public function broadcastOn(): array
      {
          return [new PrivateChannel("user.{$this->userId}.interactions")];
      }

      public function broadcastWith(): array
      {
          return [
              'interaction_id' => $this->interaction->id,
              'run_id' => $this->interaction->agent_run_id,
              'question_type' => $this->interaction->question_type,
              'question' => $this->interaction->question_content,
              'options' => $this->interaction->options,
              'context' => $this->interaction->context,
              'expires_at' => $this->interaction->expires_at->toISOString(),
          ];
      }
  }
  ```

- [ ] **Add channel authorization** (`routes/channels.php`)
  ```php
  Broadcast::channel('user.{userId}.interactions', function (User $user, int $userId) {
      return $user->id === $userId;
  });
  ```

- [ ] **Create useInteractionRealtime composable** (`resources/js/composables/useInteractionRealtime.ts`)

  **Research Insight: State Machine Pattern with Tab Coordination**
  ```typescript
  import { ref, computed, onMounted, onUnmounted } from 'vue'
  import type { Ref } from 'vue'

  // Discriminated union for type safety
  type InteractionState =
    | { status: 'idle' }
    | { status: 'pending'; interaction: Interaction; expiresAt: Date }
    | { status: 'submitting'; interaction: Interaction; idempotencyKey: string }
    | { status: 'submitted'; interaction: Interaction }
    | { status: 'responded_elsewhere'; interaction: Interaction }
    | { status: 'expired'; interaction: Interaction }

  interface Interaction {
    id: number
    runId: number
    questionType: 'text' | 'select' | 'confirm'
    question: string
    options?: Array<{ label: string; value: string }>
    context?: Record<string, unknown>
  }

  export function useInteractionRealtime(userId: number) {
    const state: Ref<InteractionState> = ref({ status: 'idle' })
    const queue = ref<Interaction[]>([])

    // BroadcastChannel for tab coordination
    let tabChannel: BroadcastChannel | null = null

    onMounted(() => {
      // Tab coordination channel
      tabChannel = new BroadcastChannel('zao-interactions')
      tabChannel.onmessage = (event) => {
        if (event.data.type === 'RESPONDED' &&
            state.value.status === 'pending' &&
            state.value.interaction.id === event.data.interactionId) {
          state.value = {
            status: 'responded_elsewhere',
            interaction: state.value.interaction
          }
        }
      }

      // Echo channel subscription
      window.Echo.private(`user.${userId}.interactions`)
        .listen('InteractionRequestCreated', handleNewInteraction)
        .listen('InteractionResponseReceived', handleResponseReceived)
    })

    onUnmounted(() => {
      tabChannel?.close()
      window.Echo.leave(`user.${userId}.interactions`)
    })

    function handleNewInteraction(data: any) {
      const interaction: Interaction = {
        id: data.interaction_id,
        runId: data.run_id,
        questionType: data.question_type,
        question: data.question,
        options: data.options,
        context: data.context,
      }

      if (state.value.status === 'idle') {
        state.value = {
          status: 'pending',
          interaction,
          expiresAt: new Date(data.expires_at),
        }
      } else {
        queue.value.push(interaction)
      }
    }

    async function submitResponse(response: string) {
      if (state.value.status !== 'pending') return

      const idempotencyKey = crypto.randomUUID()
      const interaction = state.value.interaction

      state.value = { status: 'submitting', interaction, idempotencyKey }

      try {
        await fetch(`/api/interactions/${interaction.id}/respond`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Idempotency-Key': idempotencyKey,
          },
          body: JSON.stringify({ response }),
        })

        state.value = { status: 'submitted', interaction }

        // Notify other tabs
        tabChannel?.postMessage({
          type: 'RESPONDED',
          interactionId: interaction.id
        })

        // Process queue
        setTimeout(processQueue, 500)
      } catch (error) {
        // Handle error, allow retry
        state.value = {
          status: 'pending',
          interaction,
          expiresAt: new Date() // Recalculate
        }
      }
    }

    function processQueue() {
      if (queue.value.length > 0) {
        const next = queue.value.shift()!
        state.value = {
          status: 'pending',
          interaction: next,
          expiresAt: new Date(Date.now() + 60 * 60 * 1000), // Default 1 hour
        }
      } else {
        state.value = { status: 'idle' }
      }
    }

    return {
      state,
      queueLength: computed(() => queue.value.length),
      submitResponse,
      dismiss: () => processQueue(),
    }
  }
  ```

- [ ] **Create InteractionModal component** (`resources/js/Components/InteractionModal.vue`)
  - Full-screen modal overlay with state-aware rendering
  - Display question with context
  - Render appropriate input based on question_type:
    - `text`: textarea with submit
    - `select`: radio buttons with options
    - `confirm`: Yes/No buttons
  - Show agent run context (agent name, current task)
  - Countdown timer to expiration
  - Loading state during submission
  - "Answered elsewhere" state handling

- [ ] **Create InteractionResponseController** (`app/Http/Controllers/Api/InteractionResponseController.php`)

  **Research Insight: Row-Level Locking for Race Prevention**
  ```php
  public function respond(Request $request, InteractionRequest $interaction)
  {
      $validated = $request->validate([
          'response' => 'required|string|max:10000',
      ]);

      $idempotencyKey = $request->header('X-Idempotency-Key');

      return DB::transaction(function () use ($interaction, $validated, $idempotencyKey, $request) {
          // Lock the row to prevent race conditions
          $interaction = InteractionRequest::lockForUpdate()->find($interaction->id);

          // Check idempotency - same key means retry, return success
          if ($idempotencyKey && $interaction->idempotency_key === $idempotencyKey) {
              return response()->json(['success' => true, 'was_retry' => true]);
          }

          if ($interaction->responded_at) {
              return response()->json([
                  'error' => 'Already responded',
                  'responded_via' => $interaction->responded_via,
              ], 409);
          }

          if ($interaction->expires_at < now()) {
              return response()->json(['error' => 'Interaction expired'], 410);
          }

          $interaction->update([
              'response' => $validated['response'],
              'responded_at' => now(),
              'responded_via' => 'dashboard',
              'responded_by_id' => $request->user()->id,
              'idempotency_key' => $idempotencyKey,
          ]);

          // Dispatch resume job
          ResumeInteractiveAgentJob::dispatch($interaction);

          // Broadcast to close modals in other tabs/sessions
          InteractionResponseReceived::dispatch($interaction);

          return response()->json(['success' => true]);
      });
  }
  ```

- [ ] **Add API route** (`routes/api.php`)
  ```php
  Route::post('/interactions/{interaction}/respond', [InteractionResponseController::class, 'respond'])
      ->middleware(['auth:sanctum', 'throttle:60,1']);
  ```

- [ ] **Integrate InteractionModal into main layout**
  - Add to `AuthenticatedLayout.vue`
  - Use composable to show/hide based on pending interactions
  - Auto-focus when interaction arrives

#### Research Insights for Phase 2

**Best Practices:**
- Use discriminated unions in TypeScript for exhaustive state handling
- Generate idempotency keys client-side (crypto.randomUUID())
- Use BroadcastChannel API for tab coordination (same origin)

**Performance Considerations:**
- Debounce rapid state changes to avoid UI flicker
- Use `lockForUpdate()` sparingly - only in the critical section
- Consider connection pooling for high-traffic scenarios

**Edge Cases:**
- Tab closed during submission: Idempotency key handles retry on reopen
- Reverb disconnect: Show reconnecting state, auto-reconnect
- Expired while typing: Validate expiry client-side before submit

---

### Phase 3: Slack Integration

**Goal:** Support interactive questions in Slack threads.

#### Tasks

- [ ] **Extend SlackBotResponseService** with interaction posting
  ```php
  public function postInteractionQuestion(InteractionRequest $interaction, string $channelId, string $threadTs): void
  {
      $blocks = $this->buildInteractionBlocks($interaction);

      $this->slackApi->chat()->postMessage([
          'channel' => $channelId,
          'thread_ts' => $threadTs,
          'blocks' => $blocks,
          'text' => "Question from agent: {$interaction->question_content}",
      ]);
  }
  ```

- [ ] **Create Slack interaction blocks builder**
  - For `text` type: Show question, link to dashboard for response
  - For `select` type: Radio button action block
  - For `confirm` type: Button action block with Yes/No

- [ ] **Handle Slack interaction callbacks**
  - Route: `POST /slack/interactions`
  - Parse `block_actions` payload
  - Use same `lockForUpdate()` pattern as dashboard
  - Store response and dispatch resume job

- [ ] **Add fallback to dashboard for complex interactions**
  - If question requires long text, provide link to dashboard
  - Store channel/thread context for posting completion message back

- [ ] **Store Slack context on AgentRun**
  - Add `slack_channel_id`, `slack_thread_ts` to run context
  - Use for posting questions and completion messages

#### Research Insights for Phase 3

**Best Practices:**
- Use Slack's `response_url` for updating messages after response
- Include interaction ID in block action `value` for lookup
- Post completion message back to original thread

**Edge Cases:**
- User responds via both Slack and Dashboard: First wins via row locking
- Slack button clicked multiple times: Debounce in Slack callback handler
- Thread archived: Fall back to DM or dashboard notification

---

### Phase 4: Compound Engineering Skills Integration

**Goal:** Load and execute Compound Engineering workflows.

#### Tasks

- [ ] **Create skill definition loader** (`app/Services/Agents/CompoundEngineeringSkillLoader.php`)
  - Parse skill invocation from prompt (e.g., `/workflows:plan Add authentication`)
  - Load skill file content from configured path
  - Build system prompt with skill instructions
  - Configure tool allowlist for the skill

- [ ] **Create CompoundEngineeringAgent definition** (`app/Services/Agents/Definitions/CompoundEngineeringAgent.php`)
  - Extends base AgentDefinition
  - Configures for interactive execution mode
  - Sets appropriate model (opus for complex, sonnet for simple)
  - Enables all Compound Engineering tools

- [ ] **Store skill files on server**
  - Path: `storage/app/compound-engineering/skills/`
  - Download from plugin repo or fetch on deploy
  - Include: workflows/plan.md, workflows/work.md, workflows/review.md

- [ ] **Configure Claude CLI for Compound Engineering**
  - Ensure Context7 MCP server is accessible
  - Set appropriate timeout (30+ minutes for /lfg)
  - Configure tool permissions

- [ ] **Add skill invocation parsing to entry points**
  - SlackIntentDetectionService: Detect `/workflows:*` commands
  - CommandPalette: Add Compound Engineering command
  - MCP trigger-agent: Accept skill parameter

#### Research Insights for Phase 4

**Best Practices:**
- Cache parsed skill definitions to avoid repeated file reads
- Version skill files with git SHA for traceability
- Log skill invocations for usage analytics

**Performance Considerations:**
- `/lfg` can run 30+ minutes - ensure worker timeouts are disabled
- Stream output to database incrementally to avoid memory issues

---

### Phase 5: Polish & Production Readiness

**Goal:** Ensure reliability, security, and observability.

#### Tasks

- [ ] **Implement timeout handling**
  - Default 60-minute timeout for interactions
  - Grace period warning at 55 minutes (broadcast to frontend)
  - Auto-cancel with notification on timeout
  - Process cleanup on timeout via scheduled command

- [ ] **Add interaction queue UI**
  - Show list of pending interactions in dashboard sidebar
  - Allow user to switch between interactions
  - Show status of each (waiting, expired, responded)

- [ ] **Implement concurrency handling**
  - One active interaction modal at a time
  - Queue additional interactions
  - Prevent race conditions via row locking (already implemented)

- [ ] **Add security measures**
  - Validate user can respond to interaction (owns the run)
  - Sanitize responses before piping to process
  - Rate limit interaction responses (60/min)
  - Log all interactions for audit

- [ ] **Add MCP tools for agent-native architecture** (`routes/ai.php`)

  **Research Insight: Other agents need API access**
  ```php
  McpServer::register('interactions', function () {
      return new InteractionMcpServer();
  });

  // In InteractionMcpServer:
  public function tools(): array
  {
      return [
          new McpTool(
              name: 'respond-to-interaction',
              description: 'Respond to a pending interaction request',
              inputSchema: [
                  'type' => 'object',
                  'properties' => [
                      'interaction_id' => ['type' => 'integer'],
                      'response' => ['type' => 'string'],
                  ],
                  'required' => ['interaction_id', 'response'],
              ],
              handler: fn($input) => $this->handleRespond($input),
          ),
          new McpTool(
              name: 'list-pending-interactions',
              description: 'List all pending interaction requests',
              handler: fn() => $this->listPending(),
          ),
      ];
  }
  ```

- [ ] **Create monitoring dashboard**
  - Active interactive runs count
  - Average response time to interactions
  - Timeout rate
  - Skill usage statistics

- [ ] **Document the feature**
  - Add to `/docs/COMPOUND_ENGINEERING.md`
  - Include setup instructions for Laravel Cloud
  - Add troubleshooting guide
  - Document skill file management

#### Research Insights for Phase 5

**Security Considerations:**
- Audit `--dangerously-skip-permissions` usage - consider per-skill permission grants
- Implement response sanitization: strip control characters, limit length
- Add rate limiting to MCP tools as well as HTTP endpoints

**Performance Considerations:**
- Use dedicated `interactive-agents` queue with `--memory=512` flag
- Monitor worker memory with Horizon metrics
- Consider Redis cluster for high availability of pub/sub

---

## Simplification Opportunities

**Research Insight: 450+ LOC Reduction Possible**

1. **Reuse ApprovalRequest patterns** - Similar model structure exists, could extend
2. **Reuse existing broadcast event structure** - `AgentRunStatusChanged` pattern
3. **Leverage useAgentRealtime composable** - Extend rather than create new
4. **Single job class with checkpoint** - Instead of two job classes

```php
// Simplified single job approach
class RunInteractiveAgentJob implements ShouldQueue
{
    public function __construct(
        public AgentRun $run,
        public ?string $resumeFromResponse = null
    ) {}

    public function handle()
    {
        $runner = $this->resumeFromResponse
            ? InteractiveClaudeRunner::resume($this->run, $this->resumeFromResponse)
            : InteractiveClaudeRunner::start($this->run);

        // ... same logic, one job class handles both start and resume
    }
}
```

---

## Alternative Approaches Considered

### 1. Turn-Based Execution (Now Partially Adopted via Checkpoint-Resume)

**Original rejection:** Loses process state, high latency, context limits.

**Updated status:** Checkpoint-resume pattern addresses worker utilization while acknowledging that Claude CLI may need to be restarted. Testing needed to confirm if stdin piping works for responses or if full restart with context is required.

### 2. SDK-Only Approach (Partially Adopted)

**Approach:** Use Claude SDK directly instead of CLI.

**Status:** Already supported via `ClaudeAgentSdk.php` for some agents.

**Why not exclusive:**
- CLI provides Claude Max subscription usage (cost savings)
- CLI has Compound Engineering plugin support
- SDK used for simpler agents, CLI for full Compound Engineering

### 3. Webhook-Based Interactions (Considered for Future)

**Approach:** Claude calls a webhook when it needs input.

**Why deferred:**
- Requires MCP server changes
- More complex to implement
- Current streaming approach works for MVP

---

## Acceptance Criteria

### Functional Requirements

- [ ] Users can trigger `/workflows:plan` from dashboard command palette
- [ ] Users can trigger `/workflows:plan` from Slack @mention
- [ ] Interactive questions appear as real-time modals in dashboard
- [ ] Interactive questions appear in Slack threads (where applicable)
- [ ] User responses are piped back to running process
- [ ] Process continues after response until next question or completion
- [ ] `/workflows:work` executes with plan context
- [ ] `/workflows:review` executes with code context
- [ ] `/lfg` executes full autonomous loop with minimal interactions
- [ ] Other Claude sessions can respond via MCP tools (agent-native)

### Non-Functional Requirements

- [ ] Interactive response latency < 2 seconds (question to modal)
- [ ] Process can wait up to 60 minutes for response
- [ ] System handles 10 concurrent interactive sessions (300MB memory baseline)
- [ ] No memory leaks in long-running interactive processes
- [ ] Graceful degradation if Reverb connection lost
- [ ] Race conditions handled via row-level locking

### Quality Gates

- [ ] Unit tests for InteractionRequest model
- [ ] Feature tests for InteractionResponseController (including race conditions)
- [ ] Feature tests for InteractiveClaudeRunner
- [ ] Integration test for full workflow execution
- [ ] Manual test: Slack -> question -> dashboard response
- [ ] Manual test: Dashboard -> question -> Slack response
- [ ] Manual test: Two tabs responding simultaneously (first wins)

---

## Dependencies & Prerequisites

1. **Claude CLI installed on Laravel Cloud**
   - Requires Node.js environment
   - OAuth token configuration
   - Access to npm packages

2. **Laravel Reverb operational**
   - Already configured per `routes/channels.php`
   - Needs user-specific channel authorization

3. **Compound Engineering skill files**
   - Either bundle with app or fetch on deploy
   - Store in `storage/app/compound-engineering/`

4. **Redis for job dispatching**
   - Already available via Horizon
   - Used for ResumeInteractiveAgentJob dispatch

5. **Dedicated queue worker**
   - Queue: `interactive-agents`
   - Memory: 512MB
   - Timeout: 0 (no timeout)

---

## Risk Analysis & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Claude CLI doesn't support stdin piping | Medium | High | **Research confirmed:** Symfony InputStream works. If issues, fall back to checkpoint-restart with full context. |
| Streaming output format undocumented | Medium | Medium | **Research confirmed:** `--output-format stream-json` emits JSONL with tool_use before execution. |
| Process hangs waiting for input | Low | High | Checkpoint-resume pattern frees workers. Timeout watchdog kills process after expiry. |
| Laravel Cloud restricts long-running processes | Low | High | Use queue workers; jobs exit after checkpoint. |
| User never responds | Medium | Medium | 60-minute timeout; notify before expiry via broadcast. |
| Security: prompt injection via responses | Low | High | **Mitigation:** Sanitize responses (strip control chars, limit to 10KB), audit log all responses. |
| Race conditions on simultaneous response | Medium | High | **Mitigation:** Row-level locking with `lockForUpdate()` in transaction. |
| Multi-tab coordination | Medium | Low | **Mitigation:** BroadcastChannel API for same-origin tab coordination. |
| Worker memory exhaustion | Low | Medium | **Mitigation:** Dedicated queue with --memory=512, Horizon monitoring. |

---

## Resource Requirements

- **Development:** 3-4 weeks for senior developer
- **Infrastructure:** Dedicated queue worker (512MB memory)
- **Dependencies:** Claude CLI npm package on server

---

## Future Considerations

1. **Multi-User Collaboration:** Allow multiple users to observe and contribute to interactive sessions
2. **Session Recording:** Record all interactions for playback/training
3. **Custom Skills:** Allow users to upload their own skill definitions
4. **Workflow Templates:** Pre-built workflows for common tasks (PR review, bug fix, feature planning)
5. **Mobile App:** Push notifications for interactions on mobile

---

## References & Research

### Internal References

- Agent execution: `app/Services/Agents/AgentExecutor.php:163-196`
- CLI runner: `app/Services/Agents/ClaudeCliRunner.php`
- Reverb broadcasting: `app/Events/AgentRunStatusChanged.php`
- Agent realtime composable: `resources/js/composables/useAgentRealtime.ts:21-148`
- Slack processing: `app/Jobs/ProcessSlackMentionJob.php:134-250`
- Approval workflow: `app/Models/ApprovalRequest.php`

### External References

- [Claude Code CLI Reference](https://code.claude.com/docs/en/cli-reference)
- [Claude Agent SDK](https://github.com/anthropics/claude-agent-sdk-typescript)
- [Compound Engineering Plugin](https://github.com/EveryInc/compound-engineering-plugin)
- [Laravel Reverb Documentation](https://laravel.com/docs/reverb)
- [Claude Code Sandboxing](https://code.claude.com/docs/en/sandboxing)
- [Symfony Process InputStream](https://symfony.com/doc/current/components/process.html)

### Research Findings (from /deepen-plan)

- Symfony `InputStream` class enables stdin writing after process start
- Claude CLI stream-json emits tool_use blocks before execution (interceptable)
- Row-level locking (`lockForUpdate()`) required for race condition prevention
- State machine pattern with discriminated unions for frontend reliability
- BroadcastChannel API for multi-tab coordination
- Checkpoint-resume pattern superior to blocking workers on pub/sub

### Related Work

- Existing agent system: `docs/AGENTS.md`
- Website builder real-time: `resources/js/composables/useWebsiteBuilder.ts`

---

## Open Questions (Updated)

1. ~~**Critical:** Does Claude CLI's `--output-format stream-json` emit parseable `tool_use` events that we can intercept?~~
   - **ANSWERED:** Yes, confirmed via research. JSONL format with tool_use blocks emitted before execution.

2. ~~**Critical:** Can we pipe stdin to a running Process after it's started in Laravel/Symfony?~~
   - **ANSWERED:** Yes, use `Symfony\Component\Process\InputStream` class directly.

3. **Important:** Should interactions be respondable from both Slack and Dashboard simultaneously?
   - Current assumption: Yes, first response wins (enforced via row locking)

4. **Nice-to-have:** Should we support file upload in interaction responses?
   - Current assumption: No, text only for MVP

5. **NEW: Needs Spike:** Does Claude CLI accept stdin input for tool_result after emitting tool_use, or do we need full restart?
   - GitHub Issue #16712 suggests this may not work cleanly
   - Fallback: Checkpoint full context, restart process with response included

---

## ERD: New Models

```mermaid
erDiagram
    AgentRun ||--o{ InteractionRequest : has
    User ||--o{ InteractionRequest : responds_to

    InteractionRequest {
        bigint id PK
        bigint agent_run_id FK
        bigint responded_by_id FK "nullable"
        string question_type "text|select|confirm"
        text question_content
        json options "nullable, for select type"
        json context "nullable, additional display context"
        text response "nullable"
        timestamp responded_at "nullable"
        string responded_via "nullable: dashboard|slack"
        string idempotency_key "nullable, unique"
        timestamp expires_at
        timestamp created_at
        timestamp updated_at
    }

    AgentRun {
        bigint id PK
        bigint agent_id FK
        string status "running|awaiting_input|completed|failed"
        json context
        json checkpoint "nullable, for resume"
        timestamps
    }
```

---

## Implementation Checklist

### Phase 1: Foundation
- [ ] Create InteractionRequest model with responded_by_id FK
- [ ] Create migration with proper indexes
- [ ] Create InteractiveClaudeRunner with Symfony InputStream
- [ ] Create broadcast events
- [ ] Add `awaiting_input` status to AgentRun
- [ ] Add `checkpoint` column to AgentRun
- [ ] Create RunInteractiveAgentJob with checkpoint-resume

### Phase 2: Real-Time & Frontend
- [ ] Create InteractionRequestCreated event
- [ ] Add channel authorization
- [ ] Create useInteractionRealtime composable with state machine
- [ ] Create InteractionModal component
- [ ] Create InteractionResponseController with row locking
- [ ] Add API route with throttling
- [ ] Integrate into AuthenticatedLayout
- [ ] Add BroadcastChannel tab coordination

### Phase 3: Slack Integration
- [ ] Extend SlackBotResponseService
- [ ] Create Slack interaction blocks builder
- [ ] Handle Slack interaction callbacks with row locking
- [ ] Add fallback to dashboard
- [ ] Store Slack context on AgentRun

### Phase 4: Skills Integration
- [ ] Create skill definition loader
- [ ] Create CompoundEngineeringAgent definition
- [ ] Store skill files on server
- [ ] Configure Claude CLI
- [ ] Add skill parsing to entry points

### Phase 5: Polish
- [ ] Implement timeout handling
- [ ] Add interaction queue UI
- [ ] Implement concurrency handling
- [ ] Add security measures (response sanitization)
- [ ] Add MCP tools (respond-to-interaction, list-pending-interactions)
- [ ] Create monitoring dashboard
- [ ] Document the feature
- [ ] Configure dedicated queue worker

---

## Spike Required Before Phase 1

Before full implementation, validate these assumptions:

```bash
# Test 1: Does stream-json emit tool_use before execution?
claude --print --output-format stream-json "Ask me a question using AskUserQuestion"

# Test 2: Can we write to stdin after process starts?
# Create test script that uses Symfony InputStream

# Test 3: Does Claude accept stdin response for tool_result?
# If not, document checkpoint-restart approach
```
