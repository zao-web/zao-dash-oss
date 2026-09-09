<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

interface Plan {
    id: number;
    week_label: string;
    status: string;
    focus_areas: string[];
    progress_percent: number;
    items_total: number;
    items_completed: number;
}

defineProps<{
    plan: Plan | null;
}>();

const focusLabels: Record<string, string> = {
    lead_gen: '🎯 Leads',
    closing: '🤝 Closing',
    upsells: '📈 Upsells',
    retention: '💚 Retention',
    operations: '⚙️ Ops',
    content: '✍️ Content',
};

const getFocusLabel = (area: string) => focusLabels[area] || area;
</script>

<template>
    <div class="plan-widget">
        <div class="plan-header">
            <h3 class="plan-title">This Week's Plan</h3>
            <Link href="/weekly-plans" class="plan-link">View →</Link>
        </div>

        <div v-if="plan" class="plan-body">
            <div class="plan-top">
                <div class="plan-week">{{ plan.week_label }}</div>
                <span :class="['plan-status', `status-${plan.status}`]">
                    {{ plan.status === 'draft' ? 'Draft' : plan.status === 'approved' ? 'Approved' : plan.status === 'active' ? 'Active' : 'Done' }}
                </span>
            </div>

            <!-- Focus Areas -->
            <div v-if="plan.focus_areas.length" class="plan-focus">
                <span
                    v-for="area in plan.focus_areas.slice(0, 3)"
                    :key="area"
                    class="focus-tag"
                >
                    {{ getFocusLabel(area) }}
                </span>
            </div>

            <!-- Progress -->
            <div class="plan-progress">
                <div class="progress-header">
                    <span class="progress-label">Tasks</span>
                    <span class="progress-value">{{ plan.items_completed }}/{{ plan.items_total }}</span>
                </div>
                <div class="progress-bar">
                    <div
                        :class="['progress-fill', plan.progress_percent >= 100 ? 'complete' : 'in-progress']"
                        :style="{ width: `${plan.progress_percent}%` }"
                    ></div>
                </div>
            </div>

            <div class="plan-footer">
                <Link :href="`/weekly-plans/${plan.id}`" class="plan-details-link">
                    View Plan Details →
                </Link>
            </div>
        </div>

        <div v-else class="plan-empty">
            <div class="empty-icon">📋</div>
            <p class="empty-text">No plan for this week</p>
            <p class="empty-subtext">Business Strategist runs Monday 6am</p>
        </div>
    </div>
</template>

<style scoped>
.plan-widget {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 10px;
}

@media (min-width: 768px) {
    .plan-widget {
        border-radius: 12px;
    }
}

.plan-header {
    border-bottom: 1px solid var(--color-border-subtle);
    padding: 0.625rem 0.875rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

@media (min-width: 768px) {
    .plan-header {
        padding: 0.75rem 1rem;
    }
}

.plan-title {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
}

.plan-link {
    font-size: 0.75rem;
    color: var(--color-accent);
}

.plan-link:hover {
    opacity: 0.8;
}

.plan-body {
    padding: 0.875rem;
}

@media (min-width: 768px) {
    .plan-body {
        padding: 1rem;
    }
}

.plan-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
}

.plan-week {
    color: var(--color-text-primary);
    font-weight: 500;
}

.plan-status {
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
}

.plan-status.status-draft {
    background: rgba(148, 163, 184, 0.15);
    color: var(--color-text-secondary);
}

.plan-status.status-approved {
    background: rgba(99, 102, 241, 0.15);
    color: var(--color-accent);
}

.plan-status.status-active {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-status-green);
}

.plan-status.status-completed {
    background: rgba(139, 92, 246, 0.15);
    color: #a78bfa;
}

.plan-focus {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    margin-bottom: 0.75rem;
}

.focus-tag {
    padding: 0.125rem 0.5rem;
    background: var(--color-bg-tertiary);
    border-radius: 4px;
    font-size: 0.75rem;
    color: var(--color-text-secondary);
}

.plan-progress {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.progress-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.75rem;
}

.progress-label {
    color: var(--color-text-tertiary);
}

.progress-value {
    color: var(--color-text-primary);
}

.progress-bar {
    height: 0.5rem;
    background: var(--color-bg-tertiary);
    border-radius: 9999px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: 9999px;
    transition: width 0.5s ease;
}

.progress-fill.in-progress {
    background: var(--color-accent);
}

.progress-fill.complete {
    background: var(--color-status-green);
}

.plan-footer {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--color-border-subtle);
}

.plan-details-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
    text-align: center;
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    transition: color 0.15s ease;
}

.plan-details-link:hover {
    color: var(--color-text-primary);
}

.plan-empty {
    padding: 1.5rem;
    text-align: center;
}

.empty-icon {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.empty-text {
    color: var(--color-text-tertiary);
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
}

.empty-subtext {
    color: var(--color-text-tertiary);
    font-size: 0.75rem;
    opacity: 0.7;
}
</style>
