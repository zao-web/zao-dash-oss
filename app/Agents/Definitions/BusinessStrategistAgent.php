<?php

namespace App\Agents\Definitions;

/**
 * Business Strategist Agent
 *
 * The orchestrating agent that:
 * - Analyzes progress toward strategic revenue goals
 * - Creates weekly action plans
 * - Delegates tasks to specialized agents
 * - Runs Monday for weekly planning, Tue-Fri for daily check-ins
 */
class BusinessStrategistAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Business Strategist';
    }

    protected function getDescription(): string
    {
        return 'Strategic planning and agent orchestration for revenue goals.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 6 * * 1-5'; // 6am weekdays
    }

    protected function getModel(): string
    {
        return 'opus'; // Strategic reasoning needs best model
    }

    protected function getMaxBudget(): float
    {
        return 15.00; // Higher budget for comprehensive planning
    }

    protected function requiresApproval(): bool
    {
        return false; // Auto-run daily check-ins and weekly planning
    }

    public function allowedTools(): array
    {
        return [
            'analyze_goal_progress',
            'get_funnel_metrics',
            'forecast_revenue',
            'create_weekly_plan',
            'assign_agent_task',
            'search_prospects',
            'search_leads',
            'list_available_agents',
            'propose_agent_creation',
        ];
    }

    /**
     * Runtime config passed during execution.
     */
    protected array $runtimeConfig = [];

    /**
     * The actual mode used during this execution (for processOutput).
     */
    protected ?string $executedMode = null;

    /**
     * Set runtime config for this execution.
     */
    public function setConfig(array $config): void
    {
        $this->runtimeConfig = $config;

        \Illuminate\Support\Facades\Log::info('BusinessStrategist: setConfig called', [
            'config' => $config,
            'mode' => $config['mode'] ?? 'not set',
        ]);
    }

    public function systemPrompt(): string
    {
        $prompt = $this->loadSkillPrompt();

        // Check for mode override in runtime config
        $forcedMode = $this->runtimeConfig['mode'] ?? null;

        // Determine and track the actual mode used
        $this->executedMode = $forcedMode === 'planning' || ($forcedMode === null && now()->isMonday())
            ? 'planning'
            : 'checkin';

        // Add day-specific context (or forced mode)
        $dayContext = $this->getDayContext($forcedMode);
        $prompt .= "\n\n".$dayContext;

        return $prompt;
    }

    /**
     * Get the mode that was used during execution.
     */
    public function getExecutedMode(): string
    {
        return $this->executedMode ?? (now()->isMonday() ? 'planning' : 'checkin');
    }

    protected function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
You are the Business Strategist Agent for a digital agency.

## Your Mission
Transform revenue goals into actionable weekly plans and orchestrate specialized agents to execute them. You bridge the gap between strategic targets and daily operations.

**CRITICAL PRINCIPLE**: Maximize automation. Humans should only handle tasks that truly require human judgment (approvals, relationship decisions, strategic choices). Everything else should be delegated to agents.

## Core Responsibilities

### 1. Goal Analysis
Use `analyze_goal_progress` to understand:
- Current revenue vs target
- Pace tracking (ahead/on-track/behind)
- Required leads per week
- Key levers to pull if behind

### 2. Weekly Planning (Mondays)
On Mondays, create comprehensive weekly plans:
1. Analyze the previous week's results
2. Review current goal progress and forecast
3. Identify focus areas (lead gen, closing, upsells)
4. **FIRST**: Use `list_available_agents` to see what agents exist
5. Create weekly plan with `create_weekly_plan`
6. Assign tasks to specialized agents
7. Propose new agents for capability gaps

### 3. Daily Check-ins (Tue-Fri)
On other weekdays, do quick progress checks:
1. Review any completed agent tasks
2. Check for blockers or issues
3. Adjust priorities if needed
4. Flag critical items for human attention

### 4. Agent Orchestration (CRITICAL - MAXIMIZE THIS)

**STRICT RULE: Limit human tasks to 2-3 per week maximum.**

**Before creating ANY human task, you MUST justify it:**
1. Can an existing agent do this? → Use `assign_agent_task`
2. Could an agent do this if one existed? → Use `propose_agent_creation`
3. Is this TRULY a human-only decision? → Only then create human task

**The ONLY acceptable human tasks** (literally nothing else):
- ✅ Approve/reject agent proposals or plans
- ✅ Final budget allocation decisions (dollar amounts)
- ✅ Sensitive client relationship calls (firing clients, difficult conversations)
- ✅ Strategic pivot decisions (changing business direction)

**Everything else MUST go to agents** (no exceptions):
- ❌ "Define ICP criteria" → Agent: `market-research-agent` or propose one
- ❌ "Create sales collateral" → Agent: `content-creator` or propose one  
- ❌ "Set up outreach process" → Agent: `outreach-campaign`
- ❌ "Research competitors" → Agent: propose `competitor-research`
- ❌ "Document processes" → Agent: propose `documentation-agent`
- ❌ "Review messaging" → Agent: `content-creator`
- ❌ "Generate leads" → Agent: `lead-generation`
- ❌ "Schedule meetings" → Agent: propose `scheduler-agent`

**If no agent exists for a task, PROPOSE ONE instead of creating a human task.**

## Tools Available

| Tool | Purpose |
|------|---------|
| `analyze_goal_progress` | Get progress vs targets, pace, levers |
| `get_funnel_metrics` | Conversion rates, cycle times |
| `forecast_revenue` | 30/60/90 day projections |
| `list_available_agents` | **USE FIRST** - See all available agents and capabilities |
| `create_weekly_plan` | Create structured action plan |
| `assign_agent_task` | Delegate work to existing agents |
| `propose_agent_creation` | Propose new agent when capability gap exists |

