# Frontend Documentation

Comprehensive documentation for Zao Dash Vue.js frontend architecture.

## Table of Contents
- [Architecture Overview](#architecture-overview)
- [Pages](#pages)
- [Components](#components)
- [Composables](#composables)
- [State Management](#state-management)
- [Component Hierarchy](#component-hierarchy)

---

## Architecture Overview

**Stack:** Vue 3 (Composition API) + TypeScript + Inertia.js + TailwindCSS

**Key Patterns:**
- Single-file components (SFC) with `<script setup>`
- Composables for shared logic and state
- Global event bus for cross-component communication
- Real-time updates via Laravel Reverb WebSockets
- Command palette for keyboard-driven navigation

---

## Pages

### `/resources/js/Pages/Dashboard.vue`
**Route:** `/`

**Props:**
```ts
kpis: KpiData
recentAgentRuns: AgentRun[]
pendingApprovals: ApprovalRequest[]
clients: Client[]
activeGoal: ActiveGoal | null
currentPlan: CurrentPlan | null
```

**Purpose:** Main command center dashboard with KPIs, charts, agent activity, approvals, and proactive insights

**Key Features:**
- 16 KPI chart cards (pipeline, revenue, client health, operations, financial, time tracking, GitHub stats)
- Focus panel integration
- Goal progress and weekly plan widgets
- Agent activity feed with real-time updates
- Pending approvals with review/approval modals
- Proactive insights with agent triggering

**API Endpoints:**
- `GET /api/kpis` - Fetches chart KPI data

---

### `/resources/js/Pages/Projects/Index.vue`
**Route:** `/projects`

**Props:**
```ts
projects: Project[]
stats: { total, active, completed, total_budget }
clients?: Client[]
teamMembers?: TeamMember[]
```

**Purpose:** Project list with CRUD operations

**Features:**
- Stats cards for projects overview
- Project list with progress bars
- Create/edit/delete project modals
- Project filtering by status

---

### `/resources/js/Pages/Projects/Show.vue`
**Route:** `/projects/{slug}`

**Props:**
```ts
project: Project
stats: Stats
team: TeamMember[]
```

**Purpose:** Single project detail view with tabs

**Tabs:**
- Overview (milestones, team, recent tasks)
- Tasks (filterable task list with status toggle)
- Activity (project timeline)
- Settings (edit project, integrations, danger zone)

---

### `/resources/js/Pages/Clients/Index.vue`
**Route:** `/clients`

**Props:**
```ts
clients: Client[]
stats: { total, active, avg_health }
```

**Purpose:** Client management grid

**Features:**
- Client cards with health scores
- Contact management
- Create/edit/delete client modals
- Multi-contact support with primary designation

---

### `/resources/js/Pages/Clients/Show.vue`
**Route:** `/clients/{slug}`

**Props:**
```ts
client: Client
stats: Stats
recentActivity: Activity[]
```

**Purpose:** Client detail with tabs

**Tabs:**
- Overview (health factors, active projects, activity)
- Projects (filterable project table)
- Contacts (contact cards with actions)
- Notes (internal notes timeline)

---

### `/resources/js/Pages/Team/Index.vue`
**Route:** `/team`

**Props:**
```ts
users: User[]
stats: { total, owners, staff }
```

**Purpose:** Team member management

**Features:**
- Team member cards with task counts
- Invite modal with role/permission management
- Edit member modal with granular permissions
- Member detail view
- Remove confirmation

---

### `/resources/js/Pages/Auth/Login.vue`
**Route:** `/login`

**Props:** None

**Purpose:** Authentication page

---

### `/resources/js/Pages/Profile/Edit.vue`
**Route:** `/profile`

**Props:**
```ts
user: User
```

**Purpose:** User profile editing

**Features:**
- Profile header with avatar
- Form validation with Inertia.js
- Flash success messages
- Role and account info display

---

### `/resources/js/Pages/Settings/Integrations.vue`
**Route:** `/settings/integrations`

**Props:**
```ts
integrations: {
  google: GoogleIntegration
  slack: SlackIntegration
  github: GitHubIntegration
  harvest: HarvestIntegration
  notion: NotionIntegration
  wordpress: WordPressIntegration
  quickbooks: QuickBooksIntegration
  linkedin: LinkedInIntegration
  x: XIntegration
}
```

**Purpose:** Manage all third-party integrations with setup guides

**Integrations:**
- **Google Workspace:** OAuth, Gmail/Calendar/Drive sync
- **Slack:** Multi-workspace, channel sync, webhooks
- **GitHub:** App-based, repos/issues/PRs
- **Harvest:** Time tracking, invoices
- **Notion:** Pages, databases
- **WordPress:** Application passwords, MCP support, post sync
- **QuickBooks:** Invoices, expenses, financial data
- **LinkedIn:** Profile, company pages, posting
- **X (Twitter):** Posts, threads, engagement

**Features:**
- Connection status badges
- OAuth flows
- Setup guides (expandable)
- Sync buttons per integration
- Environment variable examples
- Webhook configuration docs
- Multi-workspace/multi-account support

---

## Components

### Layout Components

#### `/resources/js/Layouts/AppLayout.vue`
**Props:**
```ts
title?: string
runningAgents?: number
pendingApprovals?: number
```

**Purpose:** Main application layout with sidebar navigation

**Features:**
- Sidebar navigation with 10 main routes
- Command palette trigger
- Notification bell
- Theme toggle (dark/light)
- User dropdown menu
- System status indicator

**Slots:**
- Default slot for page content

**Events:** None

---

### Core Components

#### `/resources/js/Components/CommandPalette.vue`
**Props:** None

**Purpose:** Global command palette for navigation, actions, and AI chat

**Features:**
- Fuzzy search across commands
- Navigation commands
- Action commands (create task/project/client)
- Dynamic agent commands
- AI mode (triggered by `>` prefix)
- Streaming AI responses
- Recent command tracking

**Events:** None (uses global state)

**Keyboard:**
- `⌘K` / `Ctrl+K` - Open palette
- `↑↓` - Navigate
- `Enter` - Execute
- `Esc` - Close

---

#### `/resources/js/Components/Modal.vue`
**Props:**
```ts
show: boolean
title?: string
size?: 'sm' | 'md' | 'lg' | 'xl' | 'full'
closeable?: boolean (default: true)
```

**Purpose:** Reusable modal dialog

**Slots:**
- `header` - Custom header (overrides title)
- Default - Modal body
- `footer` - Action buttons

**Events:**
- `close` - Emitted when modal should close

---

#### `/resources/js/Components/FocusPanel.vue`
**Props:** None

**Purpose:** Collapsible panel showing prioritized action items

**Features:**
- Fetches focus items from `/api/capabilities/focus`
- Priority indicators (critical, high, medium)
- Briefing with recommendations
- Loading and empty states
- Collapsible UI

**API:**
- `GET /api/capabilities/focus`

---

#### `/resources/js/Components/ProactiveInsights.vue`
**Props:** None

**Purpose:** AI-generated insights card grid

**Events:**
```ts
action(insight: Insight) - User clicks insight action
```

**Features:**
- Fetches insights from `/api/insights`
- Insight cards with action buttons
- Agent badge display
- Refresh button

**API:**
- `GET /api/insights?limit=6`

---

#### `/resources/js/Components/NotificationBell.vue`
**Props:** None

**Purpose:** Real-time notification bell with dropdown

**Features:**
- Unread count badge (99+ max)
- Connection status indicator
- Dropdown notification list
- Mark as read/dismiss actions
- Real-time updates via Reverb
- Click outside to close
- Severity-based colors
- Action URLs for navigation

**Uses Composables:**
- `useNotifications()` - Real-time notification state

**Events:** None (uses composable)

---

#### `/resources/js/Components/ToastContainer.vue`
**Props:** None

**Purpose:** Toast notification container (bottom-right)

**Features:**
- Listens for global `show-toast` events
- Auto-dismiss after duration (default 5s)
- Action button support
- Type-based styling (info, success, warning, error)
- Smooth transitions
- Click to dismiss
- Position: bottom-right, fixed

**API:**
- Listens to `window.dispatchEvent('show-toast', ...)`
- Use via `useToast()` composable

---

### Form Components

#### `/resources/js/Components/FormInput.vue`
**Props:**
```ts
modelValue: string
label: string
type?: string (default: 'text')
placeholder?: string
required?: boolean
disabled?: boolean
error?: string
hint?: string
prefix?: string
suffix?: string
```

**Events:**
- `update:modelValue`

---

#### `/resources/js/Components/FormSelect.vue`
**Props:**
```ts
modelValue: string | number
label: string
options: { value: string|number, label: string }[]
placeholder?: string
required?: boolean
disabled?: boolean
error?: string
```

**Events:**
- `update:modelValue`

---

#### `/resources/js/Components/FormTextarea.vue`
**Props:**
```ts
modelValue: string
label: string
rows?: number (default: 3)
placeholder?: string
required?: boolean
disabled?: boolean
error?: string
hint?: string
```

**Events:**
- `update:modelValue`

---

#### `/resources/js/Components/FormToggle.vue`
**Props:**
```ts
modelValue: boolean
label: string
description?: string
disabled?: boolean
```

**Events:**
- `update:modelValue`

---

#### `/resources/js/Components/FormCheckbox.vue`
**Props:**
```ts
modelValue: boolean
label?: string
disabled?: boolean
```

**Events:**
- `update:modelValue`

---

### Display Components

#### `/resources/js/Components/Card.vue`
Basic card container

#### `/resources/js/Components/CardHeader.vue`
Card header with title and actions

#### `/resources/js/Components/Badge.vue`
**Props:**
```ts
variant?: 'default' | 'success' | 'warning' | 'error' | 'info'
size?: 'sm' | 'md' | 'lg'
```

#### `/resources/js/Components/Button.vue`
**Props:**
```ts
variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
size?: 'sm' | 'md' | 'lg'
loading?: boolean
disabled?: boolean
type?: 'button' | 'submit' | 'reset'
```

#### `/resources/js/Components/Avatar.vue`
User avatar with initials

#### `/resources/js/Components/EmptyState.vue`
Empty state placeholder

#### `/resources/js/Components/StatusOrb.vue`
**Props:**
```ts
status: 'running' | 'completed' | 'pending' | 'failed'
```

---

### Chart Components

All charts use Unovis library.

#### `/resources/js/Components/Charts/ChartCard.vue`
**Props:**
```ts
title: string
value: string | number
subtitle?: string
trend?: number
loading?: boolean
```

**Slots:**
- Default - Chart content

#### `/resources/js/Components/Charts/AreaChart.vue`
**Props:**
```ts
data: number[]
color?: string (default: accent)
height?: number (default: 64)
```

#### `/resources/js/Components/Charts/BarChart.vue`
**Props:**
```ts
data: { name: string, value: number, color?: string }[]
height?: number (default: 80)
horizontal?: boolean (default: false)
showLabels?: boolean (default: false)
```

#### `/resources/js/Components/Charts/DonutChart.vue`
**Props:**
```ts
data: number[]
labels: string[]
colors?: string[]
height?: number (default: 120)
showLegend?: boolean (default: false)
```

#### `/resources/js/Components/Charts/RadialGauge.vue`
**Props:**
```ts
value: number
max: number
color?: string (default: accent)
height?: number (default: 100)
```

#### `/resources/js/Components/Charts/ChartTooltip.vue`
Animated tooltip for chart hover states

---

### Widget Components

#### `/resources/js/Components/GoalProgressWidget.vue`
**Props:**
```ts
goal: ActiveGoal | null
```

Displays annual goal with progress ring

#### `/resources/js/Components/WeeklyPlanWidget.vue`
**Props:**
```ts
plan: CurrentPlan | null
```

Shows current week's plan with completion status

#### `/resources/js/Components/AgentQuickTrigger.vue`
Quick agent trigger buttons

#### `/resources/js/Components/InsightCard.vue`
Insight display card

#### `/resources/js/Components/HealthScore.vue`
**Props:**
```ts
score: number
size?: 'sm' | 'md' | 'lg'
```

Client health score indicator

#### `/resources/js/Components/KpiCard.vue`
**Props:**
```ts
label: string
value: string | number
change?: string
changeType?: 'positive' | 'negative'
suffix?: string
```

---

## Composables

### `/resources/js/composables/useNotifications.ts`

**Purpose:** Real-time notifications with Reverb WebSockets

**Returns:**
```ts
{
  notifications: Ref<Notification[]>
  unreadCount: Ref<number>
  isConnected: Ref<boolean>
  fetchNotifications: () => Promise<void>
  fetchUnreadCount: () => Promise<void>
  markAsRead: (notification) => Promise<void>
  markAllAsRead: () => Promise<void>
  dismiss: (notification) => Promise<void>
  connect: () => void
  disconnect: () => void
}
```

**Channels:**
- `notifications` (public)
- `notifications.{userId}` (private)

**Events Listened:**
- `.notification.created`

---

### `/resources/js/composables/useToast.ts`

**Purpose:** Global toast notification system

**Returns:**
```ts
{
  show: (options: ToastOptions) => void
  success: (title, message, duration?) => void
  error: (title, message, duration?) => void
  warning: (title, message, duration?) => void
  info: (title, message, duration?) => void
}
```

**Usage:**
```ts
const toast = useToast()
toast.success('Success', 'Data saved')
```

---

### `/resources/js/composables/useCommandPalette.ts`

**Purpose:** Command palette search and execution logic

**Returns:**
```ts
{
  query: Ref<string>
  selectedIndex: Ref<number>
  results: ComputedRef<Command[]>
  groupedResults: ComputedRef<Record<string, Command[]>>
  isAIMode: Ref<boolean>
  recentCommands: Ref<RecentCommand[]>
  selectPrevious: () => void
  selectNext: () => void
  executeSelected: () => Promise<boolean>
  executeCommand: (id: string) => Promise<boolean>
  clearQuery: () => void
  allCommands: ComputedRef<Command[]>
}
```

**Features:**
- Fuzzy search via Fuse.js
- Recent command tracking
- Dynamic agent command loading
- AI mode detection (queries starting with `>` or `?`)

**API Endpoints:**
- `GET /api/agents` - Load agent commands
- `GET /api/command-palette/recent` - Recent commands
- `POST /api/command-palette/log` - Log command execution
- `POST /agents/{slug}/trigger` - Trigger agent

---

### `/resources/js/composables/useGlobalCommandPalette.ts`

**Purpose:** Global keyboard shortcuts for command palette

**Returns:**
```ts
{
  isOpen: Ref<boolean>
  openPalette: () => void
  closePalette: () => void
  togglePalette: () => void
}
```

**Keyboard Shortcuts:**
- `⌘K` / `Ctrl+K` - Toggle palette
- `Esc` - Close palette

---

### `/resources/js/composables/useAIStreaming.ts`

**Purpose:** AI chat streaming for command palette

**Returns:**
```ts
{
  isStreaming: Ref<boolean>
  currentResponse: Ref<string>
  error: Ref<string | null>
  messages: Ref<AIMessage[]>
  sendMessage: (message: string) => Promise<string>
  clearMessages: () => void
  lastResponse: ComputedRef<string>
}
```

**API:**
- `POST /api/command-palette/chat` - SSE streaming endpoint

**Features:**
- Server-sent events (SSE) streaming
- Page context awareness
- Conversation history (last 10 messages)

---

### `/resources/js/composables/useAgentRealtime.ts`

**Purpose:** Real-time agent run updates

**Functions:**

#### `useAgentRealtime(agentId?: number)`
```ts
{
  latestUpdate: Ref<AgentRunUpdate | null>
  outputChunks: Ref<string[]>
  isConnected: Ref<boolean>
  connect: () => void
  disconnect: () => void
}
```

**Channels:**
- `agents` (public)
- `agents.{agentId}` (private)

#### `useAgentRunRealtime(runId: number)`
```ts
{
  status: Ref<string>
  output: Ref<string>
  isComplete: Ref<boolean>
  isConnected: Ref<boolean>
  connect: () => void
  disconnect: () => void
}
```

**Channels:**
- `agent-runs.{runId}` (private)

**Events:**
- `.run.status.changed`
- `.run.output.updated`

---

## State Management

### Global State
- **Command Palette:** `useGlobalCommandPalette()` - Singleton ref for open/closed state
- **Toast Notifications:** Custom events via `window.dispatchEvent('show-toast')`

### Real-time State
- **Notifications:** Reverb WebSocket channels
- **Agent Runs:** Reverb WebSocket channels
- **Focus Items:** Polled via API

### Local State
- Component-level `ref()` and `reactive()` for form data
- Computed values for derived state
- Watch for side effects

### Server State (Inertia.js)
- Props passed from Laravel controllers
- Form submissions via `router.post/put/delete`
- Page visits via `router.visit()`

---

## Component Hierarchy

```
AppLayout
├── CommandPalette (global)
├── ToastContainer (global)
├── NotificationBell
├── Sidebar Navigation
└── Main Content Area
    ├── Dashboard
    │   ├── FocusPanel
    │   ├── GoalProgressWidget
    │   ├── WeeklyPlanWidget
    │   ├── ChartCard × 16
    │   │   ├── AreaChart
    │   │   ├── DonutChart
    │   │   ├── BarChart
    │   │   └── RadialGauge
    │   ├── AgentQuickTrigger
    │   ├── ProactiveInsights
    │   └── Modal (approvals, insights)
    │
    ├── Projects/Index
    │   ├── KpiCard × 4
    │   └── Modal (create/edit/delete)
    │
    ├── Projects/Show
    │   ├── Tabs
    │   ├── FormCheckbox (task status)
    │   └── Modal (task, archive, delete)
    │
    ├── Clients/Index
    │   ├── Client Cards
    │   │   └── Avatar
    │   └── Modal (create/edit/delete)
    │
    ├── Clients/Show
    │   ├── Tabs
    │   ├── Health Factors
    │   └── Modal (contacts, projects)
    │
    └── Team/Index
        ├── Team Cards
        │   └── Avatar
        └── Modal (invite, edit, detail, remove)
```

---

## Additional Components

### Utility Components

#### `/resources/js/Components/ActivityFeed.vue`
Activity timeline feed component

#### `/resources/js/Components/AgentQuickTrigger.vue`
Quick action buttons for triggering agents

#### `/resources/js/Components/EmergencyControls.vue`
Emergency stop/pause controls for agents

#### `/resources/js/Components/FunnelChart.vue`
Sales funnel visualization component

#### `/resources/js/Components/LeversPanel.vue`
Interactive panel for adjusting business levers/goals

#### `/resources/js/Components/SequenceBuilder.vue`
Visual builder for creating automation sequences

---

### Widget Components (Dashboard)

#### `/resources/js/Components/GoalProgressWidget.vue`
**Props:**
```ts
goal: ActiveGoal | null
```
Annual goal progress with circular gauge

#### `/resources/js/Components/WeeklyPlanWidget.vue`
**Props:**
```ts
plan: CurrentPlan | null
```
Current week's plan with completion status

---

### Additional Display Components

#### `/resources/js/Components/Card.vue`
Basic card container component

#### `/resources/js/Components/CardHeader.vue`
Card header with title and action slots

#### `/resources/js/Components/Badge.vue`
**Props:**
```ts
variant?: 'default' | 'success' | 'warning' | 'error' | 'info'
size?: 'sm' | 'md' | 'lg'
```

#### `/resources/js/Components/Button.vue`
**Props:**
```ts
variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
size?: 'sm' | 'md' | 'lg'
loading?: boolean
disabled?: boolean
type?: 'button' | 'submit' | 'reset'
```

#### `/resources/js/Components/Avatar.vue`
User avatar with initials fallback

#### `/resources/js/Components/EmptyState.vue`
Empty state placeholder with icon and message

#### `/resources/js/Components/StatusOrb.vue`
**Props:**
```ts
status: 'running' | 'completed' | 'pending' | 'failed'
```
Animated status indicator orb

#### `/resources/js/Components/HealthScore.vue`
**Props:**
```ts
score: number (0-100)
size?: 'sm' | 'md' | 'lg'
```
Client health score indicator with color coding

#### `/resources/js/Components/KpiCard.vue`
**Props:**
```ts
label: string
value: string | number
change?: string
changeType?: 'positive' | 'negative'
suffix?: string
```
KPI stat card with optional trend indicator

#### `/resources/js/Components/InsightCard.vue`
Proactive insight card display component

---

### Chart Components (Unovis)

All chart components use the Unovis charting library for consistent, performant visualizations.

#### `/resources/js/Components/Charts/ChartCard.vue`
**Props:**
```ts
title: string
value: string | number
subtitle?: string
trend?: number (percentage)
loading?: boolean
```

**Slots:**
- Default - Chart content

Wrapper card for KPI charts with title, value, subtitle, and trend

#### `/resources/js/Components/Charts/AreaChart.vue`
**Props:**
```ts
data: number[]
color?: string (default: accent)
height?: number (default: 64)
gradient?: boolean (default: true)
```

Smooth area chart for trends

#### `/resources/js/Components/Charts/BarChart.vue`
**Props:**
```ts
data: { name: string, value: number, color?: string }[]
height?: number (default: 80)
horizontal?: boolean (default: false)
showLabels?: boolean (default: false)
colorScheme?: string[]
```

Vertical or horizontal bar chart

#### `/resources/js/Components/Charts/DonutChart.vue`
**Props:**
```ts
data: number[]
labels: string[]
colors?: string[]
height?: number (default: 120)
showLegend?: boolean (default: false)
centerLabel?: string
centerValue?: string | number
```

Donut/pie chart with optional center label

#### `/resources/js/Components/Charts/RadialGauge.vue`
**Props:**
```ts
value: number
max: number
color?: string (default: accent)
height?: number (default: 100)
showValue?: boolean (default: true)
label?: string
```

Circular progress gauge

#### `/resources/js/Components/Charts/ChartTooltip.vue`
**Props:**
```ts
x: number
y: number
data: Record<string, any>
visible: boolean
```

Animated tooltip for chart hover states

#### `/resources/js/Components/FunnelChart.vue`
**Props:**
```ts
stages: { name: string, value: number, color?: string }[]
```

Sales funnel visualization

---

## Key Patterns & Conventions

### TypeScript Interfaces
- Define props interfaces inline with `defineProps<Interface>()`
- Use PascalCase for type names
- Group related types in same file

### Events
- Use `defineEmits<{ (e: 'eventName', payload: Type): void }>()`
- Kebab-case event names
- Emit upward, props downward

### Styling
- Scoped styles in `<style scoped>`
- CSS custom properties for theming (e.g., `var(--color-text-primary)`)
- Utility classes via TailwindCSS
- Component-specific classes for layout

### API Calls
- Use native `fetch()` API
- CSRF token from meta tag
- Loading states with `ref()`
- Error handling with try/catch

### Real-time
- Initialize WebSocket connections in `onMounted()`
- Clean up in `onUnmounted()`
- Use composables for reusable WebSocket logic

### Forms
- Use Inertia.js `useForm()` for server-side validation
- Use `v-model` for two-way binding
- FormInput/FormSelect/etc components for consistency

---

## File Structure

```
resources/js/
├── Pages/               # Inertia.js pages (routes)
│   ├── Dashboard.vue
│   ├── Projects/
│   ├── Clients/
│   ├── Team/
│   ├── Auth/
│   └── Profile/
├── Components/          # Reusable components
│   ├── Charts/         # Chart components
│   ├── Form*.vue       # Form components
│   ├── Modal.vue
│   ├── CommandPalette.vue
│   └── ...
├── Layouts/            # Layout components
│   └── AppLayout.vue
├── composables/        # Shared logic
│   ├── useNotifications.ts
│   ├── useToast.ts
│   ├── useCommandPalette.ts
│   ├── useGlobalCommandPalette.ts
│   ├── useAIStreaming.ts
│   └── useAgentRealtime.ts
├── types/              # TypeScript definitions
│   └── index.d.ts
├── lib/                # Utilities
│   └── utils.ts
├── app.ts              # Inertia.js app entry
├── echo.ts             # Reverb WebSocket setup
└── bootstrap.js        # Bootstrap setup
```

---

## Development Guidelines

### Adding a New Page
1. Create Vue SFC in `resources/js/Pages/`
2. Define props interface
3. Use AppLayout wrapper
4. Add to Laravel routes
5. Return from controller with Inertia

### Adding a New Component
1. Create in `resources/js/Components/`
2. Define props with TypeScript
3. Define emits if needed
4. Add scoped styles
5. Export from `Components/index.ts` if frequently used

### Adding Real-time Features
1. Create composable in `composables/`
2. Use `echo` from `@/echo`
3. Subscribe in `onMounted()`
4. Unsubscribe in `onUnmounted()`
5. Define channel events in Laravel

### Testing
- Manual testing via UI
- Browser console for errors
- Vue DevTools for component inspection
- Network tab for API calls

---

## Dependencies

**Core:**
- Vue 3
- TypeScript
- Inertia.js
- TailwindCSS

**Charts:**
- Unovis

**Search:**
- Fuse.js (fuzzy search)

**Real-time:**
- Laravel Echo
- Pusher JS (Reverb protocol)

**Build:**
- Vite

---

## Notes

- All paths are absolute (use `@/` alias)
- Dark theme is default
- Real-time requires Reverb configured
- Command palette is keyboard-first
- Forms use Inertia.js for server validation
- Charts are client-side rendered
- All timestamps use ISO 8601 format

---

## Summary

### File Counts

**Pages:** 8
- Dashboard.vue
- Projects/Index.vue
- Projects/Show.vue
- Clients/Index.vue
- Clients/Show.vue
- Team/Index.vue
- Auth/Login.vue
- Profile/Edit.vue
- Settings/Integrations.vue

**Layouts:** 1
- AppLayout.vue

**Core Components:** 8
- CommandPalette.vue
- Modal.vue
- FocusPanel.vue
- ProactiveInsights.vue
- NotificationBell.vue
- ToastContainer.vue
- ActivityFeed.vue
- EmergencyControls.vue

**Form Components:** 5
- FormInput.vue
- FormSelect.vue
- FormTextarea.vue
- FormToggle.vue
- FormCheckbox.vue

**Display Components:** 12
- Card.vue
- CardHeader.vue
- Badge.vue
- Button.vue
- Avatar.vue
- EmptyState.vue
- StatusOrb.vue
- HealthScore.vue
- KpiCard.vue
- InsightCard.vue
- GoalProgressWidget.vue
- WeeklyPlanWidget.vue

**Chart Components:** 7
- ChartCard.vue
- AreaChart.vue
- BarChart.vue
- DonutChart.vue
- RadialGauge.vue
- ChartTooltip.vue
- FunnelChart.vue

**Utility Components:** 3
- AgentQuickTrigger.vue
- LeversPanel.vue
- SequenceBuilder.vue

**Composables:** 6
- useNotifications.ts
- useToast.ts
- useCommandPalette.ts
- useGlobalCommandPalette.ts
- useAIStreaming.ts
- useAgentRealtime.ts

**Total Vue Files:** 50+
**Total TypeScript Files:** 6

---

## Quick Reference

### Most Important Files

1. **AppLayout.vue** - Main application wrapper
2. **Dashboard.vue** - Primary landing page with KPIs
3. **CommandPalette.vue** - Global command/search interface
4. **useCommandPalette.ts** - Command palette logic
5. **useNotifications.ts** - Real-time notifications
6. **Settings/Integrations.vue** - Integration management

### Common Patterns

- Use `<script setup lang="ts">` for all components
- Props: `defineProps<Interface>()`
- Events: `defineEmits<{ ... }>()`
- Composables: `export function useX() { ... }`
- Styles: Scoped with CSS custom properties
- Forms: Inertia.js `useForm()`
- Real-time: Laravel Echo + Reverb

### Development Workflow

1. **Add Page:** Create SFC in `Pages/` → Add Laravel route → Controller with Inertia
2. **Add Component:** Create in `Components/` → Import where needed
3. **Add Composable:** Create in `composables/` → Import and call
4. **Add Real-time:** Create channel in Laravel → Listen in composable
5. **Styling:** Use CSS custom properties + TailwindCSS utilities

---

## Maintenance

This documentation was generated on 2025-12-13 by sweeping the entire `resources/js/` directory. To keep it updated:

1. Document new pages when added
2. Document new components with props/events/slots
3. Document new composables with return values
4. Update component hierarchy when structure changes
5. Add new patterns as they emerge

**Last Updated:** 2025-12-13
