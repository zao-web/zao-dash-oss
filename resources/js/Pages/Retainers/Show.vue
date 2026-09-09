<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

interface Client {
    id: number;
    name: string;
    slug: string;
    initials: string;
    contacts: { id: number; name: string; email: string; role: string }[];
}

interface Retainer {
    id: number;
    client: Client | null;
    tier: string | null;
    monthly_amount: number;
    hours_included: number;
    total_hours: number;
    health_status: string;
    effective_margin_percent: number | null;
    period_start: string;
    period_end: string;
    internal_hourly_rate: number;
    ai_equivalent_hourly_rate: number;
}

interface Snapshot {
    human_hours: number;
    human_hours_by_type: Record<string, number>;
    meeting_hours: number;
    meeting_count: number;
    agent_cost_usd: number;
    agent_tasks_completed: number;
    agent_equivalent_hours: number;
    total_equivalent_hours: number;
    hours_budget: number;
    hours_remaining: number;
    usage_percent: number;
    is_over_budget: boolean;
    cost_to_serve: number;
    monthly_amount: number;
    effective_margin_percent: number | null;
    health_status: string;
    alerts: { type: string; message: string; action: string | null }[];
}

interface TrendEntry extends Snapshot {
    month: string;
}

interface AgentRun {
    id: number;
    task: string;
    effort_type: string | null;
    cost_usd: number;
    duration_ms: number | null;
    status: string;
    started_at: string;
}

interface Meeting {
    id: number;
    title: string;
    start_at: string;
    duration_hours: number;
    attendees: { email: string; name?: string }[];
}

interface OpenTask {
    id: number;
    title: string;
    priority: string;
    status: string;
    created_at: string;
}

interface ReportEntry {
    id: number;
    period_label: string;
    status: string;
    sent_at: string | null;
    opens_count: number;
}

const props = defineProps<{
    retainer: { data: Retainer };
    snapshot: Snapshot;
    trend: TrendEntry[];
    agentRuns: AgentRun[];
    meetings: Meeting[];
    openTasks: OpenTask[];
    reportHistory: ReportEntry[];
}>();

const ret = computed(() => props.retainer.data);
const refreshing = ref(false);

