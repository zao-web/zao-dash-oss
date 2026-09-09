<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { computed } from 'vue';

interface StrategicGoal {
    id: number;
    fiscal_year: number;
    name: string;
    revenue_target: number;
    revenue_actual: number;
    progress_percent: number;
    status: string;
    is_on_track: boolean;
}

interface BusinessGoal {
    id: number;
    type: string;
    period: string;
    label: string;
    target: number;
    current: number;
    progress: number;
    is_achieved: boolean;
}

const props = defineProps<{
    goals: StrategicGoal[];
    businessGoals: BusinessGoal[];
}>();

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
};

const getStatusBadge = (goal: StrategicGoal) => {
    if (goal.status === 'achieved') return { class: 'bg-emerald-500/20 text-emerald-400', text: 'Achieved' };
    if (goal.status === 'missed') return { class: 'bg-red-500/20 text-red-400', text: 'Missed' };
    if (goal.status === 'archived') return { class: 'bg-zinc-500/20 text-zinc-400', text: 'Archived' };
    if (goal.is_on_track) return { class: 'bg-emerald-500/20 text-emerald-400', text: 'On Track' };
    return { class: 'bg-amber-500/20 text-amber-400', text: 'Behind' };
};

const getProgressColor = (progress: number, isOnTrack: boolean) => {
    if (progress >= 100) return 'bg-emerald-500';
    if (isOnTrack) return 'bg-blue-500';
    if (progress >= 50) return 'bg-amber-500';
    return 'bg-red-500';
};

const activeGoal = computed(() => props.goals.find(g => g.status === 'active'));
</script>

<template>
    <AppLayout>
        <Head title="Strategic Goals" />

        <div class="p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-title">Strategic Goals</h1>
                    <p class="text-body mt-1">Track progress toward revenue targets</p>
                </div>
                <Link
                    href="/goals/create"
                    class="btn btn-primary"
                >
                    New Goal
                </Link>
            </div>

            <!-- Active Goal Hero -->
            <div v-if="activeGoal" class="card p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <div class="text-caption uppercase tracking-wider mb-1">Active Goal</div>
                        <h2 class="text-xl font-semibold text-[var(--color-text-primary)]">{{ activeGoal.name }}</h2>
                        <p class="text-[var(--color-text-secondary)] mt-1">FY{{ activeGoal.fiscal_year }}</p>
                    </div>
                    <span :class="[getStatusBadge(activeGoal).class, 'px-3 py-1 rounded-full text-xs font-medium']">
                        {{ getStatusBadge(activeGoal).text }}
                    </span>
                </div>

                <div class="grid grid-cols-3 gap-6 mb-4">
                    <div>
                        <div class="text-caption uppercase mb-1">Target</div>
                        <div class="text-2xl font-semibold text-[var(--color-text-primary)]">{{ formatCurrency(activeGoal.revenue_target) }}</div>
                    </div>
                    <div>
                        <div class="text-caption uppercase mb-1">Actual</div>
                        <div class="text-2xl font-semibold text-[var(--color-text-primary)]">{{ formatCurrency(activeGoal.revenue_actual) }}</div>
                    </div>
                    <div>
                        <div class="text-caption uppercase mb-1">Progress</div>
                        <div class="text-2xl font-semibold text-[var(--color-text-primary)]">{{ activeGoal.progress_percent.toFixed(1) }}%</div>
                    </div>
                </div>

                <div class="relative h-3 bg-[var(--color-bg-elevated)] rounded-full overflow-hidden">
                    <div
                        :class="[getProgressColor(activeGoal.progress_percent, activeGoal.is_on_track), 'absolute inset-y-0 left-0 rounded-full transition-all duration-500']"
                        :style="{ width: `${Math.min(100, activeGoal.progress_percent)}%` }"
                    ></div>
                </div>

                <div class="mt-4 pt-4 border-t border-[var(--color-border-subtle)] flex justify-end">
                    <Link
                        :href="`/goals/${activeGoal.id}`"
                        class="text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] text-sm font-medium transition-colors"
                    >
                        View Details →
                    </Link>
                </div>
            </div>

            <!-- Quick KPI Goals -->
            <div v-if="businessGoals.length" class="card">
                <div class="card-header">
                    <h2 class="card-title">KPI Targets</h2>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-6">
                    <div
                        v-for="goal in businessGoals"
                        :key="goal.id"
                        class="bg-[var(--color-bg-tertiary)] rounded-lg p-4"
                    >
                        <div class="text-caption uppercase tracking-wider mb-2">{{ goal.label }}</div>
                        <div class="flex items-baseline gap-2">
                            <span class="text-xl font-semibold text-[var(--color-text-primary)]">
                                {{ goal.type === 'revenue' || goal.type === 'pipeline' ? formatCurrency(goal.current) : goal.current }}
                            </span>
                            <span class="text-[var(--color-text-secondary)] text-sm">
                                / {{ goal.type === 'revenue' || goal.type === 'pipeline' ? formatCurrency(goal.target) : goal.target }}
                            </span>
                        </div>
                        <div class="mt-2 h-1.5 bg-[var(--color-bg-elevated)] rounded-full overflow-hidden">
                            <div
                                :class="goal.is_achieved ? 'bg-emerald-500' : 'bg-blue-500'"
                                class="h-full rounded-full"
                                :style="{ width: `${Math.min(100, goal.progress)}%` }"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- All Goals List -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">All Goals</h2>
                </div>

                <div v-if="goals.length" class="divide-y divide-[var(--color-border-subtle)]">
                    <Link
                        v-for="goal in goals"
                        :key="goal.id"
                        :href="`/goals/${goal.id}`"
                        class="list-item"
                    >
                        <div class="flex items-center justify-between w-full">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-[var(--color-bg-tertiary)] rounded-lg flex items-center justify-center">
                                    <span class="text-lg font-semibold text-[var(--color-text-secondary)]">{{ String(goal.fiscal_year ?? '').slice(-2) }}</span>
                                </div>
                                <div>
                                    <div class="text-[var(--color-text-primary)] font-medium">{{ goal.name }}</div>
                                    <div class="text-[var(--color-text-secondary)] text-sm">
                                        {{ formatCurrency(goal.revenue_actual) }} of {{ formatCurrency(goal.revenue_target) }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="w-32">
                                    <div class="flex items-center justify-between text-xs mb-1">
                                        <span class="text-[var(--color-text-tertiary)]">Progress</span>
                                        <span class="text-[var(--color-text-primary)]">{{ Number(goal.progress_percent ?? 0).toFixed(1) }}%</span>
                                    </div>
                                    <div class="h-1.5 bg-[var(--color-bg-elevated)] rounded-full overflow-hidden">
                                        <div
                                            :class="getProgressColor(goal.progress_percent, goal.is_on_track)"
                                            class="h-full rounded-full"
                                            :style="{ width: `${Math.min(100, goal.progress_percent)}%` }"
                                        ></div>
                                    </div>
                                </div>
                                <span :class="[getStatusBadge(goal).class, 'px-2 py-1 rounded text-xs font-medium']">
                                    {{ getStatusBadge(goal).text }}
                                </span>
                            </div>
                        </div>
                    </Link>
                </div>

                <div v-else class="px-6 py-12 text-center">
                    <div class="text-4xl mb-3">🎯</div>
                    <p class="text-[var(--color-text-secondary)] mb-4">No strategic goals yet</p>
                    <Link
                        href="/goals/create"
                        class="btn btn-primary"
                    >
                        Create Your First Goal
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
