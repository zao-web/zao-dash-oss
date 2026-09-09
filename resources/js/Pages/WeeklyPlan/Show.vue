<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { ref, computed } from 'vue';

interface AgentTask {
    id: number;
    status: string;
    scheduled_for: string | null;
    completed_at: string | null;
}

interface PlanItem {
    id: number;
    action: string;
    owner_type: 'human' | 'agent';
    agent_slug: string | null;
    priority: string;
    due_day: string | null;
    due_date: string | null;
    success_metric: string | null;
    expected_outcome: Record<string, unknown> | null;
    status: string;
    result: Record<string, unknown> | null;
    is_overdue: boolean;
    agent_task: AgentTask | null;
}

interface RevisionEntry {
    feedback: string;
    requested_by: string;
    requested_at: string;
}

interface Plan {
    id: number;
    week_starting: string;
    week_ending: string;
    week_label: string;
    is_current_week: boolean;
    status: string;
    focus_areas: string[];
    targets: Record<string, number>;
    strategy_notes: string | null;
    week_results: Record<string, unknown> | null;
    progress_percent: number;
    approved_at: string | null;
    approved_by: string | null;
    created_by: string | null;
    goal: { id: number; name: string; revenue_target: number } | null;
    // Rejection/Revision fields
    rejected_by: string | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    revision_history: RevisionEntry[] | null;
    revision_feedback: string | null;
}

interface Stats {
    total: number;
    completed: number;
    in_progress: number;
    pending: number;
    skipped: number;
    agent_items: number;
    human_items: number;
}

const props = defineProps<{
    plan: Plan;
    items: PlanItem[];
    stats: Stats;
}>();

const filter = ref<'all' | 'human' | 'agent'>('all');

// Modal state
const showRejectModal = ref(false);
const showRevisionModal = ref(false);

// Forms
const rejectForm = useForm({
    reason: '',
});

const revisionForm = useForm({
    feedback: '',
});

// Can show reject/revision buttons
const canRejectOrRevise = computed(() => {
    return props.plan.status === 'draft' || props.plan.status === 'revision_requested';
});

const filteredItems = computed(() => {
    if (filter.value === 'all') return props.items;
    return props.items.filter(item => item.owner_type === filter.value);
});

const humanItems = computed(() => props.items.filter(i => i.owner_type === 'human'));
const agentItems = computed(() => props.items.filter(i => i.owner_type === 'agent'));

const getStatusBadge = (status: string) => {
    const badges: Record<string, { class: string; text: string }> = {
        draft: { class: 'bg-zinc-500/20 text-zinc-400', text: 'Draft' },
        approved: { class: 'bg-blue-500/20 text-blue-400', text: 'Approved' },
        active: { class: 'bg-emerald-500/20 text-emerald-400', text: 'Active' },
        completed: { class: 'bg-purple-500/20 text-purple-400', text: 'Completed' },
        rejected: { class: 'bg-red-500/20 text-red-400', text: 'Rejected' },
        revision_requested: { class: 'bg-amber-500/20 text-amber-400', text: 'Revision Requested' },
    };
    return badges[status] || badges.draft;
};

const getPriorityBadge = (priority: string) => {
    const badges: Record<string, { class: string; label: string }> = {
        critical: { class: 'bg-red-500/20 text-red-400 border-red-500/30', label: '🔴 Critical' },
        high: { class: 'bg-orange-500/20 text-orange-400 border-orange-500/30', label: '🟠 High' },
        medium: { class: 'bg-blue-500/20 text-blue-400 border-blue-500/30', label: '🔵 Medium' },
        low: { class: 'bg-zinc-500/20 text-zinc-400 border-zinc-500/30', label: '⚪ Low' },
    };
    return badges[priority] || badges.medium;
};

