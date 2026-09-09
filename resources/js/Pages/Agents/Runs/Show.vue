<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'

interface ApprovalRequest {
    id: number
    action_type: string
    description: string
    risk_level: string
    status: string
    payload: Record<string, unknown>
    decided_at: string | null
    decided_by: string | null
}

interface Run {
    id: number
    session_id: string
    status: 'pending' | 'running' | 'completed' | 'failed' | 'cancelled' | 'pending_approval'
    task: string
    context: Record<string, unknown> | null
    output: Record<string, unknown> | null
    cost_usd: number
    duration_ms: number | null
    input_tokens: number | null
    output_tokens: number | null
    error_message: string | null
    started_at: string | null
    completed_at: string | null
    created_at: string
    approval: ApprovalRequest | null
}

interface Agent {
    id: number
    name: string
    slug: string
    model: string
}

const props = defineProps<{
    run: Run
    agent: Agent
}>()

const activeTab = ref<'overview' | 'context' | 'output' | 'logs'>('overview')

const statusConfig: Record<string, { color: string; bg: string; label: string }> = {
    pending: { color: 'var(--color-text-tertiary)', bg: 'var(--color-bg-tertiary)', label: 'Pending' },
    pending_approval: { color: 'var(--color-status-yellow)', bg: 'rgba(234, 179, 8, 0.15)', label: 'Awaiting Approval' },
    running: { color: 'var(--color-status-blue)', bg: 'rgba(59, 130, 246, 0.15)', label: 'Running' },
    completed: { color: 'var(--color-status-green)', bg: 'rgba(34, 197, 94, 0.15)', label: 'Completed' },
    failed: { color: 'var(--color-status-red)', bg: 'rgba(239, 68, 68, 0.15)', label: 'Failed' },
    cancelled: { color: 'var(--color-status-yellow)', bg: 'rgba(234, 179, 8, 0.15)', label: 'Cancelled' }
}

const riskColors: Record<string, { color: string; bg: string }> = {
    low: { color: 'var(--color-status-green)', bg: 'rgba(34, 197, 94, 0.15)' },
    medium: { color: 'var(--color-status-yellow)', bg: 'rgba(234, 179, 8, 0.15)' },
    high: { color: 'var(--color-status-orange)', bg: 'rgba(249, 115, 22, 0.15)' },
    critical: { color: 'var(--color-status-red)', bg: 'rgba(239, 68, 68, 0.15)' }
}

const formatDuration = (ms: number | null) => {
    if (!ms) return '—'
    if (ms < 1000) return `${ms}ms`
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`
}

const formatCost = (cost: number) => {
    return `$${Number(cost).toFixed(4)}`
}

const formatNumber = (n: number | null) => {
    if (n === null) return '—'
    return n.toLocaleString()
}

const formatJson = (obj: Record<string, unknown> | null) => {
    if (!obj) return 'null'
    return JSON.stringify(obj, null, 2)
}

const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text)
}

const cancelling = ref(false)

const cancelRun = async () => {
    if (!confirm('Are you sure you want to cancel this run?')) return
    
    cancelling.value = true
    try {
        const response = await fetch(`/agents/${props.agent.slug}/runs/${props.run.id}/cancel`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        })
        const data = await response.json()
        if (data.success) {
            router.reload()
        } else {
            alert(data.error || 'Failed to cancel run')
        }
    } catch (error) {
        alert('Failed to cancel run')
    } finally {
        cancelling.value = false
    }
}

/**
 * Extract PR URL from agent output.
 * Handles various output formats from DevAgent.
 */
const prUrl = computed(() => {
    if (!props.run.output) return null

    // Check direct pr_url field
    if (props.run.output.pr_url) {
        return props.run.output.pr_url as string
    }

    // Try to extract from result field
    const result = props.run.output.result
    if (!result) return null

    // If result is a string, try to find GitHub PR URL
    if (typeof result === 'string') {
        // Match GitHub PR URLs
        const prMatch = result.match(/https:\/\/github\.com\/[^\/]+\/[^\/]+\/pull\/\d+/)
        if (prMatch) return prMatch[0]

        // Try parsing as JSON
        try {
            const parsed = JSON.parse(result)
            if (parsed.pr_url) return parsed.pr_url
        } catch {
            // Not JSON, continue
        }
    }

    // If result is an object with pr_url
    if (typeof result === 'object' && result !== null && 'pr_url' in result) {
        return (result as { pr_url: string }).pr_url
    }

    return null
})

/**
 * Extract branch name from output if available.
 */
const branchName = computed(() => {
    if (!props.run.output) return null

    const result = props.run.output.result
    if (!result || typeof result !== 'string') return null

    // Match common branch patterns
    const branchMatch = result.match(/branch[:\s]+['"`]?([a-zA-Z0-9\/_-]+)['"`]?/i)
    if (branchMatch) return branchMatch[1]

    return null
})
</script>

