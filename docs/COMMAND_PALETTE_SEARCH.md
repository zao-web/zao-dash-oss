# Command Palette Enhanced Search

## Overview

The Command Palette provides a powerful, priority-based search system that intelligently routes queries through three layers:

1. **Registered Actions** - Navigation commands, action commands, and agent triggers
2. **Object Search** - Searches across Projects, Clients, Tasks, Agents, Team Members, and Approvals
3. **AI Fallback** - Automatically suggests AI mode for questions and complex queries

## Priority Search Flow

### 1. Registered Actions (First Priority)

When you search in the Command Palette, it first checks all registered commands including:

- **Navigation Commands**: "Go to Projects", "Go to Tasks", etc.
- **Action Commands**: "Create New Task", "Create New Project", etc.
- **Agent Commands**: "Run Task Manager", "Run CFO Agent", etc.

These are fuzzy-matched using Fuse.js against:
- Command name
- Command description
- Command keywords

**Example:**
```
Query: "tasks"
Results:
  - Go to Tasks
  - Create New Task
  - View GitHub Issues
```

### 2. Object Search (Second Priority)

If the query doesn't match registered actions (or in addition to matched actions), the system searches across database entities:

#### Searchable Entities

**Projects**
- Fields searched: name, description
- Returns: Project name, status, link to project page
- Icon: folder

**Clients**
- Fields searched: name, description
- Returns: Client name, status, link to client page
- Icon: users

**Tasks**
- Fields searched: title, description
- Returns: Task title, project context, status, priority
- Links to task within project page
- Icon: check-square

**Agents**
- Fields searched: name, description
- Returns: Agent name, description, status
- Links to agent detail page
- Icon: cpu

**Team Members**
- Fields searched: name, email
- Filters: Only active users
- Returns: Name, email, role
- Icon: user

**Approval Requests**
- Fields searched: title, description
- Filters: Only pending approvals
- Returns: Approval title, associated agent
- Icon: shield

**Example:**
```
Query: "Zao Dashboard"
Results:
  - Zao Dashboard (Project)
  - Task: Fix Zao Dashboard login issue
  - Task: Update Zao Dashboard README
```

### 3. AI Fallback (Third Priority)

When no actions or objects match, the system intelligently detects if the query looks like a question or AI request and suggests switching to AI mode.

#### Auto-Detection Patterns

The system detects questions by checking for:

- **Question words**: what, how, why, when, where, who, which, can, could, should, would, is, are, does, do
- **Question mark**: Ends with `?`
- **AI patterns**: show me, tell me, find me, help me, explain, summarize
- **Length heuristic**: Queries over 10 characters with no matches

**Example:**
```
Query: "What should I focus on today?"
Result: Shows AI suggestion button
  "No commands or items found"
  "Your query looks like a question"
  [Ask AI instead] button
```

## Using AI Mode

### Two Ways to Enter AI Mode

1. **Prefix Method**: Type `>` or `?` before your query
   ```
   > What tasks are overdue?
   ? Show me pending approvals
   ```

2. **Auto-Suggestion**: Click "Ask AI instead" when shown the suggestion

### AI Mode Features

- **Conversational**: Maintains conversation history
- **Context-aware**: Knows your current page and system state
- **Tool-enabled**: Can search, create, and update items
- **Streaming responses**: Real-time response streaming

## Technical Implementation

### Backend

**Controller**: `App\Http\Controllers\CommandPaletteController`

#### Object Search Endpoint

```php
GET /api/command-palette/object-search?query={query}&limit={limit}

Parameters:
  - query (required): Search string (min 1 char)
  - limit (optional): Results per entity type (default: 5, max: 20)

Response:
{
  "results": {
    "projects": [...],
    "clients": [...],
    "tasks": [...],
    "agents": [...],
    "team": [...],
    "approvals": [...]
  },
  "total": 12,
  "query": "search term"
}
```

#### Result Format

Each result contains:
```php
[
  'id' => 'project-123',
  'type' => 'project',
  'name' => 'Project Name',
  'description' => 'Short description',
  'url' => '/projects/slug',
  'icon' => 'folder',
  'metadata' => [
    'status' => 'active',
    'slug' => 'project-slug'
  ]
]
```

### Frontend

**Component**: `resources/js/Components/CommandPalette.vue`
**Composable**: `resources/js/composables/useCommandPalette.ts`

#### Search Flow

```typescript
1. User types in search input
2. Debounced search (150ms)
3. Fuzzy search registered actions
4. API call to object search endpoint
5. Combine results (actions first, then objects)
6. Check if AI suggestion should show
7. Display grouped results
```

#### State Management

```typescript
{
  query: ref(''),
  selectedIndex: ref(0),
  isAIMode: ref(false),
  shouldShowAISuggestion: ref(false),
  isLoadingObjects: ref(false),
  objectResults: ref<Command[]>([]),
  results: computed<Command[]>()
}
```

## Performance Considerations

### Backend Optimizations

1. **Limited results**: Default 5 per entity, max 20
2. **Selective columns**: Only fetches required fields
3. **Indexed searches**: Searches on indexed columns (name, title)
4. **Recent first**: Orders by `updated_at DESC` for relevance

### Frontend Optimizations

1. **Debouncing**: 150ms delay prevents excessive API calls
2. **Minimum query length**: Requires 2+ characters for object search
3. **Cached actions**: Registered commands loaded once on mount
4. **Lazy agent loading**: Agent commands loaded on mount, not per search

## Usage Examples

