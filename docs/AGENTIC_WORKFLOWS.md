# Agentic Workflow Best Practices

> Source: "A Practical Guide for Production-Grade Agentic AI Workflows"
> Paper: https://arxiv.org/html/2512.08769v1

This document captures first principles for building production-grade agentic systems in Zao Dash.

---

## The Nine Best Practices

### 1. Tool Calls Over MCP

**Principle:** Prefer direct function invocation to MCP (Model Context Protocol) integration.

**Why:** MCP adds abstraction layers that reduce determinism. Research found "the agent frequently made ambiguous tool-selection decisions, inconsistently inferred invocation parameters, and occasionally failed with non-deterministic MCP responses."

**Hierarchy of Preference:**
1. Pure function invocation (most deterministic)
2. Direct tool calls (LLM-interpreted, more overhead)
3. MCP integration (most abstract, least deterministic)

**Application:**
- Use direct PHP function calls in `ClaudeCliRunner` rather than MCP servers
- If MCP is needed for external tools, wrap them in deterministic functions
- Our `ToolRegistry` should expose simple, direct function interfaces

---

### 2. Direct Function Calls Over Tool Calls

**Principle:** Operations not requiring language reasoning should use pure functions, not tool calls.

**Why:** Tool calls require the LLM to "parse instructions, interpret parameter formats, and map natural language input to function arguments—steps that increase token consumption and can lead to non-deterministic behavior."

**Benefits of Pure Functions:**
- Deterministic execution
- Controlled side effects
- Lower cost and faster execution
- Fully testable
- Eliminates unnecessary LLM reasoning

**Application:**
```php
// BAD: Agent with tool
$agent->execute('Create a PR with title X and body Y');

// GOOD: Direct function after agent reasoning
$prContent = $agent->execute('Generate PR content for these changes');
GitHubService::createPR($prContent['title'], $prContent['body']); // Deterministic
```

---

### 3. Avoid Overloading Agents With Many Tools

**Principle:** Assign ONE tool per agent whenever tool usage is required.

**Why:** Multiple tools increase prompt complexity. Agents "must first reason about which tool to invoke and how to structure the parameters—introducing unnecessary ambiguity."

**Observed Failure Pattern:**
> "An agent with both scrape and publish tools would often invoke only one tool, invoke them in the wrong order, or fail to call them entirely, especially when the prompt or input size increased."

**Current Agent Review:**
| Agent | Current Tools | Recommended |
|-------|--------------|-------------|
| MeetingParser | api_calls | api_calls (OK - single tool) |
| ContentCreator | web_search, file_ops | Split or reduce to one |
| DevAgent | code_exec, github, file_ops | code_exec only, rest deterministic |
| QAAgent | code_exec, file_ops | code_exec only |
| CommunicationAgent | email, slack | Split into EmailAgent + SlackAgent |

---

### 4. Single-Responsibility Agents

**Principle:** Each agent should handle ONE clearly defined task.

**Why:** Following software design principles, agents that "do one thing well" are easier to prompt, test, and debug.

**Failure Case from Research:**
> "Combining Veo-3 JSON prompt generation and video generation in one agent caused the LLM to produce malformed JSON, sometimes mix natural language with JSON, and sometimes 'hallucinate' file paths or status messages."

**Solution Pattern:**
- **Planning Agent** → produces structured plan/specification
- **Execution Function** → deterministic code executes the plan

**Example:**
```
BEFORE (Multi-Responsibility):
DevAgent: Reads task → Plans implementation → Writes code → Creates PR

AFTER (Single-Responsibility):
DevPlannerAgent: Reads task → Outputs implementation plan (JSON)
CodeGeneratorFunction: Takes plan → Writes code to workspace (deterministic)
PRCreatorFunction: Takes code diff → Creates PR (deterministic)
```

---

### 5. Store Prompts Externally and Load at Runtime

**Principle:** Maintain prompts as external artifacts, not embedded in code.

**Why:** External storage enables:
- Non-technical stakeholders can update behavior without code changes
- Governance workflows (review, versioning, rollback)
- A/B testing and red-teaming
- Continuous improvement without deploys
- Prompt access control

**Zao Dash Implementation:**
- SKILL.md files in `storage/app/skills/{agent-slug}/`
- Loaded at runtime by `BaseAgentDefinition::loadSkillPrompt()`

**Future Enhancements:**
- Version control prompts separately (dedicated repo or branch)
- Add prompt versioning to AgentRun tracking
- Build prompt editor UI for non-developers
- Implement prompt A/B testing framework

---

### 6. Responsible AI: Multi-Model Consortium

**Principle:** Use multiple specialized LLMs with consolidated reasoning rather than single-model outputs.

**Why:** Single-model outputs suffer from "hallucinations, reasoning inconsistencies, and subtle or overt biases."

**The Consortium Architecture:**
```
Input → [Claude Agent] ─┐
      → [GPT Agent]    ─┼→ [Reasoning Agent] → Consolidated Output
      → [Gemini Agent] ─┘
```

**How the Reasoning Agent Works:**
Rather than creating content, it performs "structured consolidation tasks:
- Conflict resolution
- Logical consistency checking
- Factual alignment
- Deduplication
- Relevance filtering"

