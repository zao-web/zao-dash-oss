<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import FormToggle from '@/Components/FormToggle.vue';
import AgentQuickTrigger from '@/Components/AgentQuickTrigger.vue';
import AgentCheckinsWidget from '@/Components/AgentCheckinsWidget.vue';
import FocusPanel from '@/Components/FocusPanel.vue';
import ProactiveInsights from '@/Components/ProactiveInsights.vue';
import GoalProgressWidget from '@/Components/GoalProgressWidget.vue';
import WeeklyPlanWidget from '@/Components/WeeklyPlanWidget.vue';
import { ChartCard, AreaChart, DonutChart, RadialGauge, BarChart } from '@/Components/Charts';
import { ChartIcon, CpuIcon, CheckIcon, WarningIcon, PlayIcon } from '@/Components/Icons';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, reactive, onMounted, onUnmounted } from 'vue';
import echo from '@/echo';

interface KpiData {
    revenue_mtd: number;
    revenue_change_pct: number;
    active_projects: number;
    client_health_avg: number;
    hours_tracked_mtd: number;
    pending_approvals: number;
    running_agents: number;
    total_agents: number;
}

interface AgentRun {
    id: number;
    agent_name: string;
    agent_slug: string;
    status: 'pending' | 'running' | 'completed' | 'failed' | 'awaiting_approval';
    trigger: string;
    cost_usd: string;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
}

interface ApprovalRequest {
    id: number;
    action_type: string;
    summary: string;
    risk_level: 'low' | 'medium' | 'high' | 'critical';
    agent_name: string;
    expires_at: string | null;
    created_at: string;
    context?: string;
    payload?: Record<string, unknown>;
}

interface Client {
    id: number;
    name: string;
    slug: string;
    health_score: number;
    status: string;
}

interface ChartKpis {
    pipeline: {
        total_pipeline: number;
        weighted_pipeline: number;
        win_rate: number;
        won_count: number;
        lost_count: number;
        won_value_mtd: number;
        funnel: { stage: string; count: number }[];
        by_stage: Record<string, { total: number; count: number }>;
    };
    clients: {
        total_active: number;
        avg_health: number;
        health_distribution: { healthy: number; at_risk: number; critical: number };
        at_risk_clients: { id: number; name: string; slug: string; health_score: number }[];
        by_status: Record<string, number>;
    };
    operations: {
        task_completion_rate: number;
        completed_tasks_month: number;
        total_tasks_month: number;
        tasks_by_status: Record<string, number>;
        overdue_tasks: number;
        agent_success_rate: number;
        total_agent_runs: number;
        agent_costs_30d: number;
        active_projects: number;
        projects_by_status: Record<string, number>;
    };
    financial: {
        revenue_mtd: number;
        revenue_change_pct: number;
        outstanding_ar: number;
        outstanding_count: number;
        overdue_amount: number;
        overdue_count: number;
        avg_days_to_pay: number;
        amount_invoiced_mtd: number;
        invoices_issued_mtd: number;
        payments_last_month: number;
        payments_ytd: number;
        invoices_paid_mtd: number;
    };
    time_tracking: {
        hours_today: number;
        hours_yesterday: number;
        hours_this_week: number;
        hours_last_week: number;
        hours_mtd: number;
        hours_last_month: number;
        billable_hours: number;
        non_billable_hours: number;
        utilization_rate: number;
        billable_amount_mtd: number;
        unbilled_hours: number;
        unbilled_amount: number;
        top_clients: { client: string; slug: string | null; hours: number }[];
    };
    github: {
        open_issues: number;
        issues_closed_week: number;
        agent_tasks: number;
        prs_merged_week: number;
        open_prs: number;
        avg_pr_cycle_days: number;
        issues_by_label: Record<string, number>;
    };
    trends: {
        pipeline: number[];
        tasks: number[];
        agent_costs: number[];
        overdue: number[];
        revenue: number[];
        hours: number[];
    };
}

interface ActiveGoal {
    id: number;
    name: string;
    fiscal_year: number;
    revenue_target: number;
    revenue_actual: number;
    progress_percent: number;
    is_on_track: boolean;
    time_elapsed_percent: number;
}

interface CurrentPlan {
    id: number;
    week_label: string;
    status: string;
    focus_areas: string[];
    progress_percent: number;
    items_total: number;
    items_completed: number;
}

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
    kpis: KpiData;
    recentAgentRuns: AgentRun[];
    pendingApprovals: ApprovalRequest[];
    clients: Client[];
    activeGoal: ActiveGoal | null;
    currentPlan: CurrentPlan | null;
    agentCheckins: AgentCheckin[];
}>();

// Chart KPI Data
const chartKpis = ref<ChartKpis | null>(null);
const chartLoading = ref(true);

const fetchChartKpis = async () => {
    try {
        const response = await fetch('/api/kpis');
        chartKpis.value = await response.json();
    } catch (error) {
        console.error('Failed to load KPIs:', error);
    } finally {
        chartLoading.value = false;
    }
};

// Real-time updates: refresh KPIs when new leads come in
let notificationChannel: ReturnType<typeof echo.channel> | null = null;

