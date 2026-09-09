# Capability Synthesis Agent Technical Spec

## Overview

A meta-agent that runs whenever new integrations, agents, tools, or capabilities are added to the system. It analyzes how new functionality interacts with existing capabilities and identifies emergent opportunities.

---

## The Problem

Individual integrations are valuable alone:
- Slack → action item extraction
- GitHub → issue tracking
- Harvest → time tracking

But compound value emerges from combinations:
- Slack + GitHub → "bug mentioned in Slack" → auto-create GitHub issue
- GitHub + Harvest → "PR merged" → stop timer, log time
- All four → monthly client report with complete picture

Without systematic review, these synergies get missed.

---

## When It Runs

### Automatic Triggers
| Event | Trigger |
|-------|---------|
| New integration connected | OAuth flow completed |
| New agent deployed | Agent registration |
| New webhook handler added | Code deployment |
| New data source linked | Configuration change |

### Manual Triggers
- Developer runs synthesis review after feature work
- Periodic review (monthly) of all capabilities

---

## What It Analyzes

### 1. Data Flow Opportunities
```
New: GitHub issues now synced
Existing: Slack monitors client channels
Opportunity: When client mentions "bug" or "broken" in Slack
            → Auto-create GitHub issue with context
            → Link back to Slack thread
```

### 2. Agent Collaboration
```
New: QAAgent tests PRs
Existing: DevAgent creates PRs from issues
Opportunity: Chain them - DevAgent PR → QAAgent auto-test
            → If pass, auto-merge to staging
            → Notify in Slack
```

### 3. Cross-Integration Triggers
```
New: Harvest time tracking connected
Existing: GitHub PR workflow
Opportunity: PR merged → prompt to log time
            Start timer on issue assignment
            Stop timer on PR merge
```

### 4. Reporting Enhancements
```
New: Calendar meetings synced
Existing: Client health scoring
Opportunity: Meeting frequency affects health
            No meetings in 30 days → health warning
            Regular cadence → health boost
```

### 5. Automation Chains
```
New: Invoice creation agent
Existing: Time entries synced
Existing: Task completion tracking
Opportunity: End of month →
            Gather all time entries +
            List completed tasks +
            Generate invoice with descriptions +
            Draft email to client
```

---

## Output Format

### Synthesis Report
```markdown
# Capability Synthesis Report
Generated: 2024-01-15
Trigger: GitHub integration connected

## New Capability Summary
- GitHub Issues ↔ Zao Dash Tasks (bidirectional sync)
- PR workflow monitoring
- Webhook events: issues, pull_request, push

## Identified Opportunities

### High Value
1. **Slack → GitHub Issue Pipeline**
   - When: Client reports bug in Slack
   - Then: Auto-create GitHub issue with thread context
   - Confidence: High (clear use case)
   - Implementation: SlackActionExtractor checks for bug keywords
                    → calls GitHub API to create issue
                    → links issue back to Slack thread

2. **Time Tracking Integration**
   - When: Issue assigned to team member
   - Then: Offer to start Harvest timer
   - When: PR merged closing issue
   - Then: Prompt to stop timer, log final time
   - Confidence: High

### Medium Value
3. **Meeting → Issue Follow-up**
   - When: Meeting transcript mentions "we'll create a ticket"
   - Then: Auto-create GitHub issue from meeting context
   - Confidence: Medium (requires NLP accuracy)

### Exploratory
4. **Predictive Workload**
   - Combine: Open issues + PR velocity + time logged
   - Output: "At current pace, sprint will complete 3 days late"
   - Confidence: Low (needs historical data)

## Recommended Actions
1. [ ] Implement Slack→GitHub issue pipeline
2. [ ] Add timer prompts to issue assignment flow
3. [ ] Update ClientReportAgent to include GitHub metrics
4. [ ] Consider: meeting transcript → issue extraction

## Integration Matrix Update
[Updated diagram showing all connection points]
```

---

## Integration Matrix

The agent maintains a living matrix of all capabilities and their interactions:

