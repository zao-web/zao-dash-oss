<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref, computed } from 'vue';

interface Period {
    id: number;
    type: string;
    label: string;
    start: string;
    end: string;
    is_current: boolean;
    elapsed_percent: number;
    status: string;
    revenue: { target: number; actual: number; progress: number };
    leads: { target: number; actual: number; progress: number };
    deals: { target: number; actual: number; progress: number };
    pipeline: { target: number; actual: number; progress: number };
    variance_pct: number;
}

interface Lever {
    lever: string;
    title: string;
    description: string;
    current: string;
    target: string;
    impact_score: number;
    actions: string[];
}

const props = defineProps<{
    goal: {
        id: number;
        fiscal_year: number;
        name: string;
        revenue_target: number;
        margin_target_pct: number;
        profit_target: number;
        status: string;
        assumptions: Record<string, number>;
        notes: string | null;
    };
    progress: {
        revenue: { target: number; actual: number; from_deals: number; progress_pct: number; variance_pct: number };
        pace: { time_elapsed_pct: number; expected_revenue: number; pace_variance_pct: number; is_on_track: boolean; status: string };
        leads: { target: number; actual: number; progress_pct: number };
        deals: { target: number; actual: number; progress_pct: number };
        pipeline: { target: number; actual: number; coverage: number };
    };
    requirements: {
        revenue_target: number;
        revenue_actual: number;
        revenue_remaining: number;
        weeks_remaining: number;
        deals_needed: number;
        leads_needed: number;
        leads_per_week: number;
        leads_for_conversion_adjusted: number;
        assumptions: { win_rate: number; avg_deal_size: number; avg_cycle_days: number; overall_conversion: number };
    };
    levers: {
        status: string;
        message: string;
        revenue_gap?: number;
        weeks_remaining?: number;
        levers: Lever[];
    };
    forecast: {
        current_revenue: number;
        target_revenue: number;
        weighted_pipeline: number;
        daily_velocity: number;
        forecasts: Record<number, { velocity_based: number; pipeline_based: number; blended: number }>;
        year_end: { days_remaining: number; velocity_forecast: number; pipeline_forecast: number; will_hit_target: boolean; gap: number };
    };
    capacity: {
        lead_generation: { required_per_week: number; max_capacity: number; capacity_used_pct: number; is_overloaded: boolean };
        pipeline_management: { active_leads: number; max_capacity: number; capacity_used_pct: number; is_overloaded: boolean };
        recommendation: string;
    };
    periods: {
        yearly: Period | null;
        current_quarter: Period | null;
        current_month: Period | null;
        current_week: Period | null;
        quarters: Period[];
        months: Period[];
    };
}>();

const activeTab = ref<'overview' | 'periods' | 'levers' | 'forecast'>('overview');

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
};

const formatCompact = (value: number) => {
    if (value >= 1000000) return `$${(value / 1000000).toFixed(1)}M`;
    if (value >= 1000) return `$${(value / 1000).toFixed(0)}k`;
    return formatCurrency(value);
};

const getStatusBadge = (status: string) => {
    const badges: Record<string, { class: string; text: string }> = {
        on_track: { class: 'bg-emerald-500/20 text-emerald-400', text: 'On Track' },
        ahead: { class: 'bg-blue-500/20 text-blue-400', text: 'Ahead' },
        behind: { class: 'bg-amber-500/20 text-amber-400', text: 'Behind' },
        critical: { class: 'bg-red-500/20 text-red-400', text: 'Critical' },
        pending: { class: 'bg-zinc-500/20 text-zinc-400', text: 'Pending' },
        completed: { class: 'bg-emerald-500/20 text-emerald-400', text: 'Completed' },
    };
    return badges[status] || badges.pending;
};

const getProgressColor = (progress: number) => {
    if (progress >= 100) return 'bg-emerald-500';
    if (progress >= 75) return 'bg-blue-500';
    if (progress >= 50) return 'bg-amber-500';
    return 'bg-red-500';
};

const paceStatus = computed(() => props.progress.pace.status);
const isOnTrack = computed(() => props.progress.pace.is_on_track);
</script>

