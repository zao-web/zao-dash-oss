<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import FormCheckbox from '@/Components/FormCheckbox.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormTextarea from '@/Components/FormTextarea.vue'
import Modal from '@/Components/Modal.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'

interface Run {
    id: number
    session_id: string
    status: 'running' | 'completed' | 'failed' | 'pending_approval'
    task: string
    cost_usd: number
    duration_ms: number | null
    started_at: string
    completed_at: string | null
    has_approval: boolean
}

interface Agent {
    id: number
    name: string
    slug: string
    description: string
    status: 'active' | 'paused' | 'disabled'
    model: string
    requires_approval: boolean
    max_budget_usd: number
    allowed_tools: string[] | null
    circuit_broken_at: string | null
}

interface Stats {
    total_runs: number
    completed_runs: number
    failed_runs: number
    success_rate: number
    total_cost: number
    avg_cost: number
    avg_duration_ms: number
    runs_today: number
    cost_today: number
}

const props = defineProps<{
    agent: Agent
    stats: Stats
    runs: Run[]
    costHistory: { date: string; cost: number }[]
}>()

const activeTab = ref<'overview' | 'runs' | 'config'>('overview')

const statusColors: Record<string, string> = {
    running: 'var(--color-status-blue)',
    completed: 'var(--color-status-green)',
    failed: 'var(--color-status-red)',
    pending_approval: 'var(--color-status-orange)'
}

const modelColors: Record<string, string> = {
    opus: 'var(--color-status-purple)',
    sonnet: 'var(--color-status-blue)',
    haiku: 'var(--color-status-green)'
}

const modelOptions = [
    { value: 'opus', label: 'Opus' },
    { value: 'sonnet', label: 'Sonnet' },
    { value: 'haiku', label: 'Haiku' },
]

const agentStatusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'paused', label: 'Paused' },
    { value: 'disabled', label: 'Disabled' },
]

const formatDuration = (ms: number | null) => {
    if (!ms) return '—'
    if (ms < 1000) return `${ms}ms`
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`
}

const isCloning = ref(false)
const isTogglingStatus = ref(false)
const isRunningManually = ref(false)

const showRunModal = ref(false)
const runTaskInput = ref('')

const openRunModal = () => {
    runTaskInput.value = ''
    showRunModal.value = true
}

const cloneAgent = () => {
    isCloning.value = true
    router.post(`/agents/${props.agent.id}/clone`, {}, {
        onFinish: () => {
            isCloning.value = false
        },
    })
}

const toggleStatus = () => {
    isTogglingStatus.value = true
    const newStatus = props.agent.status === 'active' ? 'paused' : 'active'
    router.post(`/agents/${props.agent.slug}/status`, { status: newStatus }, {
        preserveScroll: true,
        onFinish: () => {
            isTogglingStatus.value = false
        },
    })
}

const showRunMenu = ref(false)

const runManually = (mode?: string) => {
    isRunningManually.value = true
    showRunMenu.value = false
    showRunModal.value = false
    
    const payload: Record<string, any> = {}
    if (mode) {
        payload.context = { mode }
    }
    if (runTaskInput.value.trim()) {
        payload.prompt = runTaskInput.value.trim()
    }
    
    router.post(`/agents/${props.agent.slug}/trigger`, payload, {
        preserveScroll: true,
        onFinish: () => {
            isRunningManually.value = false
            runTaskInput.value = ''
        },
    })
}

// Check if this agent supports run modes (like business-strategist)
const hasRunModes = computed(() => props.agent.slug === 'business-strategist')

// Config form state
const configForm = ref({
    requires_approval: props.agent.requires_approval,
    allowed_tools: props.agent.allowed_tools ?? ['bash', 'read', 'write', 'edit', 'grep', 'glob', 'web_fetch', 'web_search'],
    max_budget_usd: props.agent.max_budget_usd,
    status: props.agent.status,
    model: props.agent.model,
})

const allTools = ['bash', 'read', 'write', 'edit', 'grep', 'glob', 'web_fetch', 'web_search']

const isToolEnabled = (tool: string) => {
    return configForm.value.allowed_tools.includes(tool)
}

const toggleTool = (tool: string) => {
    if (isToolEnabled(tool)) {
        configForm.value.allowed_tools = configForm.value.allowed_tools.filter(t => t !== tool)
    } else {
        configForm.value.allowed_tools = [...configForm.value.allowed_tools, tool]
    }
}

const isSavingConfig = ref(false)

const saveConfig = () => {
    isSavingConfig.value = true
    router.put(`/agents/${props.agent.slug}`, {
        name: props.agent.name,
        description: props.agent.description,
        model: configForm.value.model,
        status: configForm.value.status,
        requires_approval: configForm.value.requires_approval,
        max_budget_usd: configForm.value.max_budget_usd,
        tools: configForm.value.allowed_tools,
    }, {
        preserveScroll: true,
        onFinish: () => {
            isSavingConfig.value = false
        },
    })
}

// History state management for tabs
const handleHashNavigation = () => {
    const hash = window.location.hash.replace('#', '')
    if (['overview', 'runs', 'config'].includes(hash)) {
        activeTab.value = hash as typeof activeTab.value
    }
}

const handlePopState = () => {
    handleHashNavigation()
}

watch(activeTab, (newTab) => {
    if (window.location.hash.replace('#', '') !== newTab) {
        history.replaceState({ tab: newTab }, '', `#${newTab}`)
    }
})