const getItemStatusBadge = (status: string, isOverdue: boolean) => {
    if (isOverdue) return { class: 'bg-red-500/20 text-red-400', text: 'Overdue' };
    const badges: Record<string, { class: string; text: string }> = {
        pending: { class: 'bg-zinc-500/20 text-zinc-400', text: 'Pending' },
        in_progress: { class: 'bg-blue-500/20 text-blue-400', text: 'In Progress' },
        completed: { class: 'bg-emerald-500/20 text-emerald-400', text: 'Completed' },
        skipped: { class: 'bg-amber-500/20 text-amber-400', text: 'Skipped' },
    };
    return badges[status] || badges.pending;
};

const getTaskStatusBadge = (status: string) => {
    const badges: Record<string, { class: string; text: string }> = {
        pending: { class: 'bg-zinc-500/20 text-zinc-400', text: 'Queued' },
        scheduled: { class: 'bg-purple-500/20 text-purple-400', text: 'Scheduled' },
        running: { class: 'bg-blue-500/20 text-blue-400', text: 'Running' },
        completed: { class: 'bg-emerald-500/20 text-emerald-400', text: 'Done' },
        failed: { class: 'bg-red-500/20 text-red-400', text: 'Failed' },
    };
    return badges[status] || badges.pending;
};

const focusAreaLabels: Record<string, string> = {
    lead_gen: '🎯 Lead Generation',
    closing: '🤝 Deal Closing',
    upsells: '📈 Upsells',
    retention: '💚 Client Retention',
    operations: '⚙️ Operations',
    content: '✍️ Content',
};

const getFocusLabel = (area: string) => focusAreaLabels[area] || area;

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
    }).format(value);
};

// Convert snake_case or underscore keys to Title Case
const formatLabel = (key: string) => {
    return key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, l => l.toUpperCase());
};

// Check if a key represents a monetary value
const isMonetaryKey = (key: string) => {
    const monetaryKeys = ['revenue', 'target', 'pipeline', 'deal', 'budget', 'cost', 'value', 'amount'];
    const lowerKey = key.toLowerCase();
    return monetaryKeys.some(mk => lowerKey.includes(mk));
};

// Format target value based on key type
const formatTargetValue = (key: string, value: number) => {
    if (isMonetaryKey(key)) {
        return formatCurrency(value);
    }
    return new Intl.NumberFormat('en-US').format(value);
};

const updateItemStatus = (itemId: number, status: string) => {
    router.patch(`/weekly-plans/items/${itemId}`, { status }, {
        preserveScroll: true,
    });
};

const approvePlan = () => {
    router.post(`/weekly-plans/${props.plan.id}/approve`, {}, {
        preserveScroll: true,
    });
};

const activatePlan = () => {
    router.post(`/weekly-plans/${props.plan.id}/activate`, {}, {
        preserveScroll: true,
    });
};

