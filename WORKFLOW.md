---
tracker:
  kind: kanban_tasks
  active_states:
    - pending
    - in_progress
  terminal_states:
    - completed
polling:
  interval_ms: 30000
workspace:
  root: $SYMPHONY_WORKSPACE_ROOT
hooks:
  timeout_ms: 60000
agent:
  max_concurrent_agents: 5
  max_retry_backoff_ms: 300000
  max_turns: 20
  max_concurrent_agents_by_state:
    pending: 2
    in_progress: 5
codex:
  command: codex app-server
---
You are implementing work for issue {{ issue.identifier }}.

Issue Title: {{ issue.title }}
Issue State: {{ issue.state }}
Issue Priority: {{ issue.priority }}
Issue URL: {{ issue.url }}
Attempt: {{ attempt }}

Context:
- Use existing code conventions in this repository.
- Keep changes scoped to this issue only.
- Run focused tests for touched behavior before finishing.
- When complete, summarize what changed and any follow-up checks.
