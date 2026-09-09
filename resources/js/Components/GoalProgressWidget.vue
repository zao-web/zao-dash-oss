<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

interface Goal {
    id: number;
    name: string;
    fiscal_year: number;
    revenue_target: number;
    revenue_actual: number;
    progress_percent: number;
    is_on_track: boolean;
    time_elapsed_percent: number;
}

defineProps<{
    goal: Goal | null;
}>();

const formatCurrency = (value: number) => {
    if (value >= 1000000) return `$${(value / 1000000).toFixed(1)}M`;
    if (value >= 1000) return `$${(value / 1000).toFixed(0)}k`;
    return `$${value.toLocaleString()}`;
};
</script>

<template>
    <div class="goal-widget">
        <div class="goal-header">
            <h3 class="goal-title">Revenue Goal</h3>
            <Link href="/goals" class="goal-link">View →</Link>
        </div>

        <div v-if="goal" class="goal-body">
            <div class="goal-top">
                <div>
                    <div class="goal-name">{{ goal.name }}</div>
                    <div class="goal-year">FY{{ goal.fiscal_year }}</div>
                </div>
                <span :class="['goal-status', goal.is_on_track ? 'on-track' : 'behind']">
                    {{ goal.is_on_track ? 'On Track' : 'Behind' }}
                </span>
            </div>

            <div class="goal-stats">
                <div>
                    <div class="stat-label">Actual</div>
                    <div class="stat-value">{{ formatCurrency(goal.revenue_actual) }}</div>
                </div>
                <div>
                    <div class="stat-label">Target</div>
                    <div class="stat-value muted">{{ formatCurrency(goal.revenue_target) }}</div>
                </div>
            </div>

            <div class="goal-progress">
                <div class="progress-header">
                    <span class="progress-label">Progress</span>
                    <span class="progress-value">{{ Number(goal.progress_percent ?? 0).toFixed(1) }}%</span>
                </div>
                <div class="progress-bar">
                    <div
                        :class="['progress-fill', goal.is_on_track ? 'on-track' : 'behind']"
                        :style="{ width: `${Math.min(100, goal.progress_percent)}%` }"
                    ></div>
                </div>
                <div class="progress-footer">
                    <span>{{ Number(goal.time_elapsed_percent ?? 0).toFixed(0) }}% of year elapsed</span>
                </div>
            </div>
        </div>

        <div v-else class="goal-empty">
            <div class="empty-icon">🎯</div>
            <p class="empty-text">No active goal</p>
            <Link href="/goals/create" class="goal-link">Create Goal</Link>
        </div>
    </div>
</template>

<style scoped>
.goal-widget {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 10px;
}

@media (min-width: 768px) {
    .goal-widget {
        border-radius: 12px;
    }
}

.goal-header {
    border-bottom: 1px solid var(--color-border-subtle);
    padding: 0.625rem 0.875rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

@media (min-width: 768px) {
    .goal-header {
        padding: 0.75rem 1rem;
    }
}

.goal-title {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
}

.goal-link {
    font-size: 0.75rem;
    color: var(--color-accent);
}

.goal-link:hover {
    opacity: 0.8;
}

.goal-body {
    padding: 0.875rem;
}

@media (min-width: 768px) {
    .goal-body {
        padding: 1rem;
    }
}

.goal-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
}

.goal-name {
    color: var(--color-text-primary);
    font-weight: 500;
}

.goal-year {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.goal-status {
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
}

.goal-status.on-track {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-status-green);
}

.goal-status.behind {
    background: rgba(245, 158, 11, 0.15);
    color: var(--color-status-yellow);
}

.goal-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 0.75rem;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
}

.stat-value {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.stat-value.muted {
    color: var(--color-text-secondary);
}

.goal-progress {
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

.progress-fill.on-track {
    background: var(--color-status-green);
}

.progress-fill.behind {
    background: var(--color-status-yellow);
}

.progress-footer {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.goal-empty {
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
    margin-bottom: 0.75rem;
}
</style>