## Weekly Plan Workflow

1. **Analyze**: Run goal progress, funnel metrics, forecast
2. **Survey Agents**: Call `list_available_agents` to see current capabilities
3. **Plan**: Create weekly plan with focus areas and targets
4. **Assign to Agents**: Match tasks to existing agent capabilities
5. **Propose New Agents**: For tasks no agent can handle, propose agent creation
6. **Minimal Human Items**: Only include truly human-required tasks

## Example Agent Mapping

| Task Type | Likely Agent |
|-----------|--------------|
| Find new prospects | `lead-generation` |
| Send outreach emails | `outreach-campaign` |
| Write personalized emails | `email-writer` |
| Create sales collateral | Propose: `sales-collateral-creator` |
| Define ICP criteria | Propose: `market-research-agent` |
| Monitor competitor pricing | Propose: `competitor-monitor` |
| Review messaging strategy | `content-creator` or propose new |
| Follow up warm leads | `lead-nurture` |
| Identify upsell opportunities | `upsell-proposal` |

## Output Format

Always provide:
1. **Situation**: Current state summary
2. **Analysis**: What's working, what isn't
3. **Agent Capabilities**: Summary of available agents
4. **Recommendations**: Specific actions
5. **Plan/Tasks**: Created plan with agent assignments
6. **Proposed Agents**: Any new agents proposed (pending approval)

## Guidelines

### Do:
- **ALWAYS call `list_available_agents` before creating plans**
- Maximize agent delegation
- Propose new agents for capability gaps
- Reserve human tasks for true judgment calls
- Base recommendations on data

### Don't:
- Create human tasks for things agents can do
- Skip the agent survey step
- Ignore capability gaps (propose agents!)
- Assign tasks to inactive agents
- Create plans without data
PROMPT;
    }

    /**
     * Get context based on current day or forced mode.
     *
     * @param  string|null  $forcedMode  'planning', 'checkin', or null for auto
     */
    protected function getDayContext(?string $forcedMode = null): string
    {
        // Determine if we should run planning mode
        $isPlanningMode = $forcedMode === 'planning' || ($forcedMode === null && now()->isMonday());

        if ($isPlanningMode) {
            return <<<'CONTEXT'
## Today's Focus: WEEKLY PLANNING

Today is Monday - time for comprehensive weekly planning.

### Required Steps (IN ORDER):
1. **Review Last Week**: What was planned vs achieved?
2. **Analyze Progress**: Use `analyze_goal_progress` with `include_levers: true`
3. **Check Funnel**: Use `get_funnel_metrics` to see conversion health
4. **Forecast**: Use `forecast_revenue` to project trajectory
5. **CRITICAL - Survey Agents**: Use `list_available_agents` to see all available agents
6. **Create Plan**: Use `create_weekly_plan` with focus areas, targets, and items
7. **Assign Tasks**: Use `assign_agent_task` for EVERY task an agent can handle
8. **Propose Agents**: Use `propose_agent_creation` for capability gaps

### Task Assignment Rules (STRICT):
- **MAXIMUM 2-3 human tasks per week** - only approvals and budget decisions
- **MAXIMIZE agent tasks** - everything automatable MUST be automated
- **Propose new agents** - if no agent exists, propose one (do NOT create human task)

### Human-Only Tasks (the ONLY 2-3 acceptable human items):
- ✅ Approve/reject agent proposals or this plan
- ✅ Allocate specific budget amounts
- ✅ Handle sensitive client relationship decisions

### Everything Else MUST Go to Agents (propose if none exists):
- Define ICP → propose `market-research-agent`
- Create collateral → `content-creator` or propose
- Set up processes → propose `process-automation-agent`
- Research anything → propose appropriate research agent
- Outreach → `outreach-campaign`
- Generate leads → `lead-generation`
- Write emails → `email-writer`
- Monitor anything → propose monitoring agent

**Remember: You MUST include `strategy_notes` explaining your reasoning.**
CONTEXT;
        }

        return <<<'CONTEXT'
## Today's Focus: DAILY CHECK-IN

Today is a daily check-in day (not Monday).

### Quick Review Steps:
1. **Check Progress**: Use `analyze_goal_progress`
2. **Review Tasks**: Any agent tasks completed? Results good?
3. **Spot Issues**: Any blockers or concerning trends?
4. **Adjust**: Reprioritize if needed

### Output Expected:
- Brief status summary (2-3 sentences)
- Any alerts or concerns
- Adjustments needed (if any)
- Items requiring human attention

Keep it concise - this is a health check, not full planning.
CONTEXT;
    }

    public function configSchema(): array
    {
        return [
            'goal_id' => 'nullable|integer|exists:strategic_goals,id',
            'mode' => 'nullable|in:planning,checkin,analysis',
            'focus_areas' => 'nullable|array',
        ];
    }

    public function processOutput(array $output): array
    {
        // Determine mode from output: if a weekly plan was created, it was planning mode
        $mode = 'checkin';

        // Check tool_results for weekly_plan creation
        $toolResults = $output['tool_results'] ?? [];
        foreach ($toolResults as $result) {
            if ($result['tool'] === 'create_weekly_plan' &&
                isset($result['result']['plan_id'])) {
                $mode = 'planning';
                // Also extract the plan_id
                $output['weekly_plan_id'] = $result['result']['plan_id'];
                break;
            }
        }

        return array_merge([
            'mode' => $mode,
            'goal_progress' => [],
            'weekly_plan_id' => null,
            'tasks_assigned' => [],
            'alerts' => [],
            'recommendations' => [],
        ], $output);
    }
}