onMounted(() => {
    fetchChartKpis();
    
    // Listen for lead_created notifications to auto-refresh dashboard KPIs
    if (echo) {
        notificationChannel = echo.channel('notifications')
            .listen('.notification.created', (notification: { type: string }) => {
                if (notification.type === 'lead_created') {
                    fetchChartKpis();
                }
            });
    }
});

onUnmounted(() => {
    if (notificationChannel) {
        echo.leave('notifications');
        notificationChannel = null;
    }
});

// Computed chart data
const pipelineFunnelData = computed(() => {
    if (!chartKpis.value) return [];
    return chartKpis.value.pipeline.funnel.map((f, i) => ({
        name: f.stage,
        value: f.count,
        color: ['#6366f1', '#8b5cf6', '#a78bfa', '#c4b5fd'][i],
    }));
});

const healthDistributionData = computed(() => {
    if (!chartKpis.value) return { data: [], labels: [] };
    const dist = chartKpis.value.clients.health_distribution;
    return {
        data: [dist.healthy, dist.at_risk, dist.critical],
        labels: ['Healthy', 'At Risk', 'Critical'],
    };
});

const winRateData = computed(() => {
    if (!chartKpis.value) return { data: [], labels: [] };
    return {
        data: [chartKpis.value.pipeline.won_count, chartKpis.value.pipeline.lost_count],
        labels: ['Won', 'Lost'],
    };
});

const tasksByStatusData = computed(() => {
    if (!chartKpis.value) return [];
    const statuses = chartKpis.value.operations.tasks_by_status;
    const colors: Record<string, string> = {
        pending: '#94a3b8',
        in_progress: '#6366f1',
        review: '#f59e0b',
        completed: '#22c55e',
    };
    return Object.entries(statuses).map(([status, count]) => ({
        name: status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()),
        value: count,
        color: colors[status] || '#6366f1',
    }));
});

// Time tracking utilization donut
const utilizationData = computed(() => {
    if (!chartKpis.value?.time_tracking) return { data: [], labels: [] };
    const tt = chartKpis.value.time_tracking;
    return {
        data: [tt.billable_hours, tt.non_billable_hours],
        labels: ['Billable', 'Non-Billable'],
    };
});

// Top clients by hours bar chart
const topClientsByHoursData = computed(() => {
    if (!chartKpis.value?.time_tracking?.top_clients) return [];
    return chartKpis.value.time_tracking.top_clients.map((c, i) => ({
        name: c.client.length > 12 ? c.client.substring(0, 12) + '...' : c.client,
        value: c.hours,
        color: ['#6366f1', '#8b5cf6', '#a78bfa', '#c4b5fd', '#ddd6fe'][i] || '#6366f1',
    }));
});

// GitHub issues by label
const issuesByLabelData = computed(() => {
    if (!chartKpis.value?.github?.issues_by_label) return [];
    const colors = ['#ef4444', '#f59e0b', '#22c55e', '#6366f1', '#8b5cf6'];
    return Object.entries(chartKpis.value.github.issues_by_label)
        .slice(0, 5)
        .map(([label, count], i) => ({
            name: label,
            value: count,
            color: colors[i] || '#6366f1',
        }));
});

// Approval Modal State
const showApprovalModal = ref(false);
const showReviewModal = ref(false);
const selectedApproval = ref<ApprovalRequest | null>(null);
const approvalMessage = ref('');
const isApproving = ref(false);

const openApprovalModal = (approval: ApprovalRequest) => {
    selectedApproval.value = approval;
    approvalMessage.value = '';
    showApprovalModal.value = true;
};

const openReviewModal = (approval: ApprovalRequest) => {
    selectedApproval.value = approval;
    showReviewModal.value = true;
};

const submitApproval = async (approved: boolean) => {
    if (!selectedApproval.value) return;
    isApproving.value = true;

    router.post(`/approvals/${selectedApproval.value.id}/${approved ? 'approve' : 'reject'}`, {
        message: approvalMessage.value,
    }, {
        onSuccess: () => {
            showApprovalModal.value = false;
            showReviewModal.value = false;
            selectedApproval.value = null;
        },
        onFinish: () => {
            isApproving.value = false;
        },
    });
};

// Insight Modal State
const showInsightModal = ref(false);
const insightType = ref<'upsell' | 'landing' | 'case_study' | null>(null);
const insightForm = reactive({
    client: '',
    tone: 'professional',
    focus: '',
    additionalContext: '',
    notifyOnComplete: true,
});
const isLaunchingAgent = ref(false);

const insightConfig = {
    upsell: {
        title: 'Draft Upsell Proposal',
        description: 'Generate a tailored upsell proposal based on detected signals',
        fields: ['client', 'tone', 'focus'],
    },
    landing: {
        title: 'Generate Landing Page',
        description: 'Create a targeted landing page for vertical focus',
        fields: ['tone', 'focus', 'additionalContext'],
    },
    case_study: {
        title: 'Draft Case Study',
        description: 'Generate a comprehensive case study from project data',
        fields: ['client', 'tone', 'focus'],
    },
};

