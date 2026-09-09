<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface ActionItem {
    type: 'approval' | 'task' | 'alert' | 'recommendation';
    title: string;
    description?: string;
    agent_slug?: string;
    metadata?: Record<string, unknown>;
}

interface AgentCheckin {
    id: number;
    agent_name: string;
    agent_slug: string;
    mode: 'planning' | 'checkin' | 'analysis';
    status: string;
    created_at: string;
    summary?: string;
    action_items: ActionItem[];
    alerts: string[];
    recommendations: string[];
    weekly_plan_id?: number;
}

const props = defineProps<{
    checkins: AgentCheckin[];
}>();

const expandedCheckin = ref<number | null>(null);

const toggleExpand = (id: number) => {
    expandedCheckin.value = expandedCheckin.value === id ? null : id;
};

const getModeIcon = (mode: string) => {
    switch (mode) {
        case 'planning':
            return 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4';
        case 'checkin':
            return 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z';
        default:
            return 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    }
};

const getModeBadge = (mode: string) => {
    switch (mode) {
        case 'planning':
            return 'badge-purple';
        case 'checkin':
            return 'badge-blue';
        default:
            return 'badge-gray';
    }
};

const hasActionItems = (checkin: AgentCheckin) => {
    return checkin.action_items.length > 0 || 
           checkin.alerts.length > 0 || 
           checkin.recommendations.length > 0;
};

const totalActionItems = computed(() => {
    return props.checkins.reduce((sum, c) => 
        sum + c.action_items.length + c.alerts.length, 0
    );
});

const triggerAgent = (agentSlug: string, task?: string) => {
    router.post(`/agents/${agentSlug}/run`, {
        task: task || 'Manual trigger from checkin',
        source: 'checkin_widget',
    });
};

const viewPlan = (planId: number) => {
    router.visit(`/weekly-plans/${planId}`);
};
</script>

<template>
    <div class="card">
        <div class="card-header">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4" style="color: var(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                <span class="card-title">Agent Checkins</span>
            </div>
            <span v-if="totalActionItems > 0" class="count-badge count-badge-warning">
                {{ totalActionItems }} action{{ totalActionItems !== 1 ? 's' : '' }}
            </span>
        </div>

        <div class="card-body">
            <div v-if="checkins.length === 0" class="p-6 text-center">
                <div class="avatar avatar-md avatar-muted mx-auto mb-3">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>
                <p class="text-body">No recent checkins</p>
                <p class="text-caption mt-1">Business Strategist runs Mon-Fri at 6am</p>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="checkin in checkins"
                    :key="checkin.id"
                    class="checkin-item"
                    :class="{ 'has-actions': hasActionItems(checkin) }"
                >
                    <!-- Header -->
                    <div 
                        class="checkin-header"
                        @click="toggleExpand(checkin.id)"
                    >
                        <div class="flex items-center gap-3">
                            <div class="checkin-icon">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="getModeIcon(checkin.mode)" />
                                </svg>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="checkin-title">{{ checkin.agent_name }}</span>
                                    <span :class="['badge badge-sm', getModeBadge(checkin.mode)]">
                                        {{ checkin.mode }}
                                    </span>
                                </div>
                                <div class="checkin-meta">{{ checkin.created_at }}</div>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <span v-if="checkin.action_items.length > 0" class="action-count">
                                {{ checkin.action_items.length }}
                            </span>
                            <svg 
                                class="expand-icon" 
                                :class="{ expanded: expandedCheckin === checkin.id }"
                                fill="none" 
                                stroke="currentColor" 
                                viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>
                    </div>

                    <!-- Summary (always visible if exists) -->
                    <p v-if="checkin.summary" class="checkin-summary">
                        {{ checkin.summary }}
                    </p>

                    <!-- Expanded Content -->
                    <div v-if="expandedCheckin === checkin.id" class="checkin-details">
                        <!-- Empty state when no details -->
                        <div v-if="!hasActionItems(checkin) && !checkin.weekly_plan_id" class="empty-details">
                            <p class="text-caption text-center">No additional details available for this checkin.</p>
                        </div>

                        <!-- Alerts -->
                        <div v-if="checkin.alerts.length > 0" class="detail-section">
                            <h4 class="detail-title text-status-yellow">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                Alerts
                            </h4>
                            <ul class="alert-list">
                                <li v-for="(alert, idx) in checkin.alerts" :key="idx">
                                    {{ alert }}
                                </li>
                            </ul>
                        </div>

                        <!-- Action Items -->
                        <div v-if="checkin.action_items.length > 0" class="detail-section">
                            <h4 class="detail-title">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                </svg>
                                Human Actions Required
                            </h4>
                            <div class="action-items">
                                <div 
                                    v-for="(item, idx) in checkin.action_items" 
                                    :key="idx"
                                    class="action-item"
                                >
                                    <div class="action-item-content">
                                        <span class="action-item-title">{{ item.title }}</span>
                                        <p v-if="item.description" class="action-item-desc">
                                            {{ item.description }}
                                        </p>
                                    </div>
                                    <button
                                        v-if="item.agent_slug"
                                        class="btn btn-xs btn-primary"
                                        @click.stop="triggerAgent(item.agent_slug, item.title)"
                                    >
                                        Run Agent
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Recommendations -->
                        <div v-if="checkin.recommendations.length > 0" class="detail-section">
                            <h4 class="detail-title text-status-blue">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                </svg>
                                Recommendations
                            </h4>
                            <ul class="recommendation-list">
                                <li v-for="(rec, idx) in checkin.recommendations" :key="idx">
                                    {{ rec }}
                                </li>
                            </ul>
                        </div>

                        <!-- View Plan Link -->
                        <div v-if="checkin.weekly_plan_id" class="mt-3">
                            <button 
                                class="btn btn-sm btn-secondary w-full"
                                @click.stop="viewPlan(checkin.weekly_plan_id)"
                            >
                                View Weekly Plan
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- View All Link -->
            <div v-if="checkins.length > 0" class="mt-4 pt-4 border-t border-subtle">
                <Link 
                    href="/agents/business-strategist" 
                    class="text-link text-sm flex items-center justify-center gap-1 group"
                >
                    View all checkins
                    <svg class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7" />
                    </svg>
                </Link>
            </div>
        </div>
    </div>
</template>

<style scoped>
.checkin-item {
    padding: 0.75rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    border: 1px solid var(--color-border-subtle);
    transition: all 0.15s ease;
}

.checkin-item.has-actions {
    border-left: 3px solid var(--color-status-yellow);
}

.checkin-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
}

