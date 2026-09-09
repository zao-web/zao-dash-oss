<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    title: string;
    value?: string | number;
    subtitle?: string;
    trend?: number;
    loading?: boolean;
    compact?: boolean;
}>();

const trendClass = computed(() => {
    if (!props.trend) return '';
    return props.trend > 0 ? 'trend-up' : props.trend < 0 ? 'trend-down' : '';
});

const formattedTrend = computed(() => {
    if (props.trend === undefined || props.trend === null) return '';
    const sign = props.trend > 0 ? '+' : '';
    return `${sign}${props.trend}%`;
});
</script>

<template>
    <div :class="['chart-card', { compact }]">
        <!-- Header -->
        <div class="chart-header">
            <span class="chart-label">{{ title }}</span>
            <span v-if="trend !== undefined && trend !== null" :class="['chart-trend', trendClass]">
                {{ formattedTrend }}
            </span>
        </div>

        <!-- Value -->
        <div v-if="value !== undefined && !compact" class="chart-value-row">
            <span class="chart-value">{{ value }}</span>
            <span v-if="subtitle" class="chart-subtitle">{{ subtitle }}</span>
        </div>

        <!-- Chart Area -->
        <div :class="['chart-area', { 'has-value': value !== undefined && !compact }]">
            <div v-if="loading" class="chart-skeleton">
                <div class="skeleton-shimmer"></div>
            </div>
            <slot v-else />
        </div>
    </div>
</template>

<style scoped>
.chart-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 12px;
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.chart-card:hover {
    border-color: var(--color-border-default);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.chart-card.compact {
    padding: 1rem;
    gap: 0.5rem;
}

/* Header */
.chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.chart-label {
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--color-text-tertiary);
}

.chart-trend {
    font-size: 0.6875rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    padding: 0.125rem 0.375rem;
    border-radius: 4px;
}

.trend-up {
    color: var(--color-status-green);
    background: rgba(34, 197, 94, 0.1);
}

.trend-down {
    color: var(--color-status-red);
    background: rgba(239, 68, 68, 0.1);
}

/* Value */
.chart-value-row {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
}

.chart-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.02em;
    line-height: 1;
}

.chart-subtitle {
    font-size: 0.75rem;
    color: var(--color-text-quaternary);
}

/* Chart Area */
.chart-area {
    flex: 1;
    min-height: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chart-area.has-value {
    min-height: 80px;
    margin-top: 0.25rem;
}

.compact .chart-area {
    min-height: 60px;
}

/* Skeleton Loading */
.chart-skeleton {
    width: 100%;
    height: 80px;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    overflow: hidden;
    position: relative;
}

.skeleton-shimmer {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        90deg,
        transparent 0%,
        rgba(255, 255, 255, 0.04) 50%,
        transparent 100%
    );
    animation: shimmer 1.5s ease-in-out infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

/* Light mode */
:global(.light) .chart-card {
    background: rgba(255, 255, 255, 0.8);
    border-color: rgba(0, 0, 0, 0.08);
}

:global(.light) .chart-card:hover {
    border-color: rgba(0, 0, 0, 0.15);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
}

:global(.light) .skeleton-shimmer {
    background: linear-gradient(
        90deg,
        transparent 0%,
        rgba(0, 0, 0, 0.04) 50%,
        transparent 100%
    );
}
</style>
