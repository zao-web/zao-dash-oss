<script setup lang="ts">
interface Lever {
    lever: string;
    title: string;
    description: string;
    current: string;
    target: string;
    impact_score: number;
    actions: string[];
}

interface LeversData {
    status: string;
    message: string;
    revenue_gap?: number;
    weeks_remaining?: number;
    levers: Lever[];
}

defineProps<{
    data: LeversData;
    compact?: boolean;
}>();

const getLeverIcon = (lever: string) => {
    const icons: Record<string, string> = {
        increase_leads: '🎯',
        improve_win_rate: '📈',
        increase_deal_size: '💰',
        accelerate_cycle: '⚡',
        reactivate_lost: '🔄',
    };
    return icons[lever] || '🔧';
};

const getImpactBadge = (score: number) => {
    if (score >= 70) return { class: 'bg-emerald-500/20 text-emerald-400', text: 'High Impact' };
    if (score >= 50) return { class: 'bg-amber-500/20 text-amber-400', text: 'Medium' };
    return { class: 'bg-zinc-500/20 text-zinc-400', text: 'Low' };
};

const formatCurrency = (value: number) => {
    if (value >= 1000000) return `$${(value / 1000000).toFixed(1)}M`;
    if (value >= 1000) return `$${(value / 1000).toFixed(0)}k`;
    return `$${value.toLocaleString()}`;
};
</script>

<template>
    <div class="space-y-4">
        <!-- Status Header -->
        <div v-if="data.status !== 'on_track'" class="flex items-start gap-3 p-4 rounded-lg" :class="data.status === 'critical' ? 'bg-red-500/10 border border-red-500/30' : 'bg-amber-500/10 border border-amber-500/30'">
            <div class="text-2xl">{{ data.status === 'critical' ? '🚨' : '⚠️' }}</div>
            <div>
                <div :class="data.status === 'critical' ? 'text-red-400' : 'text-amber-400'" class="font-medium">
                    {{ data.message }}
                </div>
                <div v-if="data.revenue_gap" class="text-zinc-400 text-sm mt-1">
                    Gap: {{ formatCurrency(data.revenue_gap) }} • {{ data.weeks_remaining }} weeks remaining
                </div>
            </div>
        </div>

        <!-- On Track State -->
        <div v-if="data.status === 'on_track'" class="flex items-center gap-3 p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30">
            <div class="text-2xl">✅</div>
            <div class="text-emerald-400 font-medium">{{ data.message }}</div>
        </div>

        <!-- Levers List -->
        <div v-if="data.levers.length > 0" class="space-y-3">
            <h4 v-if="!compact" class="text-sm font-medium text-zinc-400 uppercase">Recommended Actions</h4>

            <div
                v-for="lever in data.levers"
                :key="lever.lever"
                class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden"
            >
                <div class="p-4">
                    <div class="flex items-start justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <span class="text-lg">{{ getLeverIcon(lever.lever) }}</span>
                            <span class="text-white font-medium">{{ lever.title }}</span>
                        </div>
                        <span :class="[getImpactBadge(lever.impact_score).class, 'px-2 py-0.5 rounded text-xs']">
                            {{ getImpactBadge(lever.impact_score).text }}
                        </span>
                    </div>

                    <p class="text-zinc-400 text-sm mb-3">{{ lever.description }}</p>

                    <!-- Current vs Target -->
                    <div class="flex gap-4 text-sm mb-3">
                        <div>
                            <span class="text-zinc-500">Current: </span>
                            <span class="text-zinc-300">{{ lever.current }}</span>
                        </div>
                        <div class="text-zinc-600">→</div>
                        <div>
                            <span class="text-zinc-500">Target: </span>
                            <span class="text-emerald-400">{{ lever.target }}</span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div v-if="!compact && lever.actions.length > 0" class="space-y-1">
                        <div
                            v-for="(action, idx) in lever.actions"
                            :key="idx"
                            class="flex items-center gap-2 text-sm text-zinc-500"
                        >
                            <div class="w-1.5 h-1.5 bg-zinc-600 rounded-full"></div>
                            <span>{{ action }}</span>
                        </div>
                    </div>
                </div>

                <!-- Impact Bar -->
                <div class="h-1 bg-zinc-800">
                    <div
                        class="h-full bg-gradient-to-r from-blue-500 to-emerald-500"
                        :style="{ width: `${lever.impact_score}%` }"
                    ></div>
                </div>
            </div>
        </div>
    </div>
</template>