const openInsightModal = (type: 'upsell' | 'landing' | 'case_study', context?: { client?: string }) => {
    insightType.value = type;
    insightForm.client = context?.client || '';
    insightForm.tone = 'professional';
    insightForm.focus = '';
    insightForm.additionalContext = '';
    insightForm.notifyOnComplete = true;
    showInsightModal.value = true;
};

// Handle actions from ProactiveInsights component
const handleInsightAction = (insight: { action: string; metadata: Record<string, unknown> }) => {
    const actionMap: Record<string, 'upsell' | 'landing' | 'case_study'> = {
        upsell: 'upsell',
        landing: 'landing',
        case_study: 'case_study',
        followup: 'upsell', // Use upsell modal for follow-up drafts
        review: 'upsell',   // Use upsell modal for review (can customize later)
    };

    const type = actionMap[insight.action] || 'upsell';
    const client = (insight.metadata?.client || insight.metadata?.client_slug) as string | undefined;

    openInsightModal(type, { client });
};

const launchInsightAgent = async () => {
    if (!insightType.value) return;
    isLaunchingAgent.value = true;

    router.post('/agents/launch', {
        type: insightType.value,
        ...insightForm,
    }, {
        onSuccess: () => {
            showInsightModal.value = false;
            insightType.value = null;
        },
        onFinish: () => {
            isLaunchingAgent.value = false;
        },
    });
};

const toneOptions = [
    { value: 'professional', label: 'Professional' },
    { value: 'friendly', label: 'Friendly' },
    { value: 'formal', label: 'Formal' },
    { value: 'casual', label: 'Casual' },
    { value: 'technical', label: 'Technical' },
];

const clientOptions = computed(() =>
    props.clients.map(c => ({ value: c.slug, label: c.name }))
);

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
    }).format(value);
};

const kpiCards = computed(() => [
    {
        label: 'REVENUE MTD',
        value: formatCurrency(props.kpis?.revenue_mtd ?? 0),
        change: `${(props.kpis?.revenue_change_pct ?? 0) >= 0 ? '+' : ''}${props.kpis?.revenue_change_pct ?? 0}%`,
        changeType: (props.kpis?.revenue_change_pct ?? 0) >= 0 ? 'positive' as const : 'negative' as const,
        href: '/goals',
    },
    {
        label: 'ACTIVE PROJECTS',
        value: String(props.kpis?.active_projects ?? 0),
        href: '/projects',
    },
    {
        label: 'CLIENT HEALTH',
        value: Number(props.kpis?.client_health_avg ?? 0).toFixed(1),
        suffix: '/10',
        href: '/clients',
    },
    {
        label: 'AGENTS ONLINE',
        value: `${props.kpis?.running_agents ?? 0}`,
        suffix: `/${props.kpis?.total_agents ?? 0}`,
        href: '/agents',
    },
]);

const getStatusClass = (status: string) => {
    const classes: Record<string, string> = {
        running: 'running',
        completed: 'completed',
        pending: 'pending',
        failed: 'failed',
        awaiting_approval: 'pending',
    };
    return classes[status] || 'pending';
};

const getBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        running: 'badge-green',
        completed: 'badge-blue',
        pending: 'badge-yellow',
        failed: 'badge-red',
        awaiting_approval: 'badge-yellow',
    };
    return classes[status] || 'badge-gray';
};

const getRiskBadge = (risk: string) => {
    const classes: Record<string, string> = {
        low: 'badge-green',
        medium: 'badge-yellow',
        high: 'badge-red',
        critical: 'badge-red',
    };
    return classes[risk] || 'badge-yellow';
};

const getHealthClass = (score: number | string) => {
    const numScore = typeof score === 'string' ? parseFloat(score) : score;
    if (numScore >= 8) return 'high';
    if (numScore >= 6) return 'medium';
    return 'low';
};

const formatScore = (score: number | string) => {
    const numScore = typeof score === 'string' ? parseFloat(score) : score;
    return numScore.toFixed(1);
};
</script>