.checkin-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-secondary);
    border-radius: 6px;
    color: var(--color-accent);
}

.checkin-title {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.checkin-meta {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 2px;
}

.checkin-summary {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    margin-top: 0.75rem;
    padding-left: 44px;
    line-height: 1.5;
}

.action-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    font-size: 0.6875rem;
    font-weight: 600;
    background: var(--color-status-yellow);
    color: #000;
    border-radius: 10px;
}

.expand-icon {
    width: 16px;
    height: 16px;
    color: var(--color-text-tertiary);
    transition: transform 0.15s ease;
}

.expand-icon.expanded {
    transform: rotate(180deg);
}

.checkin-details {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--color-border-subtle);
}

.detail-section {
    margin-bottom: 1rem;
}

.detail-section:last-child {
    margin-bottom: 0;
}

.detail-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin-bottom: 0.5rem;
}

.detail-title.text-status-yellow {
    color: var(--color-status-yellow);
}

.detail-title.text-status-blue {
    color: var(--color-status-blue);
}

.alert-list,
.recommendation-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.alert-list li,
.recommendation-list li {
    position: relative;
    padding-left: 1rem;
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
    margin-bottom: 0.375rem;
}

.alert-list li::before,
.recommendation-list li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.5rem;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: currentColor;
}

.alert-list li {
    color: var(--color-status-yellow);
}

.action-items {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.action-item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.625rem;
    background: var(--color-bg-secondary);
    border-radius: 6px;
}

.action-item-content {
    flex: 1;
    min-width: 0;
}

.action-item-title {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.action-item-desc {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
    line-height: 1.4;
}

.count-badge-warning {
    background: rgba(234, 179, 8, 0.15);
    color: var(--color-status-yellow);
}

.border-subtle {
    border-color: var(--color-border-subtle);
}

.text-link {
    color: var(--color-accent);
    text-decoration: none;
}

.text-link:hover {
    text-decoration: underline;
}

.badge-sm {
    font-size: 0.625rem;
    padding: 0.125rem 0.375rem;
}

.badge-purple {
    background: rgba(139, 92, 246, 0.15);
    color: #a78bfa;
}

.btn-xs {
    font-size: 0.6875rem;
    padding: 0.25rem 0.5rem;
}

.empty-details {
    padding: 1rem;
    background: var(--color-bg-secondary);
    border-radius: 6px;
}

.text-caption {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}
</style>