**Key Finding:**
> "The reasoning agent effectively reduces hallucination risk by grounding its synthesis in multi-model agreement."

**When to Use Consortium:**
- ContentCreatorAgent outputs (publishing)
- CommunicationAgent outputs (client-facing)
- Any agent with `requires_approval: true`
- Financial/legal content

**When NOT Needed:**
- Internal processing (MeetingParser → internal tasks)
- Structured data extraction (low hallucination risk)
- Code generation (validated by tests)

---

### 7. Separation of Workflow and MCP Server

**Principle:** Decouple workflow backend from any MCP interface layer.

**Architecture:**
```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  MCP Clients    │────▶│  MCP Adapter    │────▶│  Workflow API   │
│ (Claude Desktop)│     │  (lightweight)  │     │  (full logic)   │
└─────────────────┘     └─────────────────┘     └─────────────────┘
```

**Benefits:**
- MCP server stays simple and stable
- Workflow backend can iterate rapidly
- Independent scaling
- Long-term adaptability

**Application:**
- Our Laravel app = Workflow Backend
- If we expose MCP, create thin adapter layer
- Command Palette → direct API calls to workflow
- Never embed workflow logic in MCP handlers

---

### 8. Containerized Deployment

**Principle:** Deploy workflows using Docker and orchestrate with Kubernetes.

**Benefits:**
1. **Portability:** Containers encapsulate all dependencies
2. **Scalability:** K8s auto-scales based on load
3. **Resilience:** Built-in health checks, restarts
4. **Security:** Container isolation, network policies
5. **Observability:** Prometheus, Grafana integration
6. **CI/CD:** Predictable deployments

**Application:**
- Already planned for Laravel Cloud (AWS/Lambda)
- Agent execution should be containerizable
- Consider agent-specific containers for isolation
- Use Laravel Horizon for queue management

---

### 9. KISS: Keep It Simple, Stupid

**Principle:** Avoid unnecessary complexity; emphasize clarity over architectural sophistication.

**Why:** Agentic workflows should "delegate reasoning, generation, and decision-making to LLMs and specialized agents" - not implement elaborate internal logic.

**What to Avoid:**
- Unnecessary structural complexity
- Traditional patterns that add little value (deep inheritance, microservices)
- Over-engineering for hypothetical futures

**What to Do:**
- Flat, readable, function-driven designs
- Transparent, lightweight orchestration
- Each component handles one task
- Let AI tools (Copilot, Claude Code) understand your code easily

---

## Failure Modes to Avoid

### Tool-Related Failures
| Failure | Cause | Prevention |
|---------|-------|------------|
| Ambiguous tool selection | Multiple tools on one agent | One tool per agent |
| Missed tool calls | Large prompts + many tools | Simplify agent scope |
| Non-deterministic results | MCP abstraction layers | Direct function calls |
| Parameter inference errors | LLM interpreting formats | Explicit schemas |

### LLM Reasoning Failures
| Failure | Cause | Prevention |
|---------|-------|------------|
| Malformed JSON output | Mixed responsibilities | Single-responsibility agents |
| Hallucinated file paths | Side effects in planning agent | Separate planning from execution |
| Single-model bias | Only using one LLM | Multi-model consortium |
| Factual drift | No consolidation | Reasoning agent synthesis |

### Architectural Pitfalls
| Pitfall | Symptom | Solution |
|---------|---------|----------|
| Over-engineering | Brittle, hard to debug | KISS principle |
| Tight coupling | Prompts in code | External prompt storage |
| Monolithic agents | Non-deterministic failures | Split into single-responsibility |

---

## Key Patterns

### Pattern 1: Planning → Execution Split
```
[Planning Agent] → JSON specification
                         ↓
              [Deterministic Function] → Side effects
```

### Pattern 2: Consortium for High-Stakes Output
```
[Claude] ─┐
[GPT]    ─┼→ [Reasoning Agent] → Verified output
[Gemini] ─┘
```

### Pattern 3: Agent Chaining with Deterministic Handoffs
```
[Agent A] → structured output → [Pure Function] → [Agent B]
```

### Pattern 4: Externalized Prompt Governance
```
storage/app/skills/
├── agent-name/
│   ├── SKILL.md           # Current prompt
│   ├── SKILL.v1.md        # Version history
│   └── config.yaml        # Prompt metadata
```

---

## First Principles Hierarchy

1. **Determinism > Flexibility** - Pure functions over tool calls over MCP
2. **Specialization > Generalization** - Single-responsibility agents
3. **Simplicity > Sophistication** - KISS principle throughout
4. **Consensus > Single Source** - Multi-model for high-stakes outputs
5. **Separation > Coupling** - Prompts external, workflow separate from interface
6. **Observable > Opaque** - Container orchestration, logging, tracing

---

## Implementation Checklist

When creating a new agent, verify:

- [ ] Agent has ONE primary tool (or zero if purely generative)
- [ ] System prompt loaded from external SKILL.md file
- [ ] Output schema is well-defined and validated
- [ ] Side effects happen in deterministic post-processing, not agent
- [ ] High-stakes outputs use multi-model consortium OR require approval
- [ ] Agent chains use structured JSON handoffs, not natural language
- [ ] Failure handling includes circuit breaker
- [ ] Cost tracking enabled for budget enforcement