onMounted(() => {
    handleHashNavigation()
    window.addEventListener('popstate', handlePopState)
})

onUnmounted(() => {
    window.removeEventListener('popstate', handlePopState)
})
</script>

<template>
    <AppLayout>
        <div class="page-container">
            <!-- Header -->
            <div class="agent-header">
                <div class="agent-icon" :style="{ background: modelColors[agent.model] || 'var(--color-status-blue)' }">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" fill="currentColor"/>
                    </svg>
                </div>
                <div class="agent-info">
                    <div class="agent-name-row">
                        <h1>{{ agent.name }}</h1>
                        <span :class="['badge', `badge-${agent.status}`]">{{ agent.status }}</span>
                        <span class="badge" :style="{ background: modelColors[agent.model], color: 'white' }">{{ agent.model }}</span>
                        <span v-if="agent.requires_approval" class="badge badge-approval">
                            <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
                                <path d="M8 1v6l4 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            Requires Approval
                        </span>
                    </div>
                    <p class="agent-description">{{ agent.description }}</p>
                    <div class="agent-meta">
                        <span class="meta-item">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.5 3.5l1.4 1.4M11.1 11.1l1.4 1.4M3.5 12.5l1.4-1.4M11.1 4.9l1.4-1.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            Max ${{ agent.max_budget_usd }}/run
                        </span>
                        <span class="meta-item slug">{{ agent.slug }}</span>
                    </div>
                </div>
                <div class="quick-actions">
                    <button
                        v-if="agent.status === 'active'"
                        class="btn btn-secondary"
                        :disabled="isTogglingStatus"
                        @click="toggleStatus"
                    >
                        {{ isTogglingStatus ? 'Pausing...' : 'Pause' }}
                    </button>
                    <button
                        v-else
                        class="btn btn-primary"
                        :disabled="isTogglingStatus"
                        @click="toggleStatus"
                    >
                        {{ isTogglingStatus ? 'Activating...' : 'Activate' }}
                    </button>
                    <!-- Run button with optional dropdown for agents with modes -->
                    <div v-if="hasRunModes" class="run-dropdown">
                        <button
                            class="btn btn-secondary"
                            :disabled="isRunningManually"
                            @click="showRunMenu = !showRunMenu"
                        >
                            {{ isRunningManually ? 'Starting...' : 'Run ▾' }}
                        </button>
                        <div v-if="showRunMenu" class="run-menu">
                            <button class="run-menu-item" @click="runManually()">
                                🔄 Auto (based on day)
                            </button>
                            <button class="run-menu-item" @click="runManually('planning')">
                                📋 Weekly Planning
                            </button>
                            <button class="run-menu-item" @click="runManually('checkin')">
                                ✓ Daily Check-in
                            </button>
                        </div>
                    </div>
                    <button
                        v-else
                        class="btn btn-secondary"
                        :disabled="isRunningManually"
                        @click="openRunModal"
                    >
                        {{ isRunningManually ? 'Starting...' : 'Run Manually' }}
                    </button>
                    <button
                        class="btn btn-secondary"
                        :disabled="isCloning"
                        @click="cloneAgent"
                    >
                        <svg v-if="!isCloning" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        {{ isCloning ? 'Cloning...' : 'Clone' }}
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button
                    v-for="tab in ['overview', 'runs', 'config']"
                    :key="tab"
                    :class="['tab', { active: activeTab === tab }]"
                    @click="activeTab = tab as any"
                >
                    {{ tab === 'config' ? 'Configuration' : tab.charAt(0).toUpperCase() + tab.slice(1) }}
                </button>
            </div>

            <!-- Overview Tab -->
            <div v-if="activeTab === 'overview'" class="tab-content">
                <div class="stats-grid">
                    <div class="metric-card">
                        <div class="metric-label">TOTAL RUNS</div>
                        <div class="metric-value">{{ stats.total_runs }}</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">SUCCESS RATE</div>
                        <div class="metric-value" :style="{ color: stats.success_rate >= 90 ? 'var(--color-status-green)' : stats.success_rate >= 70 ? 'var(--color-status-orange)' : 'var(--color-status-red)' }">
                            {{ stats.success_rate }}%
                        </div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">TOTAL COST</div>
                        <div class="metric-value">${{ stats.total_cost.toFixed(2) }}</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">AVG COST/RUN</div>
                        <div class="metric-value">${{ stats.avg_cost.toFixed(3) }}</div>
                    </div>
                </div>

                <div class="two-col">
                    <!-- Performance -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Performance</h3>
                        </div>
                        <div class="performance-stats">
                            <div class="perf-item">
                                <div class="perf-label">Average Duration</div>
                                <div class="perf-value">{{ formatDuration(stats.avg_duration_ms) }}</div>
                            </div>
                            <div class="perf-item">
                                <div class="perf-label">Runs Today</div>
                                <div class="perf-value">{{ stats.runs_today }}</div>
                            </div>
                            <div class="perf-item">
                                <div class="perf-label">Cost Today</div>
                                <div class="perf-value">${{ stats.cost_today.toFixed(2) }}</div>
                            </div>
                            <div class="perf-item">
                                <div class="perf-label">Failed Runs</div>
                                <div class="perf-value" :style="{ color: stats.failed_runs > 0 ? 'var(--color-status-red)' : 'var(--color-text-primary)' }">
                                    {{ stats.failed_runs }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cost Breakdown -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Cost Trend (7 days)</h3>
                        </div>
                        <div class="cost-chart">
                            <div class="chart-bars">
                                <div
                                    v-for="day in costHistory"
                                    :key="day.date"
                                    class="chart-bar-container"
                                >
                                    <div
                                        class="chart-bar"
                                        :style="{ height: `${Math.max(4, (day.cost / Math.max(...costHistory.map(d => d.cost), 1)) * 100)}%` }"
                                        :title="`$${Number(day.cost || 0).toFixed(3)}`"
                                    >
                                        <span class="chart-tooltip">${{ Number(day.cost || 0).toFixed(3) }}</span>
                                    </div>
                                    <span class="chart-label">{{ day.date }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Runs -->
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Runs</h3>
                        <button class="btn btn-secondary" @click="activeTab = 'runs'">View All</button>
                    </div>
                    <div class="runs-list">
                        <div v-for="run in runs.slice(0, 5)" :key="run.id" class="run-item">
                            <div class="run-status">
                                <div class="status-dot" :style="{ background: statusColors[run.status] }"></div>
                            </div>
                            <div class="run-info">
                                <div class="run-task">{{ run.task }}</div>
                                <div class="run-meta">
                                    <span class="run-time">{{ run.started_at }}</span>
                                    <span v-if="run.duration_ms" class="run-duration">{{ formatDuration(run.duration_ms) }}</span>
                                </div>
                            </div>
                            <div class="run-cost">${{ Number(run.cost_usd || 0).toFixed(3) }}</div>
                            <Link :href="`/agents/${agent.slug}/runs/${run.id}`" class="btn btn-ghost btn-sm">
                                View
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Runs Tab -->
            <div v-if="activeTab === 'runs'" class="tab-content">
                <div class="card">
                    <div class="runs-table">
                        <div class="table-header">
                            <div class="col-status">Status</div>
                            <div class="col-task">Task</div>
                            <div class="col-session">Session</div>
                            <div class="col-duration">Duration</div>
                            <div class="col-cost">Cost</div>
                            <div class="col-time">Started</div>
                            <div class="col-actions"></div>
                        </div>
                        <div v-for="run in runs" :key="run.id" class="table-row">
                            <div class="col-status">
                                <span :class="['status-badge', `status-${run.status}`]">
                                    {{ run.status.replace('_', ' ') }}
                                </span>
                            </div>
                            <div class="col-task">{{ run.task }}</div>
                            <div class="col-session">
                                <code>{{ run.session_id.slice(0, 8) }}...</code>
                            </div>
                            <div class="col-duration">{{ formatDuration(run.duration_ms) }}</div>
                            <div class="col-cost">${{ Number(run.cost_usd || 0).toFixed(3) }}</div>
                            <div class="col-time">{{ run.started_at }}</div>
                            <div class="col-actions">
                                <Link :href="`/agents/${agent.slug}/runs/${run.id}`" class="btn btn-ghost btn-sm">
                                    Details
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Config Tab -->
            <div v-if="activeTab === 'config'" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Agent Configuration</h3>
                    </div>
                    <div class="config-form">
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" :value="agent.name" class="form-input" disabled />
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea class="form-input" rows="3" disabled>{{ agent.description }}</textarea>
                        </div>
                        <div class="form-row">
                            <FormSelect
                                v-model="configForm.model"
                                label="Model"
                                :options="modelOptions"
                            />
                            <div class="form-group">
                                <label>Max Budget per Run ($)</label>
                                <input type="number" v-model.number="configForm.max_budget_usd" class="form-input" step="0.01" />
                            </div>
                            <FormSelect
                                v-model="configForm.status"
                                label="Status"
                                :options="agentStatusOptions"
                            />
                        </div>
                        <div class="form-group">
                            <FormCheckbox
                                v-model="configForm.requires_approval"
                                label="Require human approval before executing actions"
                            />
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Allowed Tools</h3>
                    </div>
                    <div class="tools-grid">
                        <FormCheckbox
                            v-for="tool in allTools"
                            :key="tool"
                            :model-value="isToolEnabled(tool)"
                            @update:model-value="toggleTool(tool)"
                            :label="tool"
                        />
                    </div>
                </div>

                <div class="config-actions">
                    <button class="btn btn-primary" :disabled="isSavingConfig" @click="saveConfig">
                        {{ isSavingConfig ? 'Saving...' : 'Save Changes' }}
                    </button>
                </div>

                <div class="card danger-zone">
                    <div class="card-header">
                        <h3 style="color: var(--color-status-red);">Danger Zone</h3>
                    </div>
                    <div class="danger-actions">
                        <div class="danger-item">
                            <div>
                                <div class="danger-title">Reset agent statistics</div>
                                <div class="danger-desc">Clear all run history and reset performance metrics.</div>
                            </div>
                            <button class="btn btn-danger-outline">Reset Stats</button>
                        </div>
                        <div class="danger-item">
                            <div>
                                <div class="danger-title">Delete this agent</div>
                                <div class="danger-desc">Permanently remove this agent and all associated data.</div>
                            </div>
                            <button class="btn btn-danger">Delete Agent</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Run Task Modal -->
        <Modal :show="showRunModal" size="md" @close="showRunModal = false">
            <template #header>
                <h2 class="modal-title">Run {{ agent.name }}</h2>
            </template>

            <div class="run-modal-content">
                <p class="run-modal-description">
                    Provide a specific task or question for this agent. Leave empty to run with default behavior.
                </p>
                <FormTextarea
                    v-model="runTaskInput"
                    label="Task (optional)"
                    placeholder="e.g., Analyze our top 5 clients and identify patterns..."
                    :rows="4"
                />
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showRunModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isRunningManually"
                        @click="runManually()"
                    >
                        {{ isRunningManually ? 'Starting...' : 'Run Agent' }}
                    </button>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.page-container {
    padding: 24px 32px;
}

/* Agent Header */
.agent-header {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 24px;
}

.agent-icon {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}

.agent-info {
    flex: 1;
}

.agent-name-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.agent-name-row h1 {
    margin: 0;
    font-size: 24px;
}

.badge-approval {
    display: flex;
    align-items: center;
    gap: 4px;
    background: rgba(249, 115, 22, 0.15);
    color: var(--color-status-orange);
}

.agent-description {
    color: var(--color-text-secondary);
    margin: 8px 0;
    font-size: 14px;
}

.agent-meta {
    display: flex;
    gap: 16px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--color-text-tertiary);
    font-size: 13px;
}

.meta-item.slug {
    font-family: monospace;
    background: var(--color-bg-tertiary);
    padding: 2px 8px;
    border-radius: 4px;
}

.quick-actions {
    display: flex;
    gap: 8px;
}

/* Run Dropdown */
.run-dropdown {
    position: relative;
}

.run-menu {
    position: absolute;
    top: 100%;
    left: 0;
    margin-top: 4px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    overflow: hidden;
    z-index: 100;
    min-width: 180px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.run-menu-item {
    display: block;
    width: 100%;
    padding: 10px 14px;
    background: none;
    border: none;
    text-align: left;
    color: var(--color-text-primary);
    font-size: 14px;
    cursor: pointer;
    transition: background 0.15s;
    white-space: nowrap;
}

.run-menu-item:hover {
    background: var(--color-bg-tertiary);
}

/* Tabs */
.tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--color-border-default);
    margin-bottom: 24px;
}