// Hours utilization
const usagePct = computed(() => Math.min(props.snapshot.usage_percent, 100));
const overBudgetHours = computed(() => {
    if (!props.snapshot.is_over_budget) return 0;
    return Math.max(0, props.snapshot.total_equivalent_hours - props.snapshot.hours_budget);
});
const isCurrentMonth = computed(() => {
    const start = new Date(ret.value.period_start);
    const now = new Date();
    return start.getFullYear() === now.getFullYear() && start.getMonth() === now.getMonth();
});
const daysInPeriod = computed(() => {
    const start = new Date(ret.value.period_start);
    const end = new Date(ret.value.period_end);
    return Math.ceil((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24));
});
const daysRemaining = computed(() => {
    const end = new Date(ret.value.period_end);
    const today = new Date();
    const diff = Math.ceil((end.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
    return Math.max(0, diff);
});
const timePct = computed(() => {
    if (daysInPeriod.value === 0) return 100;
    return Math.round(((daysInPeriod.value - daysRemaining.value) / daysInPeriod.value) * 100);
});
const progressBarColor = computed(() => {
    if (props.snapshot.is_over_budget) return 'bg-[var(--color-status-red)]';
    if (props.snapshot.usage_percent >= 80) return 'bg-[var(--color-status-yellow)]';
    return 'bg-[var(--color-status-green)]';
});

const refreshSnapshot = () => {
    refreshing.value = true;
    router.post(`/retainers/${ret.value.id}/snapshot`, {}, {
        preserveState: true,
        onFinish: () => {
            refreshing.value = false;
            router.reload();
        },
    });
};

const healthBadge = computed(() => {
    const map: Record<string, { bg: string; text: string; label: string }> = {
        healthy: { bg: 'bg-green-50 dark:bg-green-900/30', text: 'text-green-700 dark:text-green-400', label: 'Healthy' },
        warning: { bg: 'bg-amber-50 dark:bg-amber-900/30', text: 'text-amber-700 dark:text-amber-400', label: 'Warning' },
        critical: { bg: 'bg-red-50 dark:bg-red-900/30', text: 'text-red-700 dark:text-red-400', label: 'Needs Attention' },
        silent: { bg: 'bg-amber-50 dark:bg-amber-900/30', text: 'text-amber-700 dark:text-amber-400', label: 'Silent' },
    };
    return map[props.snapshot.health_status] || map.healthy;
});

const isDark = computed(() => !document.documentElement.classList.contains('light'));
const chartTextColor = computed(() => isDark.value ? '#a8a29a' : '#5a554d');
const chartGridColor = computed(() => isDark.value ? 'rgba(255, 245, 230, 0.09)' : 'rgba(80, 65, 40, 0.10)');

const marginTrendOptions = computed(() => ({
    chart: { type: 'line', height: 240, toolbar: { show: false }, background: 'transparent', foreColor: chartTextColor.value },
    stroke: { width: 2.5, curve: 'smooth' },
    colors: ['#3fba6d'],
    xaxis: { categories: props.trend.map(t => t.month) },
    yaxis: {
        labels: { formatter: (v: number) => `${Math.round(v)}%` },
    },
    annotations: {
        yaxis: [
            { y: 20, borderColor: '#d4a72c', label: { text: '20% threshold', style: { color: '#d4a72c' } } },
            { y: 0, borderColor: '#e05252', label: { text: '0% break-even', style: { color: '#e05252' } } },
        ],
    },
    grid: { borderColor: chartGridColor.value, strokeDashArray: 4 },
    theme: { mode: isDark.value ? 'dark' : 'light' },
    tooltip: { y: { formatter: (v: number) => `${v.toFixed(1)}%` } },
}));

const marginTrendSeries = computed(() => [{
    name: 'Margin',
    data: props.trend.map(t => t.effective_margin_percent ?? 0),
}]);

const effortTrendOptions = computed(() => ({
    chart: { type: 'bar', height: 240, stacked: true, toolbar: { show: false }, background: 'transparent', foreColor: chartTextColor.value },
    plotOptions: { bar: { borderRadius: 4 } },
    colors: ['#4a9eed', '#3fba6d', '#c49a4b'],
    xaxis: { categories: props.trend.map(t => t.month) },
    yaxis: { labels: { formatter: (v: number) => `${v.toFixed(1)}h` } },
    grid: { borderColor: chartGridColor.value, strokeDashArray: 4 },
    legend: { position: 'bottom' },
    theme: { mode: isDark.value ? 'dark' : 'light' },
}));

const effortTrendSeries = computed(() => {
    return [
        {
            name: 'Meetings',
            data: props.trend.map(t => t.meeting_hours ?? 0),
        },
        {
            name: 'Billable',
            data: props.trend.map(t => {
                // Human hours minus meeting hours = actual billable work
                const billable = (t.human_hours ?? 0) - (t.meeting_hours ?? 0);
                return Math.max(0, Math.round(billable * 10) / 10);
            }),
        },
        {
            name: 'Agent (equiv)',
            data: props.trend.map(t => t.agent_equivalent_hours ?? 0),
        },
    ];
});

const formatCurrency = (val: number) => new Intl.NumberFormat('en-US', {
    style: 'currency', currency: 'USD', minimumFractionDigits: 0, maximumFractionDigits: 0,
}).format(val);

const formatDuration = (ms: number | null) => {
    if (!ms) return '--';
    const seconds = Math.floor(ms / 1000);
    if (seconds < 60) return `${seconds}s`;
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
};

const formatDate = (iso: string) => {
    return new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const alertTypeColors: Record<string, string> = {
    danger: 'bg-red-50 text-red-700 border-red-200 dark:bg-red-900/20 dark:text-red-400 dark:border-red-800',
    warning: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/20 dark:text-amber-400 dark:border-amber-800',
    info: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/20 dark:text-blue-400 dark:border-blue-800',
};

const taskAge = (createdAt: string) => {
    const days = Math.floor((Date.now() - new Date(createdAt).getTime()) / (1000 * 60 * 60 * 24));
    if (days === 0) return 'today';
    if (days === 1) return '1d';
    return `${days}d`;
};
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Back link -->
            <Link href="/retainers" class="mb-4 inline-flex items-center gap-1 text-sm text-[var(--color-text-tertiary)] hover:text-[var(--color-text-primary)]">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                Retainers
            </Link>

            <!-- 1. Header Strip -->
            <div class="mb-8 space-y-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[var(--color-bg-tertiary)] text-sm font-bold text-[var(--color-text-secondary)]">
                        {{ ret.client?.initials || '??' }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <h1 class="font-[var(--font-display)] text-xl sm:text-2xl font-bold text-[var(--color-text-primary)] truncate">
                            {{ ret.client?.name }}
                        </h1>
                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs sm:text-sm text-[var(--color-text-tertiary)]">
                            <span v-if="ret.tier" class="rounded bg-[var(--color-bg-tertiary)] px-2 py-0.5 text-xs">
                                {{ ret.tier.replace(/_/g, ' + ') }}
                            </span>
                            <span>{{ ret.period_start }} — {{ ret.period_end }}</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="font-[var(--font-mono)] text-lg sm:text-xl font-bold text-[var(--color-text-primary)]">
                        {{ formatCurrency(ret.monthly_amount) }}<span class="text-sm font-normal text-[var(--color-text-tertiary)]">/mo</span>
                    </span>
                    <span class="rounded-full px-3 py-1 text-xs font-medium" :class="[healthBadge.bg, healthBadge.text]">
                        {{ healthBadge.label }}
                    </span>
                    <div class="ml-auto flex items-center gap-2">
                        <a v-if="!isCurrentMonth" :href="`/retainers/${ret.id}/current`"
                           class="rounded-lg bg-[var(--color-accent)] px-3 py-1.5 text-sm font-medium text-[#100f0d] hover:opacity-90">
                            Current month
                        </a>
                        <a :href="`/retainers/${ret.id}/report`" target="_blank"
                           class="rounded-lg border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] px-3 py-1.5 text-sm font-medium text-[var(--color-text-primary)] hover:bg-[var(--color-bg-tertiary)]">
                            View Report
                        </a>
                        <a :href="`/retainers/${ret.id}/report/pdf`"
                           class="rounded-lg border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] px-3 py-1.5 text-sm font-medium text-[var(--color-text-primary)] hover:bg-[var(--color-bg-tertiary)]">
                            Download PDF
                        </a>
                        <button @click="refreshSnapshot" :disabled="refreshing"
                                class="rounded-lg bg-[var(--color-accent)] px-3 py-1.5 text-sm font-medium text-[#100f0d] hover:opacity-90 disabled:opacity-50">
                            {{ refreshing ? 'Refreshing...' : 'Refresh' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Hours Utilization -->
            <div class="mb-8 rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-4 sm:p-5">
                <div class="flex items-baseline justify-between gap-4 mb-3">
                    <div class="flex items-baseline gap-2">
                        <span class="font-[var(--font-mono)] text-2xl sm:text-3xl font-bold text-[var(--color-text-primary)]">
                            {{ snapshot.total_equivalent_hours.toFixed(1) }}
                        </span>
                        <span class="text-sm text-[var(--color-text-tertiary)]">
                            / {{ snapshot.hours_budget }}h used
                        </span>
                    </div>
                    <div class="text-right shrink-0">
                        <span class="text-sm font-medium" :class="snapshot.is_over_budget ? 'text-[var(--color-status-red)]' : daysRemaining <= 7 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-text-tertiary)]'">
                            {{ daysRemaining }}d left
                        </span>
                    </div>
                </div>

                <!-- Hours bar -->
                <div class="h-3 w-full overflow-hidden rounded-full bg-[var(--color-bg-tertiary)]">
                    <div class="h-full rounded-full transition-all duration-500" :class="progressBarColor" :style="{ width: `${usagePct}%` }"></div>
                </div>

                <!-- Subtext row -->
                <div class="mt-2 flex items-center justify-between text-xs text-[var(--color-text-tertiary)]">
                    <span>
                        {{ snapshot.human_hours.toFixed(1) }}h human
                        <template v-if="snapshot.agent_equivalent_hours > 0">
                            + {{ snapshot.agent_equivalent_hours.toFixed(1) }}h AI
                        </template>
                    </span>
                    <span v-if="snapshot.is_over_budget" class="font-medium text-[var(--color-status-red)]">
                        {{ overBudgetHours.toFixed(1) }}h over budget
                    </span>
                    <span v-else>
                        {{ snapshot.hours_remaining.toFixed(1) }}h remaining
                    </span>
                </div>

                <!-- Time through period indicator -->
                <div class="mt-3 flex items-center gap-2 text-xs text-[var(--color-text-quaternary)]">
                    <div class="h-1 flex-1 overflow-hidden rounded-full bg-[var(--color-bg-tertiary)]">
                        <div class="h-full rounded-full bg-[var(--color-text-quaternary)] opacity-40" :style="{ width: `${timePct}%` }"></div>
                    </div>
                    <span class="shrink-0">{{ timePct }}% through period</span>
                </div>
            </div>

            <!-- 2. This Month Snapshot -->
            <div class="mb-8 grid grid-cols-1 sm:grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-4">
                    <p class="text-xs uppercase tracking-wider text-[var(--color-text-tertiary)]">Human Hours</p>
                    <p class="mt-1 font-[var(--font-mono)] text-xl font-bold text-[var(--color-text-primary)]">{{ snapshot.human_hours.toFixed(1) }}h</p>
                </div>
                <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-4">
                    <p class="text-xs uppercase tracking-wider text-[var(--color-text-tertiary)]">Meetings</p>
                    <p class="mt-1 font-[var(--font-mono)] text-xl font-bold text-[var(--color-text-primary)]">{{ snapshot.meeting_count }}</p>
                    <p class="text-xs text-[var(--color-text-tertiary)]">{{ snapshot.meeting_hours.toFixed(1) }}h total</p>
                </div>
                <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-4">
                    <p class="text-xs uppercase tracking-wider text-[var(--color-text-tertiary)]">Agent Tasks</p>
                    <p class="mt-1 font-[var(--font-mono)] text-xl font-bold text-[var(--color-accent)]">{{ snapshot.agent_tasks_completed }}</p>
                    <p class="text-xs text-[var(--color-text-tertiary)]">{{ formatCurrency(snapshot.agent_cost_usd) }} cost</p>
                </div>
                <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-4">
                    <p class="text-xs uppercase tracking-wider text-[var(--color-text-tertiary)]">Margin</p>
                    <p class="mt-1 font-[var(--font-mono)] text-xl font-bold"
                       :class="(snapshot.effective_margin_percent ?? 0) >= 30 ? 'text-green-600' : (snapshot.effective_margin_percent ?? 0) >= 0 ? 'text-amber-600' : 'text-red-600'">
                        {{ snapshot.effective_margin_percent !== null ? `${snapshot.effective_margin_percent.toFixed(1)}%` : '--' }}
                    </p>
                    <p class="text-xs text-[var(--color-text-tertiary)]">Cost: {{ formatCurrency(snapshot.cost_to_serve) }}</p>
                </div>
            </div>

            <!-- Alerts -->
            <div v-if="snapshot.alerts.length > 0" class="mb-8 space-y-2">
                <div v-for="(alert, i) in snapshot.alerts" :key="i"
                     class="flex items-center justify-between rounded-lg border px-4 py-3"
                     :class="alertTypeColors[alert.type] || alertTypeColors.info">
                    <span class="text-sm font-medium">{{ alert.message }}</span>
                    <span v-if="alert.action" class="text-xs opacity-70">{{ alert.action }}</span>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- 3. Margin Trend -->
                <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-5">
                    <h3 class="mb-4 font-[var(--font-display)] text-sm font-semibold text-[var(--color-text-primary)]">6-Month Margin Trend</h3>
                    <VueApexCharts type="line" :options="marginTrendOptions" :series="marginTrendSeries" height="240" />
                </div>

                <!-- 4. Effort Breakdown -->
                <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] p-5">
                    <h3 class="mb-4 font-[var(--font-display)] text-sm font-semibold text-[var(--color-text-primary)]">6-Month Effort Breakdown</h3>
                    <VueApexCharts type="bar" :options="effortTrendOptions" :series="effortTrendSeries" height="240" />
                </div>
            </div>

            <!-- 5. Recent Agent Runs -->
            <div class="mb-8 rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)]">
                <div class="border-b border-[var(--color-border-default)] px-5 py-4">
                    <h3 class="font-[var(--font-display)] text-sm font-semibold text-[var(--color-text-primary)]">Recent Agent Runs</h3>
                </div>
                <div v-if="agentRuns.length > 0" class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-[var(--color-border-default)] text-xs uppercase text-[var(--color-text-tertiary)]">
                            <tr>
                                <th class="px-5 py-3">Task</th>
                                <th class="px-5 py-3">Type</th>
                                <th class="px-5 py-3 text-right">Cost</th>
                                <th class="px-5 py-3 text-right">Duration</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-right">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-subtle)]">
                            <tr v-for="run in agentRuns" :key="run.id" class="text-[var(--color-text-secondary)]">
                                <td class="max-w-xs truncate px-5 py-3">{{ run.task }}</td>
                                <td class="px-5 py-3">
                                    <span v-if="run.effort_type" class="rounded bg-[var(--color-bg-tertiary)] px-2 py-0.5 text-xs">{{ run.effort_type }}</span>
                                </td>
                                <td class="px-5 py-3 text-right font-[var(--font-mono)]">${{ Number(run.cost_usd).toFixed(2) }}</td>
                                <td class="px-5 py-3 text-right">{{ formatDuration(run.duration_ms) }}</td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                          :class="run.status === 'completed' ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                              : run.status === 'failed' ? 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                              : 'bg-[var(--color-bg-tertiary)] text-[var(--color-text-secondary)]'">
                                        {{ run.status }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right text-[var(--color-text-tertiary)]">{{ run.started_at ? formatDate(run.started_at) : '--' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="px-5 py-8 text-center text-sm text-[var(--color-text-tertiary)]">
                    No agent runs recorded for this client.
                </div>
            </div>

            <!-- 6. Meeting Log -->
            <div class="mb-8 rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)]">
                <div class="border-b border-[var(--color-border-default)] px-5 py-4">
                    <h3 class="font-[var(--font-display)] text-sm font-semibold text-[var(--color-text-primary)]">Meeting Log</h3>
                </div>
                <div v-if="meetings.length > 0" class="divide-y divide-[var(--color-border-subtle)]">
                    <div v-for="meeting in meetings" :key="meeting.id" class="flex items-start justify-between gap-3 px-4 sm:px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-[var(--color-text-primary)]">{{ meeting.title }}</p>
                            <p class="mt-0.5 text-xs text-[var(--color-text-tertiary)] truncate">
                                {{ meeting.attendees?.map(a => a.name || a.email).join(', ') }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-sm text-[var(--color-text-secondary)]">{{ formatDate(meeting.start_at) }}</p>
                            <p class="text-xs text-[var(--color-text-tertiary)]">{{ meeting.duration_hours.toFixed(1) }}h</p>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-8 text-center text-sm text-[var(--color-text-tertiary)]">
                    No meetings recorded — check Google Calendar sync.
                </div>
            </div>

            <!-- 7. Open Tickets -->
            <div class="mb-8 rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)]">
                <div class="border-b border-[var(--color-border-default)] px-5 py-4">
                    <h3 class="font-[var(--font-display)] text-sm font-semibold text-[var(--color-text-primary)]">Open Tickets</h3>
                </div>
                <div v-if="openTasks.length > 0" class="divide-y divide-[var(--color-border-subtle)]">
                    <div v-for="task in openTasks" :key="task.id" class="flex items-center justify-between px-5 py-3">
                        <div class="flex items-center gap-3">
                            <span class="rounded px-2 py-0.5 text-xs font-medium"
                                  :class="{
                                      'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400': task.priority === 'urgent',
                                      'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400': task.priority === 'high',
                                      'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400': task.priority === 'medium',
                                      'bg-[var(--color-bg-tertiary)] text-[var(--color-text-secondary)]': task.priority === 'low' || !task.priority,
                                  }">
                                {{ task.priority || 'none' }}
                            </span>
                            <span class="text-sm text-[var(--color-text-primary)]">{{ task.title }}</span>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-[var(--color-text-tertiary)]">
                            <span>{{ taskAge(task.created_at) }}</span>
                            <span class="rounded bg-[var(--color-bg-tertiary)] px-2 py-0.5">{{ task.status }}</span>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-8 text-center text-sm text-[var(--color-text-tertiary)]">
                    No open tickets.
                </div>
            </div>

            <!-- 8. Report History -->
            <div class="rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)]">
                <div class="border-b border-[var(--color-border-default)] px-5 py-4">
                    <h3 class="font-[var(--font-display)] text-sm font-semibold text-[var(--color-text-primary)]">Report History</h3>
                </div>
                <div v-if="reportHistory.length > 0" class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-[var(--color-border-default)] text-xs uppercase text-[var(--color-text-tertiary)]">
                            <tr>
                                <th class="px-5 py-3">Period</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3">Sent</th>
                                <th class="px-5 py-3 text-right">Opens</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-border-subtle)]">
                            <tr v-for="report in reportHistory" :key="report.id" class="text-[var(--color-text-secondary)]">
                                <td class="px-5 py-3">{{ report.period_label }}</td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                          :class="report.status === 'sent' ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                              : report.status === 'draft' ? 'bg-[var(--color-bg-tertiary)] text-[var(--color-text-secondary)]'
                                              : 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'">
                                        {{ report.status }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-[var(--color-text-tertiary)]">{{ report.sent_at ? formatDate(report.sent_at) : '--' }}</td>
                                <td class="px-5 py-3 text-right font-[var(--font-mono)]">{{ report.opens_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="px-5 py-8 text-center text-sm text-[var(--color-text-tertiary)]">
                    No reports generated yet.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