### Finding a Project
```
Query: "zao"
Results:
  ✓ Zao Dashboard (Project)
  ✓ Go to Projects (Navigation)
```

### Finding a Task
```
Query: "implement search"
Results:
  ✓ Implement command palette search (Task)
  ✓ Implement search filters (Task)
```

### Creating Something New
```
Query: "new"
Results:
  ✓ Create New Task (Action)
  ✓ Create New Project (Action)
  ✓ Create New Client (Action)
```

### Asking a Question
```
Query: "what's blocking me?"
Result:
  "No commands or items found"
  [Ask AI instead] → Switches to AI mode
```

### Direct AI Mode
```
Query: "> summarize my week"
Result: Opens AI chat mode directly
```

## Testing

### Test Suite

Location: `tests/Feature/Controllers/CommandPaletteControllerTest.php`

#### Coverage

- ✓ Search projects by name
- ✓ Search clients by name
- ✓ Search tasks by title
- ✓ Search agents by name
- ✓ Search team members by name/email
- ✓ Search pending approvals
- ✓ Empty results handling
- ✓ Limit parameter validation
- ✓ Query parameter validation
- ✓ Metadata inclusion
- ✓ Task project context
- ✓ Total count accuracy
- ✓ Special character handling
- ✓ Status filtering (pending approvals, active users)
- ✓ URL formatting

### Running Tests

```bash
php artisan test --filter=CommandPaletteControllerTest
```

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Cmd/Ctrl + K` | Open Command Palette |
| `↑` / `↓` | Navigate results |
| `Enter` | Execute selected command |
| `Esc` | Close palette |
| `>` or `?` prefix | Enter AI mode |

## Multi-Step Tool Chains

The AI mode supports chaining multiple tool calls in a single conversation to complete complex requests like "Create a client, project, and invoice."

### How It Works

The `messageWithTools` loop in `AnthropicService` runs up to 10 iterations. Each iteration, Claude can call one or more tools, receive the results, and decide what to do next. This enables dependent operations where step 2 needs data from step 1.

### Dependency-Aware Chaining

The system prompt guides Claude to:

1. **Plan the sequence** — identify which entities depend on which (client → project → invoice)
2. **Pass IDs forward** — use the `id` returned by each creation tool in subsequent calls
3. **Handle duplicates gracefully** — if a client/project already exists, the tool returns `existing: true` with the record's `id` so the chain can continue without retrying
4. **Never retry identical failures** — if a tool fails, try a different approach or report the issue

### Example: "Create client Acme Corp, a project Website Redesign, and invoice for $5,000"

```
Iteration 1: Claude calls create-client {name: "Acme Corp"}
  → Returns {created: true, client: {id: 42, name: "Acme Corp"}}

Iteration 2: Claude calls create-project {name: "Website Redesign", client_id: 42}
  → Returns {created: true, project: {id: 15, name: "Website Redesign"}}

Iteration 3: Claude calls create-invoice {client_id: 42, project_id: 15, fixed_fees: [{description: "Website Redesign", amount: 5000}]}
  → Returns {success: true, invoice: {number: "INV-0042", total: 5000}}

Iteration 4: Claude responds with summary of all created items
```

### Slug Collision Handling

Both `CreateClientTool` and `CreateProjectTool` check for existing records with the same slug before creating. If found, they return the existing record instead of throwing a database error:

```json
{
  "created": false,
  "existing": true,
  "message": "A client named \"Acme Corp\" already exists.",
  "client": {"id": 42, "name": "Acme Corp", "slug": "acme-corp"}
}
```

This prevents the retry loop that previously caused the AI to call `create-client` repeatedly.

### Name-Based Lookups

All three creation tools support looking up related entities by name as a fallback:

- `CreateProjectTool`: accepts `client_name` as alternative to `client_id`
- `CreateInvoiceTool`: accepts `client_name` as alternative to `client_id`

### Configuration

| Setting | Value | Location |
|---------|-------|----------|
| `max_tokens` | 4096 | `AnthropicService::messageWithTools()` |
| `maxIterations` | 10 | `AnthropicService::messageWithTools()` |
| Tool count | 80+ | Auto-discovered from `app/Agents/Tools/` |

### Frontend Feedback

When a multi-step chain completes, the `tools_used` SSE event includes details about which tools were executed:

```json
{
  "iterations": 4,
  "tools": [
    {"tool": "create-client", "success": true},
    {"tool": "create-project", "success": true},
    {"tool": "create-invoice", "success": true}
  ]
}
```

## Future Enhancements

### Planned Features

1. **Recent searches**: Track and prioritize recent searches
2. **Search history**: Navigate previous searches
3. **Entity icons**: Replace emoji with proper icon library
4. **Fuzzy object search**: Apply fuzzy matching to object searches
5. **Search filters**: Filter by entity type with keyboard shortcuts
6. **Search operators**: Support advanced query syntax (type:project status:active)
7. **Quick actions**: Context actions on search results (mark complete, archive, etc.)
8. **Search analytics**: Track popular searches and improve ranking

### Performance Improvements

1. **Search index**: Dedicated search index table for faster searches
2. **Full-text search**: PostgreSQL or Elasticsearch integration
3. **Search caching**: Cache frequent searches
4. **Prefetching**: Prefetch common searches on palette open

## Related Documentation

- [Command Palette Overview](./FRONTEND.md#command-palette)
- [API Documentation](./API.md#command-palette-endpoints)
- [Agent System](./AGENTS.md)
- [AI Integration](./AI_INTEGRATION.md)