.tab {
    padding: 12px 16px;
    background: none;
    border: none;
    color: var(--color-text-secondary);
    font-size: 14px;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
}

.tab:hover { color: var(--color-text-primary); }
.tab.active {
    color: var(--color-text-primary);
    border-bottom-color: var(--color-status-blue);
}

.tab-content {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* Stats */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}

/* Two Column */
.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

/* Performance */
.performance-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    padding: 16px;
}

.perf-item {
    padding: 12px;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
}

.perf-label {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-bottom: 4px;
}

.perf-value {
    font-size: 20px;
    font-weight: 600;
    color: var(--color-text-primary);
}

/* Cost Chart */
.cost-chart {
    padding: 16px;
    height: 160px;
}

.chart-bars {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    height: 100%;
    gap: 8px;
}

.chart-bar-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    height: 100%;
}

.chart-bar {
    width: 100%;
    background: var(--color-status-blue);
    border-radius: 4px 4px 0 0;
    min-height: 4px;
    margin-top: auto;
    position: relative;
    cursor: pointer;
    transition: opacity 0.15s;
}

.chart-bar:hover {
    opacity: 0.8;
}

.chart-tooltip {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-default);
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    color: var(--color-text-primary);
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.15s;
    margin-bottom: 4px;
}

.chart-bar:hover .chart-tooltip {
    opacity: 1;
}