const rejectPlan = () => {
    rejectForm.post(`/weekly-plans/${props.plan.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            showRejectModal.value = false;
            rejectForm.reset();
        },
    });
};

const requestRevision = () => {
    revisionForm.post(`/weekly-plans/${props.plan.id}/request-revision`, {
        preserveScroll: true,
        onSuccess: () => {
            showRevisionModal.value = false;
            revisionForm.reset();
        },
    });
};
</script>

<template>
    <AppLayout>
        <Head :title="`Plan: ${plan.week_label}`" />

        <div class="p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <Link href="/weekly-plans" class="text-[var(--color-text-tertiary)] hover:text-[var(--color-text-secondary)]">
                            ← Weekly Plans
                        </Link>
                    </div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold text-[var(--color-text-primary)]">{{ plan.week_label }}</h1>
                        <span
                            v-if="plan.is_current_week"
                            class="px-2 py-0.5 bg-blue-500/20 text-blue-400 rounded text-xs"
                        >
                            Current Week
                        </span>
                    </div>
                    <p class="text-[var(--color-text-tertiary)] text-sm mt-1">
                        {{ plan.week_starting }} to {{ plan.week_ending }}
                        <span v-if="plan.goal">
                            • Linked to <Link :href="`/goals/${plan.goal.id}`" class="text-blue-400 hover:underline">{{ plan.goal.name }}</Link>
                        </span>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        v-if="canRejectOrRevise"
                        @click="showRevisionModal = true"
                        class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded-lg transition-colors"
                    >
                        Request Revision
                    </button>
                    <button
                        v-if="canRejectOrRevise"
                        @click="showRejectModal = true"
                        class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white rounded-lg transition-colors"
                    >
                        Reject
                    </button>
                    <button
                        v-if="plan.status === 'draft'"
                        @click="approvePlan"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-lg transition-colors"
                    >
                        Approve Plan
                    </button>
                    <button
                        v-if="plan.status === 'approved'"
                        @click="activatePlan"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg transition-colors"
                    >
                        Activate
                    </button>
                    <span :class="[getStatusBadge(plan.status).class, 'px-3 py-1 rounded-full text-sm font-medium']">
                        {{ getStatusBadge(plan.status).text }}
                    </span>
                </div>
            </div>

            <!-- Rejection Alert -->
            <div v-if="plan.status === 'rejected'" class="bg-red-500/10 border border-red-500/30 rounded-xl p-4">
                <div class="flex items-start gap-3">
                    <div class="text-red-400 text-xl">❌</div>
                    <div>
                        <h3 class="text-red-400 font-medium">Plan Rejected</h3>
                        <p class="text-[var(--color-text-secondary)] mt-1">{{ plan.rejection_reason }}</p>
                        <p v-if="plan.rejected_by || plan.rejected_at" class="text-[var(--color-text-tertiary)] text-xs mt-2">
                            <span v-if="plan.rejected_by">Rejected by {{ plan.rejected_by }}</span>
                            <span v-if="plan.rejected_by && plan.rejected_at"> on </span>
                            <span v-if="plan.rejected_at">{{ new Date(plan.rejected_at).toLocaleDateString() }}</span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Revision Requested Alert -->
            <div v-if="plan.status === 'revision_requested'" class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4">
                <div class="flex items-start gap-3">
                    <div class="text-amber-400 text-xl">✏️</div>
                    <div>
                        <h3 class="text-amber-400 font-medium">Revision Requested</h3>
                        <p class="text-[var(--color-text-secondary)] mt-1">{{ plan.revision_feedback }}</p>
                        <p class="text-[var(--color-text-tertiary)] text-xs mt-2">
                            The Business Strategist agent will generate a revised plan.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Revision History -->
            <div v-if="plan.revision_history && plan.revision_history.length" class="card p-4">
                <h3 class="text-sm font-medium text-[var(--color-text-tertiary)] uppercase mb-3">Revision History</h3>
                <div class="space-y-3">
                    <div
                        v-for="(revision, index) in plan.revision_history"
                        :key="index"
                        class="p-3 bg-[var(--color-bg-tertiary)] rounded-lg"
                    >
                        <p class="text-[var(--color-text-secondary)] text-sm">{{ revision.feedback }}</p>
                        <p class="text-[var(--color-text-tertiary)] text-xs mt-1">
                            Requested by {{ revision.requested_by }} on {{ new Date(revision.requested_at).toLocaleDateString() }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Stats Hero -->
            <div class="hero-gradient border border-[var(--color-border-subtle)] rounded-xl p-8 shadow-sm relative overflow-hidden">
                <!-- Decorative background accent -->
                <div class="absolute top-0 right-0 w-64 h-64 bg-blue-400/5 rounded-full blur-3xl -mr-32 -mt-32 pointer-events-none"></div>

                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-8 relative">
                    <div class="flex flex-col">
                        <div class="text-xs font-medium text-[var(--color-text-tertiary)] uppercase tracking-wider mb-2">Total Items</div>
                        <div class="text-3xl font-bold text-[var(--color-text-primary)] tracking-tight">{{ stats.total }}</div>
                    </div>
                    <div class="flex flex-col">
                        <div class="text-xs font-medium text-[var(--color-text-tertiary)] uppercase tracking-wider mb-2">Completed</div>
                        <div class="text-3xl font-bold text-emerald-500 tracking-tight">{{ stats.completed }}</div>
                    </div>
                    <div class="flex flex-col">
                        <div class="text-xs font-medium text-[var(--color-text-tertiary)] uppercase tracking-wider mb-2">In Progress</div>
                        <div class="text-3xl font-bold text-blue-500 tracking-tight">{{ stats.in_progress }}</div>
                    </div>
                    <div class="flex flex-col">
                        <div class="text-xs font-medium text-[var(--color-text-tertiary)] uppercase tracking-wider mb-2">Pending</div>
                        <div class="text-3xl font-bold text-[var(--color-text-tertiary)] tracking-tight">{{ stats.pending }}</div>
                    </div>
                    <div class="flex flex-col">
                        <div class="text-xs font-medium text-[var(--color-text-tertiary)] uppercase tracking-wider mb-2">Agent Tasks</div>
                        <div class="text-3xl font-bold text-purple-500 tracking-tight">{{ stats.agent_items }}</div>
                    </div>
                    <div class="flex flex-col">
                        <div class="text-xs font-medium text-[var(--color-text-tertiary)] uppercase tracking-wider mb-2">Progress</div>
                        <div class="text-3xl font-bold text-[var(--color-text-primary)] tracking-tight">{{ plan.progress_percent.toFixed(0) }}%</div>
                    </div>
                </div>
            </div>

            <!-- Focus & Strategy -->
            <div class="grid grid-cols-2 gap-6">
                <div class="card p-6">
                    <h3 class="text-sm font-medium text-[var(--color-text-tertiary)] uppercase mb-4">Focus Areas</h3>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="area in plan.focus_areas"
                            :key="area"
                            class="px-3 py-1.5 bg-[var(--color-bg-tertiary)] rounded-lg text-[var(--color-text-primary)]"
                        >
                            {{ getFocusLabel(area) }}
                        </span>
                    </div>
                    <div v-if="Object.keys(plan.targets).length" class="mt-4 pt-4 border-t border-[var(--color-border-default)]">
                        <h4 class="text-xs text-[var(--color-text-tertiary)] uppercase mb-2">Targets</h4>
                        <div class="grid grid-cols-2 gap-3">
                            <div v-for="(value, key) in plan.targets" :key="key">
                                <span class="text-[var(--color-text-tertiary)] text-sm">{{ formatLabel(String(key)) }}</span>
                                <div class="text-[var(--color-text-primary)] font-medium">
                                    {{ typeof value === 'number' ? formatTargetValue(String(key), value) : value }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card p-6">
                    <h3 class="text-sm font-medium text-[var(--color-text-tertiary)] uppercase mb-4">Strategy Notes</h3>
                    <p v-if="plan.strategy_notes" class="text-[var(--color-text-secondary)] text-sm whitespace-pre-wrap">
                        {{ plan.strategy_notes }}
                    </p>
                    <p v-else class="text-[var(--color-text-tertiary)] text-sm italic">No strategy notes for this plan.</p>

                    <div v-if="plan.approved_at" class="mt-4 pt-4 border-t border-[var(--color-border-default)] text-xs text-[var(--color-text-tertiary)]">
                        Approved by {{ plan.approved_by }} on {{ new Date(plan.approved_at).toLocaleDateString() }}
                    </div>
                </div>
            </div>

            <!-- Action Items -->
            <div class="card">
                <div class="border-b border-[var(--color-border-default)] px-6 py-4 flex items-center justify-between">
                    <h2 class="text-lg font-medium text-[var(--color-text-primary)]">Action Items</h2>
                    <div class="flex gap-2">
                        <button
                            @click="filter = 'all'"
                            :class="filter === 'all' ? 'bg-[var(--color-bg-elevated)] text-[var(--color-text-primary)]' : 'text-[var(--color-text-tertiary)] hover:text-[var(--color-text-primary)]'"
                            class="px-3 py-1 rounded text-sm transition-colors"
                        >
                            All ({{ items.length }})
                        </button>
                        <button
                            @click="filter = 'human'"
                            :class="filter === 'human' ? 'bg-[var(--color-bg-elevated)] text-[var(--color-text-primary)]' : 'text-[var(--color-text-tertiary)] hover:text-[var(--color-text-primary)]'"
                            class="px-3 py-1 rounded text-sm transition-colors"
                        >
                            👤 Human ({{ humanItems.length }})
                        </button>
                        <button
                            @click="filter = 'agent'"
                            :class="filter === 'agent' ? 'bg-[var(--color-bg-elevated)] text-[var(--color-text-primary)]' : 'text-[var(--color-text-tertiary)] hover:text-[var(--color-text-primary)]'"
                            class="px-3 py-1 rounded text-sm transition-colors"
                        >
                            🤖 Agent ({{ agentItems.length }})
                        </button>
                    </div>
                </div>

                <div class="divide-y divide-[var(--color-border-default)]">
                    <div
                        v-for="item in filteredItems"
                        :key="item.id"
                        class="px-6 py-4"
                    >
                        <div class="flex items-start gap-4">
                            <!-- Checkbox -->
                            <div class="pt-1">
                                <button
                                    v-if="item.status !== 'completed' && item.status !== 'skipped'"
                                    @click="updateItemStatus(item.id, 'completed')"
                                    class="w-5 h-5 border border-zinc-600 rounded hover:border-emerald-500 hover:bg-emerald-500/20 transition-colors"
                                ></button>
                                <div v-else-if="item.status === 'completed'" class="w-5 h-5 bg-emerald-500 rounded flex items-center justify-center">
                                    <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                                <div v-else class="w-5 h-5 bg-amber-500/20 rounded flex items-center justify-center">
                                    <span class="text-amber-400 text-xs">—</span>
                                </div>
                            </div>

                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p :class="item.status === 'completed' ? 'text-[var(--color-text-tertiary)] line-through' : 'text-[var(--color-text-primary)]'">
                                            {{ item.action }}
                                        </p>
                                        <div class="flex items-center gap-3 mt-1 text-xs">
                                            <span v-if="item.owner_type === 'agent'" class="text-purple-400">
                                                🤖 {{ item.agent_slug }}
                                            </span>
                                            <span v-else class="text-[var(--color-text-tertiary)]">
                                                👤 Human task
                                            </span>
                                            <span v-if="item.due_day" class="text-[var(--color-text-tertiary)]">
                                                Due: {{ item.due_day }}
                                            </span>
                                            <span v-if="item.success_metric" class="text-[var(--color-text-tertiary)]">
                                                Metric: {{ item.success_metric }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <span :class="[getPriorityBadge(item.priority).class, 'px-2 py-0.5 rounded text-xs border']">
                                            {{ item.priority }}
                                        </span>
                                        <span :class="[getItemStatusBadge(item.status, item.is_overdue).class, 'px-2 py-0.5 rounded text-xs']">
                                            {{ getItemStatusBadge(item.status, item.is_overdue).text }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Agent Task Status -->
                                <div v-if="item.agent_task" class="mt-2 p-2 bg-[var(--color-bg-tertiary)] rounded text-xs">
                                    <div class="flex items-center gap-2">
                                        <span class="text-[var(--color-text-tertiary)]">Agent Task:</span>
                                        <span :class="[getTaskStatusBadge(item.agent_task.status).class, 'px-1.5 py-0.5 rounded']">
                                            {{ getTaskStatusBadge(item.agent_task.status).text }}
                                        </span>
                                        <span v-if="item.agent_task.scheduled_for" class="text-[var(--color-text-tertiary)]">
                                            Scheduled: {{ new Date(item.agent_task.scheduled_for).toLocaleString() }}
                                        </span>
                                        <span v-if="item.agent_task.completed_at" class="text-[var(--color-text-tertiary)]">
                                            Completed: {{ new Date(item.agent_task.completed_at).toLocaleString() }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Result -->
                                <div v-if="item.result && Object.keys(item.result).length" class="mt-2 p-2 bg-emerald-500/10 border border-emerald-500/20 rounded text-xs text-emerald-400">
                                    Result: {{ JSON.stringify(item.result) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="!filteredItems.length" class="px-6 py-12 text-center">
                    <p class="text-[var(--color-text-tertiary)]">No items match the current filter.</p>
                </div>
            </div>

            <!-- Week Results (if completed) -->
            <div v-if="plan.week_results && Object.keys(plan.week_results).length" class="card p-6">
                <h3 class="text-sm font-medium text-[var(--color-text-tertiary)] uppercase mb-4">Week Results</h3>
                <pre class="text-[var(--color-text-secondary)] text-sm">{{ JSON.stringify(plan.week_results, null, 2) }}</pre>
            </div>
        </div>

        <!-- Reject Modal -->
        <Modal :show="showRejectModal" title="Reject Plan" @close="showRejectModal = false">
            <form @submit.prevent="rejectPlan">
                <div class="space-y-4">
                    <p class="text-[var(--color-text-tertiary)] text-sm">
                        Are you sure you want to reject this weekly plan? This action cannot be undone.
                    </p>
                    <div>
                        <label for="rejection-reason" class="block text-sm font-medium text-[var(--color-text-secondary)] mb-2">
                            Rejection Reason <span class="text-red-400">*</span>
                        </label>
                        <textarea
                            id="rejection-reason"
                            v-model="rejectForm.reason"
                            rows="4"
                            class="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border-default)] rounded-lg px-3 py-2 text-[var(--color-text-primary)] placeholder-[var(--color-text-tertiary)] focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent"
                            placeholder="Explain why this plan is being rejected..."
                            required
                        ></textarea>
                        <p v-if="rejectForm.errors.reason" class="mt-1 text-sm text-red-400">
                            {{ rejectForm.errors.reason }}
                        </p>
                    </div>
                </div>
            </form>
            <template #footer>
                <button
                    type="button"
                    @click="showRejectModal = false"
                    class="px-4 py-2 text-[var(--color-text-tertiary)] hover:text-[var(--color-text-primary)] transition-colors"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="rejectPlan"
                    :disabled="rejectForm.processing || !rejectForm.reason"
                    class="px-4 py-2 bg-red-600 hover:bg-red-500 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg transition-colors"
                >
                    {{ rejectForm.processing ? 'Rejecting...' : 'Reject Plan' }}
                </button>
            </template>
        </Modal>

        <!-- Request Revision Modal -->
        <Modal :show="showRevisionModal" title="Request Plan Revision" @close="showRevisionModal = false">
            <form @submit.prevent="requestRevision">
                <div class="space-y-4">
                    <p class="text-[var(--color-text-tertiary)] text-sm">
                        Provide feedback for the Business Strategist agent to revise this plan.
                    </p>
                    <div>
                        <label for="revision-feedback" class="block text-sm font-medium text-[var(--color-text-secondary)] mb-2">
                            Revision Feedback <span class="text-red-400">*</span>
                        </label>
                        <textarea
                            id="revision-feedback"
                            v-model="revisionForm.feedback"
                            rows="4"
                            class="w-full bg-[var(--color-bg-secondary)] border border-[var(--color-border-default)] rounded-lg px-3 py-2 text-[var(--color-text-primary)] placeholder-[var(--color-text-tertiary)] focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent"
                            placeholder="What changes would you like to see in the revised plan?"
                            required
                        ></textarea>
                        <p v-if="revisionForm.errors.feedback" class="mt-1 text-sm text-red-400">
                            {{ revisionForm.errors.feedback }}
                        </p>
                    </div>
                </div>
            </form>
            <template #footer>
                <button
                    type="button"
                    @click="showRevisionModal = false"
                    class="px-4 py-2 text-[var(--color-text-tertiary)] hover:text-[var(--color-text-primary)] transition-colors"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="requestRevision"
                    :disabled="revisionForm.processing || !revisionForm.feedback"
                    class="px-4 py-2 bg-amber-600 hover:bg-amber-500 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg transition-colors"
                >
                    {{ revisionForm.processing ? 'Submitting...' : 'Request Revision' }}
                </button>
            </template>
        </Modal>
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
