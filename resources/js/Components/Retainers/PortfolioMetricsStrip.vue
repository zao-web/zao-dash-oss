<script setup lang="ts">
interface Metrics {
    total_mrr: number;
    avg_margin_percent: number | null;
    active_count: number;
    critical_count: number;
    warning_count: number;
    total_human_hours: number;
    total_agent_cost: number;
    total_agent_tasks: number;
}

defineProps<{
    metrics: Metrics;
}>();

const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(val);
};

const formatPercent = (val: number | null) => {
    if (val === null) return '--';
    return `${Math.round(val)}%`;
};
</script>

<template>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 lg:grid-cols-4">
        <!-- Monthly MRR -->
        <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-[var(--color-text-tertiary)]">Monthly MRR</p>
            <p class="mt-1 font-[var(--font-display)] text-2xl font-bold text-[var(--color-text-primary)]">
                {{ formatCurrency(metrics.total_mrr) }}
            </p>
            <p class="mt-1 text-xs text-[var(--color-text-tertiary)]">
                {{ metrics.active_count }} active retainer{{ metrics.active_count !== 1 ? 's' : '' }}
            </p>
        </div>

        <!-- Portfolio Margin -->
        <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-[var(--color-text-tertiary)]">Portfolio Margin</p>
            <p class="mt-1 font-[var(--font-display)] text-2xl font-bold"
               :class="{
                   'text-green-600 dark:text-green-400': (metrics.avg_margin_percent ?? 0) >= 30,
                   'text-amber-600 dark:text-amber-400': (metrics.avg_margin_percent ?? 0) >= 10 && (metrics.avg_margin_percent ?? 0) < 30,
                   'text-red-600 dark:text-red-400': (metrics.avg_margin_percent ?? 0) < 10,
               }">
                {{ formatPercent(metrics.avg_margin_percent) }}
            </p>
            <p v-if="metrics.critical_count > 0" class="mt-1 text-xs text-red-500">
                {{ metrics.critical_count }} need{{ metrics.critical_count !== 1 ? '' : 's' }} attention
            </p>
            <p v-else-if="metrics.warning_count > 0" class="mt-1 text-xs text-amber-500">
                {{ metrics.warning_count }} warning{{ metrics.warning_count !== 1 ? 's' : '' }}
            </p>
            <p v-else class="mt-1 text-xs text-green-500">All healthy</p>
        </div>

        <!-- Human Hours -->
        <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-[var(--color-text-tertiary)]">Human Hours</p>
            <p class="mt-1 font-[var(--font-display)] text-2xl font-bold text-[var(--color-text-primary)]">
                {{ Math.round(metrics.total_human_hours) }}h
            </p>
            <p class="mt-1 text-xs text-[var(--color-text-tertiary)]">across all clients this month</p>
        </div>

        <!-- Agent Efficiency -->
        <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-[var(--color-text-tertiary)]">Agent Efficiency</p>
            <p class="mt-1 font-[var(--font-display)] text-2xl font-bold text-[var(--color-accent)]">
                {{ metrics.total_agent_tasks }} tasks
            </p>
            <p class="mt-1 text-xs text-[var(--color-text-tertiary)]">
                {{ formatCurrency(metrics.total_agent_cost) }} AI cost
            </p>
        </div>
    </div>
</template>