<template>
    <AppLayout
        title="Command"
        :running-agents="kpis.running_agents"
        :total-agents="kpis.total_agents"
        :pending-approvals="kpis.pending_approvals"
    >
        <!-- Focus Panel - What needs your attention -->
        <FocusPanel />

        <!-- Strategic Goals & Weekly Plan Widgets -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4 mb-6 md:mb-8">
            <GoalProgressWidget :goal="activeGoal" />
            <WeeklyPlanWidget :plan="currentPlan" />
        </div>

        <!-- Time & Invoice Summary (Harvest-style) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4 mb-6 md:mb-8">
            <!-- Time Summary -->
            <div class="summary-card">
                <h3 class="summary-card-title">Time Summary</h3>
                <div class="summary-grid">
                    <div class="summary-item">
                        <span class="summary-label">Hours today</span>
                        <span class="summary-value">{{ chartKpis?.time_tracking?.hours_today?.toFixed(2) ?? '0.00' }}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Hours yesterday</span>
                        <span class="summary-value">{{ chartKpis?.time_tracking?.hours_yesterday?.toFixed(2) ?? '0.00' }}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Hours this week</span>
                        <span class="summary-value">{{ chartKpis?.time_tracking?.hours_this_week?.toFixed(2) ?? '0.00' }}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Hours last week</span>
                        <span class="summary-value">{{ chartKpis?.time_tracking?.hours_last_week?.toFixed(2) ?? '0.00' }}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Hours this month</span>
                        <span class="summary-value">{{ chartKpis?.time_tracking?.hours_mtd?.toFixed(2) ?? '0.00' }}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Hours last month</span>
                        <span class="summary-value">{{ chartKpis?.time_tracking?.hours_last_month?.toFixed(2) ?? '0.00' }}</span>
                    </div>
                </div>
                <div class="summary-links">
                    <Link href="/reports/time" class="summary-link">View time report</Link>
                </div>
            </div>

            <!-- Invoice Summary -->
            <div class="summary-card">
                <h3 class="summary-card-title">Invoice Summary</h3>
                <div class="summary-grid invoice-summary">
                    <div class="summary-item full-width">
                        <span class="summary-label">Amount outstanding</span>
                        <span class="summary-value outstanding">
                            {{ formatCurrency(chartKpis?.financial?.outstanding_ar ?? 0) }}
                            <span class="summary-count">({{ chartKpis?.financial?.outstanding_count ?? 0 }} invoices)</span>
                        </span>
                    </div>
                    <div class="summary-item full-width">
                        <span class="summary-label">Amount invoiced this month</span>
                        <span class="summary-value">
                            {{ formatCurrency(chartKpis?.financial?.amount_invoiced_mtd ?? 0) }}
                            <span class="summary-count">({{ chartKpis?.financial?.invoices_issued_mtd ?? 0 }} invoices)</span>
                        </span>
                    </div>
                    <div class="summary-item full-width">
                        <span class="summary-label">Payments received last month</span>
                        <span class="summary-value">{{ formatCurrency(chartKpis?.financial?.payments_last_month ?? 0) }}</span>
                    </div>
                    <div class="summary-item full-width">
                        <span class="summary-label">Payments received year-to-date</span>
                        <span class="summary-value">{{ formatCurrency(chartKpis?.financial?.payments_ytd ?? 0) }}</span>
                    </div>
                </div>
                <div class="summary-links">
                    <Link href="/invoices" class="summary-link">View invoices</Link>
                    <Link href="/reports/payments" class="summary-link">View payments report</Link>
                </div>
            </div>
        </div>

        <!-- KPI Charts Grid -->
        <div class="kpi-charts-section mb-6 md:mb-8">
            <div class="section-header mb-3 md:mb-4">
                <ChartIcon :size="18" class="section-icon" />
                <span class="section-title">Performance Analytics</span>
            </div>

            <!-- Row 1: Pipeline & Revenue -->
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 md:gap-4 xl:grid-cols-4 mb-3 md:mb-4">
                <ChartCard
                    title="Pipeline Value"
                    :value="chartKpis?.pipeline?.total_pipeline != null ? `$${(chartKpis.pipeline.total_pipeline / 1000).toFixed(0)}k` : '-'"
                    subtitle="weighted value"
                    :loading="chartLoading"
                >
                    <RadialGauge
                        v-if="chartKpis"
                        :value="chartKpis.pipeline.weighted_pipeline"
                        :max="chartKpis.pipeline.total_pipeline || 1"
                        color="#6366f1"
                        :height="100"
                    />
                </ChartCard>

                <ChartCard
                    title="Win Rate"
                    :value="chartKpis ? `${chartKpis.pipeline.win_rate}%` : '-'"
                    subtitle="closed deals"
                    :loading="chartLoading"
                >
                    <DonutChart
                        v-if="chartKpis"
                        :data="winRateData.data"
                        :labels="winRateData.labels"
                        :colors="['#22c55e', '#ef4444']"
                        :height="100"
                        :show-legend="true"
                    />
                </ChartCard>

                <ChartCard
                    title="Won This Month"
                    :value="chartKpis?.pipeline?.won_value_mtd != null ? `$${(chartKpis.pipeline.won_value_mtd / 1000).toFixed(0)}k` : '-'"
                    subtitle="14-day trend"
                    :loading="chartLoading"
                >
                    <AreaChart
                        v-if="chartKpis"
                        :data="chartKpis.trends.pipeline"
                        color="#22c55e"
                        :height="56"
                    />
                </ChartCard>

                <ChartCard
                    title="Lead Funnel"
                    :value="chartKpis ? pipelineFunnelData.reduce((a, b) => a + b.value, 0) : '-'"
                    subtitle="by stage"
                    :loading="chartLoading"
                >
                    <BarChart
                        v-if="chartKpis && pipelineFunnelData.length"
                        :data="pipelineFunnelData"
                        :height="90"
                        :horizontal="true"
                        :show-labels="true"
                    />
                </ChartCard>
            </div>

            <!-- Row 2: Client Health & Operations -->
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 md:gap-4 xl:grid-cols-4">
                <ChartCard
                    title="Client Health"
                    :value="chartKpis?.clients?.avg_health != null ? chartKpis.clients.avg_health.toFixed(1) : '-'"
                    subtitle="avg score"
                    :loading="chartLoading"
                >
                    <DonutChart
                        v-if="chartKpis"
                        :data="healthDistributionData.data"
                        :labels="healthDistributionData.labels"
                        :colors="['#22c55e', '#f59e0b', '#ef4444']"
                        :height="100"
                        :show-legend="true"
                    />
                </ChartCard>

                <ChartCard
                    title="Task Completion"
                    :value="chartKpis ? `${chartKpis.operations.task_completion_rate}%` : '-'"
                    :subtitle="chartKpis ? `${chartKpis.operations.completed_tasks_month} of ${chartKpis.operations.total_tasks_month}` : ''"
                    :loading="chartLoading"
                >
                    <RadialGauge
                        v-if="chartKpis"
                        :value="chartKpis.operations.task_completion_rate"
                        :max="100"
                        :height="100"
                    />
                </ChartCard>

                <ChartCard
                    title="Tasks"
                    :value="chartKpis ? Object.values(chartKpis.operations.tasks_by_status).reduce((a, b) => a + b, 0) : '-'"
                    subtitle="by status"
                    :loading="chartLoading"
                >
                    <BarChart
                        v-if="chartKpis && tasksByStatusData.length"
                        :data="tasksByStatusData"
                        :height="90"
                        :horizontal="false"
                        :show-labels="true"
                    />
                </ChartCard>

                <ChartCard
                    title="Agent Costs"
                    :value="chartKpis?.operations?.agent_costs_30d != null ? `$${chartKpis.operations.agent_costs_30d.toFixed(2)}` : '-'"
                    subtitle="30-day spend"
                    :trend="chartKpis?.operations?.agent_success_rate"
                    :loading="chartLoading"
                >
                    <AreaChart
                        v-if="chartKpis"
                        :data="chartKpis.trends.agent_costs"
                        color="#8b5cf6"
                        :height="56"
                    />
                </ChartCard>
            </div>

            <!-- Row 3: Financial & Time Tracking -->
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 md:gap-4 xl:grid-cols-4 mb-3 md:mb-4">
                <ChartCard
                    title="Revenue MTD"
                    :value="chartKpis?.financial?.revenue_mtd != null ? `$${(chartKpis.financial.revenue_mtd / 1000).toFixed(0)}k` : '-'"
                    :subtitle="chartKpis?.financial?.revenue_change_pct != null ? `${chartKpis.financial.revenue_change_pct > 0 ? '+' : ''}${chartKpis.financial.revenue_change_pct}% vs last month` : 'from invoices'"
                    :loading="chartLoading"
                >
                    <AreaChart
                        v-if="chartKpis?.trends?.revenue"
                        :data="chartKpis.trends.revenue"
                        color="#22c55e"
                        :height="56"
                    />
                </ChartCard>

                <ChartCard
                    title="Outstanding AR"
                    :value="chartKpis?.financial?.outstanding_ar != null ? `$${(chartKpis.financial.outstanding_ar / 1000).toFixed(1)}k` : '-'"
                    :subtitle="chartKpis?.financial?.overdue_count ? `${chartKpis.financial.overdue_count} overdue` : 'open invoices'"
                    :loading="chartLoading"
                >
                    <RadialGauge
                        v-if="chartKpis?.financial"
                        :value="chartKpis.financial.outstanding_ar - chartKpis.financial.overdue_amount"
                        :max="chartKpis.financial.outstanding_ar || 1"
                        color="#f59e0b"
                        :height="100"
                    />
                </ChartCard>

                <ChartCard
                    title="Utilization"
                    :value="chartKpis?.time_tracking ? `${chartKpis.time_tracking.utilization_rate}%` : '-'"
                    :subtitle="chartKpis?.time_tracking ? `${chartKpis.time_tracking.billable_hours}h billable` : 'billable rate'"
                    :loading="chartLoading"
                >
                    <DonutChart
                        v-if="chartKpis?.time_tracking && utilizationData.data.length"
                        :data="utilizationData.data"
                        :labels="utilizationData.labels"
                        :colors="['#22c55e', '#94a3b8']"
                        :height="100"
                        :show-legend="true"
                    />
                </ChartCard>

                <ChartCard
                    title="Hours MTD"
                    :value="chartKpis?.time_tracking ? `${chartKpis.time_tracking.hours_mtd}h` : '-'"
                    :subtitle="chartKpis?.time_tracking ? `${chartKpis.time_tracking.hours_this_week}h this week` : '14-day trend'"
                    :loading="chartLoading"
                >
                    <AreaChart
                        v-if="chartKpis?.trends?.hours"
                        :data="chartKpis.trends.hours"
                        color="#6366f1"
                        :height="56"
                    />
                </ChartCard>
            </div>

            <!-- Row 4: GitHub & Engineering -->
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 md:gap-4 xl:grid-cols-4">
                <ChartCard
                    title="Open Issues"
                    :value="chartKpis?.github ? chartKpis.github.open_issues : '-'"
                    :subtitle="chartKpis?.github ? `${chartKpis.github.issues_closed_week} closed this week` : 'GitHub issues'"
                    :loading="chartLoading"
                >
                    <BarChart
                        v-if="chartKpis?.github && issuesByLabelData.length"
                        :data="issuesByLabelData"
                        :height="90"
                        :horizontal="true"
                        :show-labels="true"
                    />
                </ChartCard>

                <ChartCard
                    title="PR Velocity"
                    :value="chartKpis?.github ? chartKpis.github.prs_merged_week : '-'"
                    subtitle="merged this week"
                    :loading="chartLoading"
                >
                    <div v-if="chartKpis?.github" class="flex items-center justify-center h-[100px]">
                        <div class="text-center">
                            <div class="text-3xl font-bold" style="color: var(--color-accent)">
                                {{ chartKpis.github.open_prs }}
                            </div>
                            <div class="text-xs" style="color: var(--color-text-tertiary)">open PRs</div>
                        </div>
                    </div>
                </ChartCard>

                <ChartCard
                    title="PR Cycle Time"
                    :value="chartKpis?.github ? `${chartKpis.github.avg_pr_cycle_days}d` : '-'"
                    subtitle="avg days to merge"
                    :loading="chartLoading"
                >
                    <RadialGauge
                        v-if="chartKpis?.github"
                        :value="Math.max(0, 7 - chartKpis.github.avg_pr_cycle_days)"
                        :max="7"
                        color="#22c55e"
                        :height="100"
                    />
                </ChartCard>

                <ChartCard
                    title="Agent Tasks"
                    :value="chartKpis?.github ? chartKpis.github.agent_tasks : '-'"
                    subtitle="labeled for automation"
                    :loading="chartLoading"
                >
                    <div v-if="chartKpis?.github" class="flex items-center justify-center h-[100px]">
                        <div class="text-center">
                            <div class="text-3xl font-bold" style="color: var(--color-status-green)">
                                {{ chartKpis.github.agent_tasks > 0 ? '🤖' : '✓' }}
                            </div>
                            <div class="text-xs" style="color: var(--color-text-tertiary)">
                                {{ chartKpis.github.agent_tasks > 0 ? 'ready for agents' : 'all clear' }}
                            </div>
                        </div>
                    </div>
                </ChartCard>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="grid grid-cols-2 gap-3 md:gap-4 xl:grid-cols-4 mb-4 md:mb-6">
            <Link
                v-for="kpi in kpiCards"
                :key="kpi.label"
                :href="kpi.href"
                class="metric-card hover:border-[var(--color-border-default)] transition-colors"
            >
                <div class="metric-label">{{ kpi.label }}</div>
                <div class="flex items-baseline gap-1">
                    <span class="metric-value">{{ kpi.value }}</span>
                    <span v-if="kpi.suffix" class="metric-suffix">{{ kpi.suffix }}</span>
                    <span
                        v-if="kpi.change"
                        :class="['metric-change', kpi.changeType]"
                    >
                        {{ kpi.change }}
                    </span>
                </div>
            </Link>
        </div>

        <div class="grid grid-cols-1 gap-4 md:gap-6 xl:grid-cols-3">
            <!-- Agent Activity Feed + Proactive Insights -->
            <div class="xl:col-span-2 space-y-6">
                <div class="card">
                    <div class="card-header">
                        <div class="flex items-center gap-2">
                            <div class="status-dot running"></div>
                            <span class="card-title">Agent Activity</span>
                        </div>
                        <span class="text-caption text-mono">
                            {{ kpis.running_agents }} active
                        </span>
                    </div>

                    <div class="card-body">
                        <Link
                            v-for="run in recentAgentRuns"
                            :key="run.id"
                            :href="`/agents/${run.agent_slug}/runs/${run.id}`"
                            class="list-item"
                        >
                            <div :class="['status-dot', getStatusClass(run.status)]"></div>

                            <div class="list-item-content">
                                <div class="list-item-title">
                                    {{ run.agent_name }}
                                    <span class="text-mono text-caption ml-2">{{ run.agent_slug }}</span>
                                </div>
                                <div class="list-item-subtitle">{{ run.trigger }}</div>
                            </div>

                            <div class="list-item-meta">
                                <span :class="['badge', getBadgeClass(run.status)]">
                                    {{ run.status.replace('_', ' ') }}
                                </span>
                                <div class="text-caption text-mono mt-1">{{ run.created_at }}</div>
                            </div>
                        </Link>

                        <div v-if="recentAgentRuns.length === 0" class="p-8 text-center">
                            <div class="avatar avatar-md avatar-muted mx-auto mb-3">
                                <CpuIcon :size="16" />
                            </div>
                            <p class="text-body">No agent activity yet</p>
                            <p class="text-caption mt-1">Agents will appear here when they start running</p>
                        </div>
                    </div>
                </div>

                <!-- Proactive Insights -->
                <ProactiveInsights @action="handleInsightAction" />
            </div>

            <!-- Right Column -->
            <div class="space-y-6">
                <!-- Agent Checkins (Business Strategist, etc) -->
                <AgentCheckinsWidget :checkins="agentCheckins" />

                <!-- Quick Agent Triggers -->
                <AgentQuickTrigger />

                <!-- Pending Approvals -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Pending Approvals</span>
                        <span
                            v-if="pendingApprovals.length > 0"
                            class="count-badge"
                        >
                            {{ pendingApprovals.length }}
                        </span>
                    </div>

                    <div class="card-body">
                        <div
                            v-for="approval in pendingApprovals"
                            :key="approval.id"
                            class="approval-item"
                        >
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <span class="approval-type">{{ approval.action_type }}</span>
                                <span :class="['badge', getRiskBadge(approval.risk_level)]">{{ approval.risk_level }}</span>
                            </div>

                            <p class="approval-summary">{{ approval.summary }}</p>
                            <p class="approval-meta">{{ approval.agent_name }} · {{ approval.created_at }}</p>

                            <div class="approval-actions">
                                <button
                                    class="btn btn-success flex-1"
                                    @click="openApprovalModal(approval)"
                                >
                                    Approve
                                </button>
                                <button
                                    class="btn btn-secondary flex-1"
                                    @click="openReviewModal(approval)"
                                >
                                    Review
                                </button>
                            </div>
                        </div>

                        <div v-if="pendingApprovals.length === 0" class="p-6 text-center">
                            <div class="avatar avatar-md mx-auto mb-3" style="background: rgba(34, 197, 94, 0.12)">
                                <CheckIcon :size="16" style="color: var(--color-status-green)" />
                            </div>
                            <p class="text-body">All clear</p>
                            <p class="text-caption mt-1">No pending approvals</p>
                        </div>
                    </div>
                </div>

                <!-- Client Health -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Client Health</span>
                    </div>

                    <div class="card-body">
                        <Link
                            v-for="client in clients"
                            :key="client.id"
                            :href="`/clients/${client.slug}`"
                            class="health-item"
                        >
                            <div class="health-client">
                                <div class="avatar avatar-sm avatar-muted">
                                    {{ (client.name || '??').split(' ').filter(w => /^[a-zA-Z]/.test(w)).map(w => w[0]).join('').slice(0, 2).toUpperCase() || '??' }}
                                </div>
                                <span class="text-body" style="color: var(--color-text-primary)">{{ client.name }}</span>
                            </div>
                            <span :class="['health-score', getHealthClass(client.health_score)]">
                                {{ formatScore(client.health_score) }}
                            </span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approval Confirmation Modal -->
        <Modal
            :show="showApprovalModal"
            title="Confirm Approval"
            size="md"
            @close="showApprovalModal = false"
        >
            <div v-if="selectedApproval" class="space-y-4">
                <div class="approval-detail-card">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <span class="text-mono" style="color: var(--color-text-primary)">{{ selectedApproval.action_type }}</span>
                            <p class="text-caption mt-1">Requested by {{ selectedApproval.agent_name }}</p>
                        </div>
                        <span :class="['badge', getRiskBadge(selectedApproval.risk_level)]">
                            {{ selectedApproval.risk_level }} risk
                        </span>
                    </div>
                    <p class="text-body">{{ selectedApproval.summary }}</p>
                </div>

                <div class="warning-box" v-if="selectedApproval.risk_level === 'high' || selectedApproval.risk_level === 'critical'">
                    <WarningIcon :size="20" style="color: var(--color-status-yellow)" />
                    <div>
                        <p class="text-body" style="color: var(--color-status-yellow)">High-risk action</p>
                        <p class="text-caption">Please review carefully before approving.</p>
                    </div>
                </div>

                <FormTextarea
                    v-model="approvalMessage"
                    label="Message (optional)"
                    placeholder="Add a note about this approval..."
                    :rows="3"
                />
            </div>

            <template #footer>
                <button class="btn btn-secondary" @click="showApprovalModal = false">
                    Cancel
                </button>
                <button
                    class="btn btn-success"
                    :disabled="isApproving"
                    @click="submitApproval(true)"
                >
                    {{ isApproving ? 'Approving...' : 'Approve Action' }}
                </button>
            </template>
        </Modal>

        <!-- Review Modal -->
        <Modal
            :show="showReviewModal"
            title="Review Request"
            size="lg"
            @close="showReviewModal = false"
        >
            <div v-if="selectedApproval" class="space-y-5">
                <!-- Header -->
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-heading">{{ selectedApproval.action_type }}</h3>
                        <p class="text-caption mt-1">{{ selectedApproval.agent_name }} · {{ selectedApproval.created_at }}</p>
                    </div>
                    <span :class="['badge', getRiskBadge(selectedApproval.risk_level)]">
                        {{ selectedApproval.risk_level }} risk
                    </span>
                </div>

                <!-- Summary -->
                <div class="review-section">
                    <h4 class="review-section-title">Summary</h4>
                    <p class="text-body">{{ selectedApproval.summary }}</p>
                </div>

                <!-- Context -->
                <div class="review-section">
                    <h4 class="review-section-title">Context</h4>
                    <div class="context-box">
                        <p class="text-body">{{ selectedApproval.context || 'No additional context provided.' }}</p>
                    </div>
                </div>

                <!-- Payload Preview -->
                <div class="review-section" v-if="selectedApproval.payload">
                    <h4 class="review-section-title">Payload Preview</h4>
                    <pre class="code-preview">{{ JSON.stringify(selectedApproval.payload, null, 2) }}</pre>
                </div>

                <!-- Timeline -->
                <div class="review-section">
                    <h4 class="review-section-title">Timeline</h4>
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <div class="timeline-content">
                                <p class="text-body">Request created</p>
                                <p class="text-caption">{{ selectedApproval.created_at }}</p>
                            </div>
                        </div>
                        <div class="timeline-item" v-if="selectedApproval.expires_at">
                            <div class="timeline-dot warning"></div>
                            <div class="timeline-content">
                                <p class="text-body">Expires</p>
                                <p class="text-caption">{{ selectedApproval.expires_at }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Message -->
                <FormTextarea
                    v-model="approvalMessage"
                    label="Response message"
                    placeholder="Add notes or feedback..."
                    :rows="3"
                />
            </div>

            <template #footer>
                <button
                    class="btn btn-ghost"
                    :disabled="isApproving"
                    @click="submitApproval(false)"
                >
                    {{ isApproving ? 'Processing...' : 'Reject' }}
                </button>
                <button class="btn btn-secondary" @click="showReviewModal = false">
                    Close
                </button>
                <button
                    class="btn btn-success"
                    :disabled="isApproving"
                    @click="submitApproval(true)"
                >
                    {{ isApproving ? 'Approving...' : 'Approve' }}
                </button>
            </template>
        </Modal>

        <!-- Insight Agent Modal -->
        <Modal
            :show="showInsightModal"
            :title="insightType ? insightConfig[insightType].title : ''"
            size="md"
            @close="showInsightModal = false"
        >
            <div v-if="insightType" class="space-y-4">
                <p class="text-body">{{ insightConfig[insightType].description }}</p>

                <FormSelect
                    v-if="insightConfig[insightType].fields.includes('client')"
                    v-model="insightForm.client"
                    label="Client"
                    :options="clientOptions"
                    placeholder="Select a client"
                    required
                />

                <FormSelect
                    v-if="insightConfig[insightType].fields.includes('tone')"
                    v-model="insightForm.tone"
                    label="Tone"
                    :options="toneOptions"
                    required
                />

                <FormInput
                    v-if="insightConfig[insightType].fields.includes('focus')"
                    v-model="insightForm.focus"
                    label="Focus Area"
                    placeholder="e.g., Cost savings, Performance improvements..."
                    hint="What should the content emphasize?"
                />

                <FormTextarea
                    v-if="insightConfig[insightType].fields.includes('additionalContext')"
                    v-model="insightForm.additionalContext"
                    label="Additional Context"
                    placeholder="Any specific details or requirements..."
                    :rows="3"
                />

                <FormToggle
                    v-model="insightForm.notifyOnComplete"
                    label="Notify when complete"
                    description="Send a notification when the agent finishes"
                />

                <div class="agent-preview">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="status-dot running"></div>
                        <span class="text-mono text-caption">Agent will be launched</span>
                    </div>
                    <p class="text-caption">
                        This will start a background agent to generate your content.
                        You can track progress in the Agent Activity feed.
                    </p>
                </div>
            </div>

            <template #footer>
                <button class="btn btn-secondary" @click="showInsightModal = false">
                    Cancel
                </button>
                <button
                    class="btn btn-primary"
                    :disabled="isLaunchingAgent"
                    @click="launchInsightAgent"
                >
                    <PlayIcon :size="16" />
                    {{ isLaunchingAgent ? 'Launching...' : 'Launch Agent' }}
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.approval-detail-card {
    padding: 1rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    border: 1px solid var(--color-border-subtle);
}

.warning-box {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem;
    background: rgba(234, 179, 8, 0.08);
    border: 1px solid rgba(234, 179, 8, 0.2);
    border-radius: 8px;
}

.review-section {
    padding-top: 1rem;
    border-top: 1px solid var(--color-border-subtle);
}

.review-section:first-child {
    padding-top: 0;
    border-top: none;
}

.review-section-title {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin-bottom: 0.75rem;
}

.context-box {
    padding: 1rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    border: 1px solid var(--color-border-subtle);
}

.code-preview {
    padding: 1rem;
    background: var(--color-bg-primary);
    border-radius: 8px;
    border: 1px solid var(--color-border-subtle);
    font-family: var(--font-mono);
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
}

.timeline {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.timeline-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.timeline-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--color-status-green);
    margin-top: 6px;
    flex-shrink: 0;
}

.timeline-dot.warning {
    background: var(--color-status-yellow);
}

.timeline-content {
    flex: 1;
}

.agent-preview {
    padding: 1rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    border: 1px dashed var(--color-border-default);
}

/* Harvest-style Summary Cards */
.summary-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
}

.summary-card-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.summary-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem 2rem;
}

.summary-grid.invoice-summary {
    grid-template-columns: 1fr;
    gap: 0.875rem;
}

.summary-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.summary-item.full-width {
    grid-column: span 2;
}

.summary-label {
    font-size: 0.8125rem;
    color: var(--color-accent);
    font-weight: 500;
}

.summary-value {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
}

.summary-value.outstanding {
    color: var(--color-status-red);
}

.summary-count {
    font-size: 0.875rem;
    font-weight: 400;
    color: var(--color-text-tertiary);
    margin-left: 0.5rem;
}

.summary-links {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--color-border-subtle);
}

.summary-link {
    font-size: 0.8125rem;
    color: var(--color-accent);
    text-decoration: none;
    font-weight: 500;
}

.summary-link:hover {
    text-decoration: underline;
}
</style>