.chart-label {
    font-size: 10px;
    color: var(--color-text-tertiary);
    margin-top: 8px;
}

/* Runs List */
.runs-list {
    display: flex;
    flex-direction: column;
}

.run-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-default);
}

.run-item:last-child { border-bottom: none; }

.status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.run-info { flex: 1; }

.run-task {
    font-weight: 500;
    color: var(--color-text-primary);
    font-size: 14px;
}

.run-meta {
    display: flex;
    gap: 12px;
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 2px;
}

.run-cost {
    font-family: monospace;
    color: var(--color-text-secondary);
}

/* Runs Table */
.runs-table {
    display: flex;
    flex-direction: column;
}

.table-header {
    display: grid;
    grid-template-columns: 120px 2fr 120px 100px 80px 120px 80px;
    gap: 12px;
    padding: 12px 16px;
    background: var(--color-bg-tertiary);
    font-size: 11px;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
}

.table-row {
    display: grid;
    grid-template-columns: 120px 2fr 120px 100px 80px 120px 80px;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-default);
    align-items: center;
}

.table-row:hover { background: var(--color-bg-tertiary); }

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    text-transform: capitalize;
}

.status-running { background: rgba(59, 130, 246, 0.15); color: var(--color-status-blue); }
.status-completed { background: rgba(34, 197, 94, 0.15); color: var(--color-status-green); }
.status-failed { background: rgba(239, 68, 68, 0.15); color: var(--color-status-red); }
.status-pending_approval { background: rgba(249, 115, 22, 0.15); color: var(--color-status-orange); }