```
                 │ Slack │ Gmail │ GitHub │ Harvest │ Calendar │ Drive │
─────────────────┼───────┼───────┼────────┼─────────┼──────────┼───────┤
Slack            │   -   │   ○   │   ●    │    ○    │    ○     │   ○   │
Gmail            │   ○   │   -   │   ○    │    ○    │    ●     │   ●   │
GitHub           │   ●   │   ○   │   -    │    ●    │    ○     │   ○   │
Harvest          │   ○   │   ○   │   ●    │    -    │    ○     │   ○   │
Calendar         │   ○   │   ●   │   ○    │    ○    │    -     │   ○   │
Drive            │   ○   │   ●   │   ○    │    ○    │    ○     │   -   │

● = Active integration exists
○ = Opportunity identified
- = N/A
```

---

## Database Schema

### `capability_registry`
```
id
capability_type (integration, agent, webhook, tool)
name
description
data_inputs (json - what it consumes)
data_outputs (json - what it produces)
triggers (json - what activates it)
actions (json - what it can do)
added_at
```

### `synthesis_reports`
```
id
trigger_type (new_capability, periodic, manual)
trigger_capability_id
report_content (markdown)
opportunities_identified (json)
opportunities_implemented (json)
generated_at
reviewed_at
reviewed_by
```

### `integration_opportunities`
```
id
synthesis_report_id
title
description
source_capabilities (json - IDs involved)
implementation_notes
value_rating (high, medium, low, exploratory)
status (identified, planned, implemented, rejected)
implemented_at
```

---

## Agent Implementation

### CapabilitySynthesisAgent

**Trigger**: New capability registered OR monthly schedule OR manual

**Process**:
```
1. Load capability registry (all existing capabilities)
2. If triggered by new capability:
   - Focus analysis on new + existing combinations
3. If periodic/manual:
   - Full matrix review
4. For each capability pair:
   - Analyze data flow compatibility
   - Identify trigger→action chains
   - Check for reporting synergies
   - Score opportunity value
5. Generate synthesis report
6. Store in database
7. Surface high-value opportunities in dashboard
8. Create draft implementation tasks (optional)
```

**Skills**:
- Understand data schemas across integrations
- Identify semantic connections (bug report → issue)
- Evaluate implementation complexity
- Prioritize by business value

**Approval**: No (analysis only, doesn't make changes)

---

## Dashboard Integration

### Synthesis Insights Panel
```
┌─────────────────────────────────────────────────┐
│ 🔗 Integration Opportunities                    │
├─────────────────────────────────────────────────┤
│ ● HIGH: Slack → GitHub issue pipeline           │
│   Connect bug reports to issue tracker          │
│   [Implement] [Dismiss]                         │
├─────────────────────────────────────────────────┤
│ ○ MEDIUM: Timer prompts on issue assignment     │
│   Streamline time tracking workflow             │
│   [Implement] [Dismiss]                         │
├─────────────────────────────────────────────────┤
│ Last synthesis: 2 days ago                      │
│ [Run Full Review]                               │
└─────────────────────────────────────────────────┘
```

---

## Example Synthesis Chains

### Chain 1: Client Communication → Code → Delivery
```
Slack message (bug report)
    ↓ SlackActionExtractor
GitHub issue created
    ↓ DevAgent assigned
Feature branch + PR
    ↓ QAAgent
Tests pass, merged to develop
    ↓ DeployAgent
Staging deployment
    ↓ Notification
"Fix deployed to staging - verify?"
    ↓ Approval
Production deployment
    ↓ Harvest
Time logged automatically
    ↓ ClientReportAgent (monthly)
"Fixed 12 bugs this month"
```

### Chain 2: Meeting → Action → Completion
```
Google Meet with client
    ↓ MeetingTranscriptAgent
Action items extracted
    ↓ Task creation
Zao Dash tasks created
    ↓ Timer integration
Work tracked in Harvest
    ↓ GitHub (if code)
PRs linked to tasks
    ↓ Completion
Tasks marked done
    ↓ Next meeting
PreMeetingBriefAgent shows:
"Last meeting: 5 action items, 4 completed"
```

---

## Implementation Order

1. **Capability registry** - Track all system capabilities
2. **Synthesis report generation** - Core analysis logic
3. **Trigger hooks** - Auto-run on new capabilities
4. **Dashboard panel** - Surface opportunities
5. **Implementation tracking** - Monitor what gets built
6. **Periodic review job** - Monthly full synthesis

---

## Success Metrics

- Opportunities identified per synthesis run
- % of high-value opportunities implemented
- Time from identification to implementation
- User-reported "I didn't think of that" moments
- Reduction in manual integration work