<template>
    <AppLayout>
        <div class="page-container">
            <!-- Header -->
            <div class="page-header">
                <div class="header-left">
                    <div class="breadcrumb">
                        <Link :href="`/agents/${agent.slug}`" class="breadcrumb-link">{{ agent.name }}</Link>
                        <span class="breadcrumb-sep">/</span>
                        <span class="breadcrumb-current">Run #{{ run.id }}</span>
                    </div>
                    <h1>{{ run.task || 'Agent Run' }}</h1>
                    <div class="header-meta">
                        <span
                            class="status-badge"
                            :style="{ color: statusConfig[run.status].color, background: statusConfig[run.status].bg }"
                        >
                            {{ statusConfig[run.status].label }}
                        </span>
                        <span class="meta-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ formatDuration(run.duration_ms) }}
                        </span>
                        <span class="meta-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ formatCost(run.cost_usd) }}
                        </span>
                        <span class="meta-item mono">{{ run.session_id }}</span>
                    </div>
                </div>
                <div class="header-actions">
                    <button 
                        v-if="run.status === 'running' || run.status === 'pending' || run.status === 'pending_approval'"
                        class="btn btn-danger" 
                        @click="cancelRun"
                        :disabled="cancelling"
                    >
                        <svg v-if="cancelling" class="animate-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12a9 9 0 11-6.219-8.56"/>
                        </svg>
                        <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M15 9l-6 6M9 9l6 6"/>
                        </svg>
                        {{ cancelling ? 'Cancelling...' : 'Cancel Run' }}
                    </button>
                    <button class="btn btn-ghost" @click="copyToClipboard(run.session_id)">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2"/>
                            <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                        </svg>
                        Copy ID
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button
                    :class="['tab', { active: activeTab === 'overview' }]"
                    @click="activeTab = 'overview'"
                >Overview</button>
                <button
                    :class="['tab', { active: activeTab === 'context' }]"
                    @click="activeTab = 'context'"
                >Context</button>
                <button
                    :class="['tab', { active: activeTab === 'output' }]"
                    @click="activeTab = 'output'"
                >Output</button>
                <button
                    :class="['tab', { active: activeTab === 'logs' }]"
                    @click="activeTab = 'logs'"
                >Logs</button>
            </div>

            <!-- Overview Tab -->
            <div v-if="activeTab === 'overview'" class="tab-content">
                <div class="grid-2">
                    <!-- Run Details -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Run Details</span>
                        </div>
                        <div class="card-body">
                            <div class="detail-row">
                                <span class="detail-label">Status</span>
                                <span
                                    class="status-badge"
                                    :style="{ color: statusConfig[run.status].color, background: statusConfig[run.status].bg }"
                                >{{ statusConfig[run.status].label }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Agent</span>
                                <Link :href="`/agents/${agent.slug}`" class="detail-link">{{ agent.name }}</Link>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Model</span>
                                <span class="detail-value mono">{{ agent.model }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Session ID</span>
                                <span class="detail-value mono">{{ run.session_id }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Started</span>
                                <span class="detail-value">{{ run.started_at || '—' }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Completed</span>
                                <span class="detail-value">{{ run.completed_at || '—' }}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Duration</span>
                                <span class="detail-value mono">{{ formatDuration(run.duration_ms) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Token Usage -->
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title">Token Usage & Cost</span>
                        </div>
                        <div class="card-body">
                            <div class="metrics-grid">
                                <div class="metric">
                                    <span class="metric-value">{{ formatNumber(run.input_tokens) }}</span>
                                    <span class="metric-label">Input Tokens</span>
                                </div>
                                <div class="metric">
                                    <span class="metric-value">{{ formatNumber(run.output_tokens) }}</span>
                                    <span class="metric-label">Output Tokens</span>
                                </div>
                                <div class="metric">
                                    <span class="metric-value">{{ formatNumber((run.input_tokens || 0) + (run.output_tokens || 0)) }}</span>
                                    <span class="metric-label">Total Tokens</span>
                                </div>
                                <div class="metric">
                                    <span class="metric-value cost">{{ formatCost(run.cost_usd) }}</span>
                                    <span class="metric-label">Total Cost</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Task -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Task</span>
                    </div>
                    <div class="card-body">
                        <p class="task-text">{{ run.task || 'No task description' }}</p>
                    </div>
                </div>

                <!-- Pull Request (if created) -->
                <div v-if="prUrl" class="card card-pr">
                    <div class="card-header">
                        <span class="card-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="18" cy="18" r="3"/>
                                <circle cx="6" cy="6" r="3"/>
                                <path d="M13 6h3a2 2 0 012 2v7"/>
                                <path d="M6 9v12"/>
                            </svg>
                            Pull Request Created
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="pr-info">
                            <div v-if="branchName" class="pr-branch">
                                <span class="pr-label">Branch:</span>
                                <code class="pr-branch-name">{{ branchName }}</code>
                            </div>
                            <a :href="prUrl" target="_blank" rel="noopener noreferrer" class="btn btn-pr">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                                    <polyline points="15 3 21 3 21 9"/>
                                    <line x1="10" y1="14" x2="21" y2="3"/>
                                </svg>
                                View Pull Request
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Error (if failed) -->
                <div v-if="run.status === 'failed' && run.error_message" class="card card-error">
                    <div class="card-header">
                        <span class="card-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 8v4m0 4h.01"/>
                            </svg>
                            Error
                        </span>
                    </div>
                    <div class="card-body">
                        <pre class="error-message">{{ run.error_message }}</pre>
                    </div>
                </div>

                <!-- Approval Request (if exists) -->
                <div v-if="run.approval" class="card">
                    <div class="card-header">
                        <span class="card-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            Approval Request
                        </span>
                        <span
                            class="status-badge"
                            :style="{
                                color: run.approval.status === 'approved' ? 'var(--color-status-green)' :
                                       run.approval.status === 'rejected' ? 'var(--color-status-red)' :
                                       run.approval.status === 'pending' ? 'var(--color-status-yellow)' : 'var(--color-text-tertiary)',
                                background: run.approval.status === 'approved' ? 'rgba(34, 197, 94, 0.15)' :
                                            run.approval.status === 'rejected' ? 'rgba(239, 68, 68, 0.15)' :
                                            run.approval.status === 'pending' ? 'rgba(234, 179, 8, 0.15)' : 'var(--color-bg-tertiary)'
                            }"
                        >{{ run.approval.status }}</span>
                    </div>
                    <div class="card-body">
                        <div class="detail-row">
                            <span class="detail-label">Action Type</span>
                            <span class="detail-value">{{ run.approval.action_type }}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Risk Level</span>
                            <span
                                class="risk-badge"
                                :style="{
                                    color: riskColors[run.approval.risk_level]?.color || 'var(--color-text-tertiary)',
                                    background: riskColors[run.approval.risk_level]?.bg || 'var(--color-bg-tertiary)'
                                }"
                            >{{ run.approval.risk_level }}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Description</span>
                            <span class="detail-value">{{ run.approval.description }}</span>
                        </div>
                        <div v-if="run.approval.decided_at" class="detail-row">
                            <span class="detail-label">Decided</span>
                            <span class="detail-value">{{ run.approval.decided_at }} by {{ run.approval.decided_by || 'Unknown' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Context Tab -->
            <div v-if="activeTab === 'context'" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Input Context</span>
                        <button class="btn btn-ghost btn-sm" @click="copyToClipboard(formatJson(run.context))">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2"/>
                                <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                            </svg>
                            Copy
                        </button>
                    </div>
                    <div class="card-body">
                        <pre class="code-block">{{ formatJson(run.context) }}</pre>
                    </div>
                </div>
            </div>

            <!-- Output Tab -->
            <div v-if="activeTab === 'output'" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Run Output</span>
                        <button class="btn btn-ghost btn-sm" @click="copyToClipboard(formatJson(run.output))">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2"/>
                                <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                            </svg>
                            Copy
                        </button>
                    </div>
                    <div class="card-body">
                        <pre class="code-block">{{ formatJson(run.output) }}</pre>
                    </div>
                </div>
            </div>

            <!-- Logs Tab -->
            <div v-if="activeTab === 'logs'" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Execution Logs</span>
                    </div>
                    <div class="card-body">
                        <div class="logs-container">
                            <div class="log-entry">
                                <span class="log-time">{{ run.started_at || run.created_at }}</span>
                                <span class="log-level info">INFO</span>
                                <span class="log-message">Agent run started</span>
                            </div>
                            <div v-if="run.approval" class="log-entry">
                                <span class="log-time">{{ run.started_at || run.created_at }}</span>
                                <span class="log-level warn">APPROVAL</span>
                                <span class="log-message">Approval requested: {{ run.approval.action_type }}</span>
                            </div>
                            <div v-if="run.approval?.decided_at" class="log-entry">
                                <span class="log-time">{{ run.approval.decided_at }}</span>
                                <span :class="['log-level', run.approval.status === 'approved' ? 'success' : 'error']">
                                    {{ run.approval.status.toUpperCase() }}
                                </span>
                                <span class="log-message">Approval {{ run.approval.status }}</span>
                            </div>
                            <div v-if="run.status === 'failed'" class="log-entry">
                                <span class="log-time">{{ run.completed_at || '—' }}</span>
                                <span class="log-level error">ERROR</span>
                                <span class="log-message">{{ run.error_message || 'Run failed' }}</span>
                            </div>
                            <div v-if="run.status === 'completed'" class="log-entry">
                                <span class="log-time">{{ run.completed_at }}</span>
                                <span class="log-level success">SUCCESS</span>
                                <span class="log-message">Agent run completed successfully</span>
                            </div>
                            <div v-if="run.status === 'cancelled'" class="log-entry">
                                <span class="log-time">{{ run.completed_at || '—' }}</span>
                                <span class="log-level warn">CANCELLED</span>
                                <span class="log-message">Agent run was cancelled</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.page-container {
    padding: 24px 32px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
}

.header-left {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}

.breadcrumb-link {
    color: var(--color-text-tertiary);
    text-decoration: none;
}

.breadcrumb-link:hover {
    color: var(--color-status-blue);
}

.breadcrumb-sep {
    color: var(--color-text-quaternary);
}

.breadcrumb-current {
    color: var(--color-text-secondary);
}

.page-header h1 {
    font-size: 20px;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}

.header-meta {
    display: flex;
    align-items: center;
    gap: 16px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--color-text-secondary);
}

.meta-item.mono {
    font-family: var(--font-mono);
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.status-badge {
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    text-transform: capitalize;
}

.risk-badge {
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    text-transform: capitalize;
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
    color: var(--color-text-tertiary);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
}

.tab:hover {
    color: var(--color-text-secondary);
}

.tab.active {
    color: var(--color-text-primary);
    border-bottom-color: var(--color-status-blue);
}

/* Grid */
.grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

/* Cards */
.card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    margin-bottom: 20px;
}

.card:last-child {
    margin-bottom: 0;
}

.card-error {
    border-color: rgba(239, 68, 68, 0.3);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    border-bottom: 1px solid var(--color-border-default);
}

.card-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--color-text-primary);
}

.card-error .card-title {
    color: var(--color-status-red);
}

.card-body {
    padding: 16px;
}

/* Detail Rows */
.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--color-border-subtle);
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    font-size: 13px;
    color: var(--color-text-tertiary);
}

.detail-value {
    font-size: 13px;
    color: var(--color-text-primary);
}

.detail-value.mono {
    font-family: var(--font-mono);
    font-size: 12px;
}

.detail-link {
    font-size: 13px;
    color: var(--color-status-blue);
    text-decoration: none;
}

.detail-link:hover {
    text-decoration: underline;
}

/* Metrics Grid */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.metric {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.metric-value {
    font-size: 24px;
    font-weight: 600;
    color: var(--color-text-primary);
    font-family: var(--font-mono);
}

.metric-value.cost {
    color: var(--color-status-green);
}

.metric-label {
    font-size: 12px;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
}

/* Task */
.task-text {
    font-size: 14px;
    color: var(--color-text-secondary);
    line-height: 1.6;
    margin: 0;
}

/* Error */
.error-message {
    font-family: var(--font-mono);
    font-size: 13px;
    color: var(--color-status-red);
    background: rgba(239, 68, 68, 0.1);
    padding: 16px;
    border-radius: 6px;
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}

/* Code Block */
.code-block {
    font-family: var(--font-mono);
    font-size: 12px;
    line-height: 1.6;
    color: var(--color-text-secondary);
    background: var(--color-bg-tertiary);
    padding: 16px;
    border-radius: 6px;
    margin: 0;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
}

/* Logs */
.logs-container {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.log-entry {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 8px 12px;
    background: var(--color-bg-tertiary);
    border-radius: 4px;
    font-family: var(--font-mono);
    font-size: 12px;
}

.log-time {
    color: var(--color-text-tertiary);
    white-space: nowrap;
}

.log-level {
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    white-space: nowrap;
}

.log-level.info {
    background: rgba(59, 130, 246, 0.15);
    color: var(--color-status-blue);
}

.log-level.success {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

.log-level.warn {
    background: rgba(234, 179, 8, 0.15);
    color: var(--color-status-yellow);
}

.log-level.error {
    background: rgba(239, 68, 68, 0.15);
    color: var(--color-status-red);
}

.log-message {
    color: var(--color-text-secondary);
    flex: 1;
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.15s ease;
}

.btn-ghost {
    background: transparent;
    color: var(--color-text-secondary);
    border: 1px solid var(--color-border-default);
}

.btn-ghost:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}

.btn-danger {
    background: var(--color-status-red);
    color: white;
    border: none;
}

.btn-danger:hover:not(:disabled) {
    background: #dc2626;
}

.btn-danger:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.animate-spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Pull Request Card */
.card-pr {
    border-color: rgba(139, 92, 246, 0.3);
    background: linear-gradient(135deg, var(--color-bg-secondary) 0%, rgba(139, 92, 246, 0.05) 100%);
}

.card-pr .card-title {
    color: #a78bfa;
}

.pr-info {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.pr-branch {
    display: flex;
    align-items: center;
    gap: 8px;
}

.pr-label {
    font-size: 13px;
    color: var(--color-text-tertiary);
}

.pr-branch-name {
    font-family: var(--font-mono);
    font-size: 12px;
    padding: 4px 8px;
    background: var(--color-bg-tertiary);
    border-radius: 4px;
    color: var(--color-text-secondary);
}

.btn-pr {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s ease;
    width: fit-content;
}

.btn-pr:hover {
    background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.btn-pr svg {
    stroke: white;
}
</style>
