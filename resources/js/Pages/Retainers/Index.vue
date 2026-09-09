<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PortfolioMetricsStrip from '@/Components/Retainers/PortfolioMetricsStrip.vue';
import RetainerCard from '@/Components/Retainers/RetainerCard.vue';
import { ref, computed } from 'vue';

interface Client {
    id: number;
    name: string;
    slug: string;
    initials: string;
}

interface Retainer {
    id: number;
    client_id: number;
    client: Client | null;
    monthly_amount: number;
    hours_included: number;
    hours_used: number;
    total_hours: number;
    remaining_hours: number;
    usage_percent: number;
    is_over_budget: boolean;
    overage_hours: number;
    agent_cost_usd: number;
    agent_tasks_completed: number;
    agent_equivalent_hours: number;
    effective_margin_percent: number | null;
    health_status: string;
    tier: string | null;
    last_client_activity_at: string | null;
    internal_hourly_rate: number;
}

interface PortfolioMetrics {
    total_mrr: number;
    avg_margin_percent: number | null;
    active_count: number;
    critical_count: number;
    warning_count: number;
    total_human_hours: number;
    total_agent_cost: number;
    total_agent_tasks: number;
}

const props = defineProps<{
    retainers: { data: Retainer[] };
    portfolioMetrics: PortfolioMetrics;
    currentMonth: string;
}>();

type FilterType = 'all' | 'attention' | 'healthy' | 'over_budget' | 'silent';
const activeFilter = ref<FilterType>('all');

const filters: { key: FilterType; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'attention', label: 'Needs attention' },
    { key: 'healthy', label: 'Healthy' },
    { key: 'over_budget', label: 'Over budget' },
    { key: 'silent', label: 'Silent 30+ days' },
];

const filteredRetainers = computed(() => {
    const list = props.retainers.data;
    switch (activeFilter.value) {
        case 'attention':
            return list.filter(r => r.health_status === 'critical' || r.health_status === 'warning');
        case 'healthy':
            return list.filter(r => r.health_status === 'healthy');
        case 'over_budget':
            return list.filter(r => r.is_over_budget);
        case 'silent':
            return list.filter(r => r.health_status === 'silent');
        default:
            return list;
    }
});

const filterCount = (key: FilterType) => {
    const list = props.retainers.data;
    switch (key) {
        case 'attention':
            return list.filter(r => r.health_status === 'critical' || r.health_status === 'warning').length;
        case 'healthy':
            return list.filter(r => r.health_status === 'healthy').length;
        case 'over_budget':
            return list.filter(r => r.is_over_budget).length;
        case 'silent':
            return list.filter(r => r.health_status === 'silent').length;
        default:
            return list.length;
    }
};
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h1 class="font-[var(--font-display)] text-2xl font-bold text-[var(--color-text-primary)]">Retainers</h1>
                    <p class="mt-1 text-sm text-[var(--color-text-tertiary)]">{{ currentMonth }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="rounded-full bg-[var(--color-bg-tertiary)] px-3 py-1 text-sm font-medium text-[var(--color-text-secondary)]">
                        {{ portfolioMetrics.active_count }} active
                    </span>
                </div>
            </div>

            <!-- Portfolio Metrics -->
            <PortfolioMetricsStrip :metrics="portfolioMetrics" />

            <!-- Filter Bar -->
            <div class="mt-8 overflow-x-auto border-b border-[var(--color-border-default)] pb-4 -mx-4 px-4 sm:mx-0 sm:px-0" style="scrollbar-width: none; -webkit-overflow-scrolling: touch;">
                <div class="flex items-center gap-2 whitespace-nowrap">
                    <button
                        v-for="filter in filters"
                        :key="filter.key"
                        @click="activeFilter = filter.key"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors shrink-0"
                        :class="activeFilter === filter.key
                            ? 'bg-[var(--color-accent)] text-[#100f0d]'
                            : 'text-[var(--color-text-tertiary)] hover:bg-[var(--color-bg-tertiary)]'"
                    >
                        {{ filter.label }}
                        <span class="ml-1 font-[var(--font-mono)] text-xs opacity-60">{{ filterCount(filter.key) }}</span>
                    </button>
                </div>
            </div>

            <!-- Retainer Cards -->
            <div class="mt-6 space-y-4">
                <RetainerCard
                    v-for="retainer in filteredRetainers"
                    :key="retainer.id"
                    :retainer="retainer"
                />

                <!-- Empty state -->
                <div v-if="filteredRetainers.length === 0"
                     class="rounded-xl border border-dashed border-[var(--color-border-default)] py-16 text-center">
                    <p class="text-sm text-[var(--color-text-tertiary)]">No retainers match this filter.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