<template>
    <AppLayout>
        <Head :title="goal.name" />

        <div class="p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <Link href="/goals" class="text-[var(--color-text-tertiary)] hover:text-[var(--color-text-secondary)]">
                            ← Goals
                        </Link>
                    </div>
                    <h1 class="text-2xl font-semibold text-[var(--color-text-primary)]">{{ goal.name }}</h1>
                    <p class="text-[var(--color-text-tertiary)] text-sm mt-1">FY{{ goal.fiscal_year }} • Target: {{ formatCurrency(goal.revenue_target) }}</p>
                </div>
                <span :class="[getStatusBadge(paceStatus).class, 'px-3 py-1 rounded-full text-sm font-medium']">
                    {{ getStatusBadge(paceStatus).text }}
                </span>
            </div>

            <!-- Hero Progress -->
            <div class="hero-gradient border border-[var(--color-border-subtle)] rounded-xl p-8 shadow-sm relative overflow-hidden">
                <!-- Decorative background accent -->
                <div class="absolute top-0 right-0 w-64 h-64 bg-blue-400/5 rounded-full blur-3xl -mr-32 -mt-32 pointer-events-none"></div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8 relative">
                    <!-- Revenue Actual -->
                    <div class="flex flex-col">
                        <div class="text-xs font-medium text-[var(--color-text-tertiary)] uppercase tracking-wider mb-2">Revenue Actual</div>
                        <div class="text-3xl font-bold text-[var(--color-text-primary)] tracking-tight">{{ formatCompact(progress.revenue.actual) }}</div>
                        <div class="text-sm text-[var(--color-text-tertiary)] mt-1">
                            of <span class="font-medium text-[var(--color-text-secondary)]">{{ formatCompact(progress.revenue.target) }}</span> goal
                        </div>
                    </div>

                    <!-- Progress % -->
                    <div class="flex flex-col">
                        <div class="text-xs font-medium text-[var(--color-text-tertiary)] uppercase tracking-wider mb-2">Progress</div>
                        <div class="flex items-baseline gap-2">
                            <div class="text-3xl font-bold tracking-tight" :class="isOnTrack ? 'text-emerald-500' : 'text-amber-500'">
                                {{ progress.revenue.progress_pct.toFixed(1) }}%
                            </div>
                        </div>
                        <div class="text-sm text-[var(--color-text-tertiary)] mt-1">
                            {{ progress.pace.time_elapsed_pct.toFixed(0) }}% time elapsed
                        </div>
                    </div>

                    <!-- Remaining -->
                    <div class="flex flex-col">
                        <div class="text-xs font-medium text-[var(--color-text-tertiary)] uppercase tracking-wider mb-2">Remaining</div>
                        <div class="text-3xl font-bold text-[var(--color-text-primary)] tracking-tight">{{ formatCompact(requirements.revenue_remaining) }}</div>
                        <div class="text-sm text-[var(--color-text-tertiary)] mt-1">{{ requirements.weeks_remaining }} weeks left</div>
                    </div>

                    <!-- Weekly Requirement -->
                    <div class="flex flex-col">
                        <div class="text-xs font-medium text-[var(--color-text-tertiary)] uppercase tracking-wider mb-2">Weekly Target</div>
                        <div class="text-3xl font-bold text-blue-500 tracking-tight">{{ requirements.leads_per_week }}</div>
                        <div class="text-sm text-[var(--color-text-tertiary)] mt-1">leads needed / week</div>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="relative pt-2">
                    <div class="h-3 bg-[var(--color-bg-tertiary)] rounded-full overflow-hidden">
                        <div
                            :class="[getProgressColor(progress.revenue.progress_pct), 'h-full rounded-full transition-all duration-1000 ease-out']"
                            :style="{ width: `${Math.min(100, progress.revenue.progress_pct)}%` }"
                        ></div>
                    </div>
                    
                    <!-- Time marker -->
                    <div
                        class="absolute top-0 bottom-0 w-px bg-[var(--color-text-tertiary)] z-10 flex flex-col justify-end"
                        :style="{ left: `${progress.pace.time_elapsed_pct}%` }"
                    >
                        <div class="w-1.5 h-1.5 bg-[var(--color-text-tertiary)] rounded-full -ml-[2px] mb-3"></div>
                    </div>

                    <div class="flex justify-between text-xs font-medium text-[var(--color-text-tertiary)] mt-3">
                        <span>Start</span>
                        <span class="text-[var(--color-text-secondary)]">Today ({{ progress.pace.time_elapsed_pct.toFixed(0) }}%)</span>
                        <span>End</span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-[var(--color-border-default)]">
                <div class="flex gap-6">
                    <button
                        v-for="tab in ['overview', 'periods', 'levers', 'forecast']"
                        :key="tab"
                        @click="activeTab = tab as typeof activeTab"
                        :class="[
                            'pb-3 text-sm font-medium border-b-2 transition-colors capitalize',
                            activeTab === tab
                                ? 'border-blue-500 text-[var(--color-text-primary)]'
                                : 'border-transparent text-[var(--color-text-tertiary)] hover:text-[var(--color-text-secondary)]'
                        ]"
                    >
                        {{ tab }}
                    </button>
                </div>
            </div>

            <!-- Tab Content: Overview -->
            <div v-if="activeTab === 'overview'" class="grid grid-cols-2 gap-6">
                <!-- Current Periods -->
                <div class="space-y-4">
                    <h3 class="text-lg font-medium text-[var(--color-text-primary)]">Current Periods</h3>

                    <div v-if="periods.current_quarter" class="card p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[var(--color-text-tertiary)] text-sm">{{ periods.current_quarter.label }}</span>
                            <span :class="[getStatusBadge(periods.current_quarter.status).class, 'px-2 py-0.5 rounded text-xs']">
                                {{ getStatusBadge(periods.current_quarter.status).text }}
                            </span>
                        </div>
                        <div class="flex items-baseline gap-2 mb-2">
                            <span class="text-xl font-semibold text-[var(--color-text-primary)]">{{ formatCompact(periods.current_quarter.revenue.actual) }}</span>
                            <span class="text-[var(--color-text-tertiary)]">/ {{ formatCompact(periods.current_quarter.revenue.target) }}</span>
                        </div>
                        <div class="h-2 bg-[var(--color-bg-tertiary)] rounded-full overflow-hidden">
                            <div
                                :class="getProgressColor(periods.current_quarter.revenue.progress)"
                                class="h-full rounded-full"
                                :style="{ width: `${Math.min(100, periods.current_quarter.revenue.progress)}%` }"
                            ></div>
                        </div>
                    </div>

                    <div v-if="periods.current_month" class="card p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[var(--color-text-tertiary)] text-sm">{{ periods.current_month.label }}</span>
                            <span :class="[getStatusBadge(periods.current_month.status).class, 'px-2 py-0.5 rounded text-xs']">
                                {{ getStatusBadge(periods.current_month.status).text }}
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <div class="text-xs text-[var(--color-text-tertiary)] mb-1">Revenue</div>
                                <div class="text-[var(--color-text-primary)] font-medium">{{ formatCompact(periods.current_month.revenue.actual) }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-[var(--color-text-tertiary)] mb-1">Leads</div>
                                <div class="text-[var(--color-text-primary)] font-medium">{{ periods.current_month.leads.actual }} / {{ periods.current_month.leads.target }}</div>
                            </div>
                        </div>
                    </div>

                    <div v-if="periods.current_week" class="card p-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[var(--color-text-tertiary)] text-sm">{{ periods.current_week.label }}</span>
                            <span :class="[getStatusBadge(periods.current_week.status).class, 'px-2 py-0.5 rounded text-xs']">
                                {{ getStatusBadge(periods.current_week.status).text }}
                            </span>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <div class="text-xs text-[var(--color-text-tertiary)] mb-1">Leads Target</div>
                                <div class="text-[var(--color-text-primary)] font-medium">{{ periods.current_week.leads.target }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-[var(--color-text-tertiary)] mb-1">Leads Actual</div>
                                <div class="text-[var(--color-text-primary)] font-medium">{{ periods.current_week.leads.actual }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="space-y-4">
                    <h3 class="text-lg font-medium text-[var(--color-text-primary)]">Key Metrics</h3>

                    <div class="card p-4">
                        <div class="text-[var(--color-text-tertiary)] text-sm mb-3">Pipeline Health</div>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-[var(--color-text-tertiary)]">Pipeline Value</span>
                                <span class="text-[var(--color-text-primary)]">{{ formatCompact(progress.pipeline.actual) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[var(--color-text-tertiary)]">Coverage Ratio</span>
                                <span :class="progress.pipeline.coverage >= 3 ? 'text-emerald-400' : 'text-amber-400'">
                                    {{ progress.pipeline.coverage }}x
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[var(--color-text-tertiary)]">Deals Won YTD</span>
                                <span class="text-[var(--color-text-primary)]">{{ progress.deals.actual }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="card p-4">
                        <div class="text-[var(--color-text-tertiary)] text-sm mb-3">Assumptions</div>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-[var(--color-text-tertiary)]">Win Rate</span>
                                <span class="text-[var(--color-text-primary)]">{{ requirements.assumptions.win_rate }}%</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[var(--color-text-tertiary)]">Avg Deal Size</span>
                                <span class="text-[var(--color-text-primary)]">{{ formatCurrency(requirements.assumptions.avg_deal_size) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[var(--color-text-tertiary)]">Sales Cycle</span>
                                <span class="text-[var(--color-text-primary)]">{{ requirements.assumptions.avg_cycle_days }} days</span>
                            </div>
                        </div>
                    </div>

                    <div class="card p-4">
                        <div class="text-[var(--color-text-tertiary)] text-sm mb-3">Capacity</div>
                        <div class="space-y-3">
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-[var(--color-text-tertiary)]">Lead Generation</span>
                                    <span :class="capacity.lead_generation.is_overloaded ? 'text-red-400' : 'text-[var(--color-text-primary)]'">
                                        {{ capacity.lead_generation.capacity_used_pct }}%
                                    </span>
                                </div>
                                <div class="h-1.5 bg-[var(--color-bg-tertiary)] rounded-full overflow-hidden">
                                    <div
                                        :class="capacity.lead_generation.is_overloaded ? 'bg-red-500' : 'bg-blue-500'"
                                        class="h-full rounded-full"
                                        :style="{ width: `${Math.min(100, capacity.lead_generation.capacity_used_pct)}%` }"
                                    ></div>
                                </div>
                            </div>
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="text-[var(--color-text-tertiary)]">Pipeline Management</span>
                                    <span :class="capacity.pipeline_management.is_overloaded ? 'text-red-400' : 'text-[var(--color-text-primary)]'">
                                        {{ capacity.pipeline_management.capacity_used_pct }}%
                                    </span>
                                </div>
                                <div class="h-1.5 bg-[var(--color-bg-tertiary)] rounded-full overflow-hidden">
                                    <div
                                        :class="capacity.pipeline_management.is_overloaded ? 'bg-red-500' : 'bg-blue-500'"
                                        class="h-full rounded-full"
                                        :style="{ width: `${Math.min(100, capacity.pipeline_management.capacity_used_pct)}%` }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Periods -->
            <div v-if="activeTab === 'periods'" class="space-y-6">
                <div class="card">
                    <div class="border-b border-[var(--color-border-default)] px-6 py-4">
                        <h3 class="text-lg font-medium text-[var(--color-text-primary)]">Quarterly Breakdown</h3>
                    </div>
                    <div class="divide-y divide-[var(--color-border-default)]">
                        <div
                            v-for="quarter in periods.quarters"
                            :key="quarter.id"
                            :class="['px-6 py-4', quarter.is_current ? 'bg-blue-600/10' : '']"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-3">
                                    <span class="text-[var(--color-text-primary)] font-medium">{{ quarter.label }}</span>
                                    <span v-if="quarter.is_current" class="text-xs text-blue-400">Current</span>
                                </div>
                                <span :class="[getStatusBadge(quarter.status).class, 'px-2 py-0.5 rounded text-xs']">
                                    {{ getStatusBadge(quarter.status).text }}
                                </span>
                            </div>
                            <div class="grid grid-cols-4 gap-4 text-sm">
                                <div>
                                    <span class="text-[var(--color-text-tertiary)]">Revenue:</span>
                                    <span class="text-[var(--color-text-primary)] ml-2">{{ formatCompact(quarter.revenue.actual) }} / {{ formatCompact(quarter.revenue.target) }}</span>
                                </div>
                                <div>
                                    <span class="text-[var(--color-text-tertiary)]">Leads:</span>
                                    <span class="text-[var(--color-text-primary)] ml-2">{{ quarter.leads.actual }} / {{ quarter.leads.target }}</span>
                                </div>
                                <div>
                                    <span class="text-[var(--color-text-tertiary)]">Deals:</span>
                                    <span class="text-[var(--color-text-primary)] ml-2">{{ quarter.deals.actual }} / {{ quarter.deals.target }}</span>
                                </div>
                                <div>
                                    <div class="h-2 bg-[var(--color-bg-elevated)] rounded-full overflow-hidden">
                                        <div
                                            :class="getProgressColor(quarter.revenue.progress)"
                                            class="h-full rounded-full"
                                            :style="{ width: `${Math.min(100, quarter.revenue.progress)}%` }"
                                        ></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Levers -->
            <div v-if="activeTab === 'levers'" class="space-y-6">
                <div :class="[
                    'p-4 rounded-xl',
                    levers.status === 'on_track' ? 'bg-emerald-600/20 border border-emerald-500/30' :
                    levers.status === 'critical' ? 'bg-red-600/20 border border-red-500/30' :
                    'bg-amber-600/20 border border-amber-500/30'
                ]">
                    <p class="text-[var(--color-text-primary)]">{{ levers.message }}</p>
                    <p v-if="levers.revenue_gap" class="text-[var(--color-text-secondary)] text-sm mt-1">
                        Gap: {{ formatCurrency(levers.revenue_gap) }} in {{ levers.weeks_remaining }} weeks
                    </p>
                </div>

                <div v-if="levers.levers.length" class="space-y-4">
                    <div
                        v-for="lever in levers.levers"
                        :key="lever.lever"
                        class="card p-6"
                    >
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h4 class="text-[var(--color-text-primary)] font-medium">{{ lever.title }}</h4>
                                <p class="text-[var(--color-text-tertiary)] text-sm">{{ lever.description }}</p>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-[var(--color-text-tertiary)] mb-1">Impact Score</div>
                                <div class="text-xl font-bold text-blue-400">{{ lever.impact_score }}</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="bg-[var(--color-bg-tertiary)] rounded-lg p-3">
                                <div class="text-xs text-[var(--color-text-tertiary)] mb-1">Current</div>
                                <div class="text-[var(--color-text-primary)]">{{ lever.current }}</div>
                            </div>
                            <div class="bg-[var(--color-bg-tertiary)] rounded-lg p-3">
                                <div class="text-xs text-[var(--color-text-tertiary)] mb-1">Target</div>
                                <div class="text-emerald-400">{{ lever.target }}</div>
                            </div>
                        </div>

                        <div>
                            <div class="text-xs text-[var(--color-text-tertiary)] uppercase mb-2">Recommended Actions</div>
                            <ul class="space-y-1">
                                <li v-for="action in lever.actions" :key="action" class="text-[var(--color-text-secondary)] text-sm flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                                    {{ action }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Forecast -->
            <div v-if="activeTab === 'forecast'" class="space-y-6">
                <div class="grid grid-cols-3 gap-6">
                    <div
                        v-for="(data, days) in forecast.forecasts"
                        :key="days"
                        class="card p-6"
                    >
                        <div class="text-[var(--color-text-tertiary)] text-sm mb-2">{{ days }}-Day Forecast</div>
                        <div class="text-2xl font-bold text-[var(--color-text-primary)] mb-4">{{ formatCompact(data.blended) }}</div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-[var(--color-text-tertiary)]">Velocity-based</span>
                                <span class="text-[var(--color-text-primary)]">{{ formatCompact(data.velocity_based) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-[var(--color-text-tertiary)]">Pipeline-based</span>
                                <span class="text-[var(--color-text-primary)]">{{ formatCompact(data.pipeline_based) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div :class="[
                    'card p-6',
                    forecast.year_end.will_hit_target ? 'border-emerald-500/30' : 'border-red-500/30'
                ]">
                    <h3 class="text-lg font-medium text-[var(--color-text-primary)] mb-4">Year-End Projection</h3>
                    <div class="grid grid-cols-4 gap-6">
                        <div>
                            <div class="text-xs text-[var(--color-text-tertiary)] uppercase mb-1">Days Remaining</div>
                            <div class="text-2xl font-bold text-[var(--color-text-primary)]">{{ forecast.year_end.days_remaining }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-[var(--color-text-tertiary)] uppercase mb-1">Velocity Forecast</div>
                            <div class="text-2xl font-bold text-[var(--color-text-primary)]">{{ formatCompact(forecast.year_end.velocity_forecast) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-[var(--color-text-tertiary)] uppercase mb-1">Pipeline Forecast</div>
                            <div class="text-2xl font-bold text-[var(--color-text-primary)]">{{ formatCompact(forecast.year_end.pipeline_forecast) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-[var(--color-text-tertiary)] uppercase mb-1">Projected Gap</div>
                            <div :class="['text-2xl font-bold', forecast.year_end.gap > 0 ? 'text-red-400' : 'text-emerald-400']">
                                {{ forecast.year_end.gap > 0 ? '-' : '+' }}{{ formatCompact(Math.abs(forecast.year_end.gap)) }}
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-[var(--color-border-default)]">
                        <p :class="forecast.year_end.will_hit_target ? 'text-emerald-400' : 'text-red-400'">
                            {{ forecast.year_end.will_hit_target ? 'On track to hit target' : 'Action needed to close the gap' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.hero-gradient {
    background: linear-gradient(to bottom right, 
        var(--color-bg-tertiary), 
        var(--color-bg-secondary), 
        var(--color-bg-secondary)
    );
}

.light .hero-gradient {
    background: linear-gradient(to bottom right, 
        rgb(239 246 255), 
        white, 
        white
    );
}
</style>
