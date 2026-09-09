<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface Retainer {
    id: number;
    client_id: number;
    client: {
        id: number;
        name: string;
        slug: string;
        initials: string;
    } | null;
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

const props = defineProps<{
    retainer: Retainer;
}>();

const expanded = ref(false);

const healthConfig = computed(() => {
    const configs: Record<string, { border: string; badge: string; badgeText: string; badgeBg: string }> = {
        healthy: {
            border: '',
            badge: 'Healthy',
            badgeText: 'text-green-700 dark:text-green-400',
            badgeBg: 'bg-green-50 dark:bg-green-900/30',
        },
        warning: {
            border: '',
            badge: 'Warning',
            badgeText: 'text-amber-700 dark:text-amber-400',
            badgeBg: 'bg-amber-50 dark:bg-amber-900/30',
        },
        critical: {
            border: '',
            badge: 'Needs attention',
            badgeText: 'text-red-700 dark:text-red-400',
            badgeBg: 'bg-red-50 dark:bg-red-900/30',
        },
        silent: {
            border: '',
            badge: silentBadgeText(),
            badgeText: 'text-amber-700 dark:text-amber-400',
            badgeBg: 'bg-amber-50 dark:bg-amber-900/30',
        },
    };
    return configs[props.retainer.health_status] || configs.healthy;
});

function silentBadgeText() {
    if (!props.retainer.last_client_activity_at) return 'Silent';
    const days = Math.floor(
        (Date.now() - new Date(props.retainer.last_client_activity_at).getTime()) / (1000 * 60 * 60 * 24)
    );
    return `Silent ${days}d`;
}

const marginColor = computed(() => {
    const m = props.retainer.effective_margin_percent;
    if (m === null) return 'bg-[var(--color-bg-tertiary)] text-[var(--color-text-secondary)]';
    if (m >= 60) return 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400';
    if (m >= 30) return 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400';
    if (m >= 10) return 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
    return 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400';
});

const progressColor = computed(() => {
    const pct = props.retainer.usage_percent;
    if (pct >= 100) return 'bg-red-500';
    if (pct >= 80) return 'bg-amber-500';
    return 'bg-green-500';
});

const totalEquivHours = computed(() => {
    return props.retainer.hours_used + props.retainer.agent_equivalent_hours;
});

const humanPercent = computed(() => {
    const total = totalEquivHours.value;
    if (total === 0) return 0;
    return (props.retainer.hours_used / total) * 100;
});

const formatCurrency = (val: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(val);
};

const removeRetainer = () => {
    const name = props.retainer.client?.name || 'this client';
    if (!confirm(
        `Remove the ${name} retainer?\n\n` +
        'This deletes ALL retainer periods and reports for this client and disables ' +
        'recurring invoicing so the retainer is not recreated. Invoices and manually ' +
        'tracked time are kept. This cannot be undone.'
    )) {
        return;
    }
    router.delete(`/retainers/${props.retainer.id}`);
};
</script>

<template>
    <Link :href="`/retainers/${retainer.id}`"
          class="block rounded-xl border border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] transition-shadow hover:shadow-md"
          :class="healthConfig.border">
        <div class="p-5">
            <!-- Row 1: Client name + MRR + badge -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--color-bg-tertiary)] text-sm font-semibold text-[var(--color-text-secondary)]">
                        {{ retainer.client?.initials || '??' }}
                    </div>
                    <div>
                        <h3 class="font-semibold text-[var(--color-text-primary)]">
                            {{ retainer.client?.name || 'Unknown Client' }}
                        </h3>
                        <p v-if="retainer.tier" class="text-xs text-[var(--color-text-tertiary)]">
                            {{ retainer.tier.replace(/_/g, ' + ').replace(/\b\w/g, c => c.toUpperCase()) }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="font-[var(--font-mono)] text-lg font-bold text-[var(--color-text-primary)]">
                        {{ formatCurrency(retainer.monthly_amount) }}
                    </span>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                          :class="[healthConfig.badgeBg, healthConfig.badgeText]">
                        {{ healthConfig.badge }}
                    </span>
                    <button
                        type="button"
                        @click.prevent.stop="removeRetainer"
                        class="rounded-md p-1.5 text-[var(--color-text-tertiary)] transition-colors hover:bg-red-500/10 hover:text-red-600"
                        title="Remove retainer (client offboarded)"
                        aria-label="Remove retainer"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Row 2: Hours progress bar -->
            <div class="mt-4">
                <div class="mb-1 flex items-center justify-between text-xs">
                    <span class="text-[var(--color-text-tertiary)]">
                        {{ totalEquivHours.toFixed(1) }} / {{ retainer.total_hours }}h
                    </span>
                    <span class="font-[var(--font-mono)] font-medium" :class="retainer.usage_percent >= 100 ? 'text-red-600' : 'text-[var(--color-text-secondary)]'">
                        {{ Math.round(retainer.usage_percent) }}%
                    </span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-[var(--color-bg-tertiary)]">
                    <div class="h-full rounded-full transition-all duration-500" :class="progressColor"
                         :style="{ width: `${Math.min(retainer.usage_percent, 100)}%` }"></div>
                </div>
            </div>

            <!-- Row 3: Human vs AI split bar -->
            <div class="mt-2">
                <div class="mb-1 flex items-center justify-between text-xs text-[var(--color-text-tertiary)]">
                    <span>Human {{ retainer.hours_used.toFixed(1) }}h</span>
                    <span>AI {{ retainer.agent_equivalent_hours.toFixed(1) }}h equiv</span>
                </div>
                <div class="flex h-1.5 w-full overflow-hidden rounded-full bg-[var(--color-bg-tertiary)]">
                    <div class="h-full bg-[var(--color-status-blue)]" :style="{ width: `${humanPercent}%` }"></div>
                    <div class="h-full bg-[var(--color-accent)]" :style="{ width: `${100 - humanPercent}%` }"></div>
                </div>
            </div>

            <!-- Row 4: Cost to serve + margin pill -->
            <div class="mt-3 flex items-center justify-between">
                <span class="text-xs text-[var(--color-text-tertiary)]">
                    Cost: {{ formatCurrency(retainer.hours_used * retainer.internal_hourly_rate + retainer.agent_cost_usd) }}
                </span>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="marginColor">
                    {{ retainer.effective_margin_percent !== null ? `${Math.round(retainer.effective_margin_percent)}% margin` : 'No margin data' }}
                </span>
            </div>
        </div>
    </Link>
</template>