.col-session code {
    font-size: 11px;
    background: var(--color-bg-tertiary);
    padding: 2px 6px;
    border-radius: 4px;
}

/* Config Form */
.config-form {
    padding: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 6px;
}

.form-input {
    width: 100%;
    padding: 10px 12px;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    color: var(--color-text-primary);
    font-size: 14px;
}

.form-input:focus {
    outline: none;
    border-color: var(--color-status-blue);
}

.form-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}

.form-group :deep(.checkbox-group) {
    margin-bottom: 0;
}

/* Tools Grid */
.tools-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    padding: 16px;
}

.tools-grid :deep(.checkbox-group) {
    margin-bottom: 0;
}

.tools-grid :deep(.checkbox-label) {
    font-size: 13px;
    text-transform: capitalize;
}

/* Config Actions */
.config-actions {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 24px;
}

/* Danger Zone */
.danger-zone {
    border-color: rgba(239, 68, 68, 0.3);
}

.danger-actions {
    display: flex;
    flex-direction: column;
}

.danger-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    border-bottom: 1px solid var(--color-border-default);
}

.danger-item:last-child { border-bottom: none; }

.danger-title {
    font-weight: 500;
    color: var(--color-text-primary);
}

.danger-desc {
    font-size: 13px;
    color: var(--color-text-tertiary);
    margin-top: 2px;
}

.btn-danger-outline {
    background: transparent;
    border: 1px solid var(--color-status-red);
    color: var(--color-status-red);
}

.btn-danger {
    background: var(--color-status-red);
    border-color: var(--color-status-red);
}

.btn-ghost {
    background: transparent;
    border: 1px solid var(--color-border-default);
    color: var(--color-text-secondary);
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}

/* Run Modal */
.modal-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.run-modal-content {
    padding: 0;
}

.run-modal-description {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-bottom: 1rem;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}
</style>
