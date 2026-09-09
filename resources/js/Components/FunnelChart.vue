<script setup lang="ts">
import { computed } from 'vue';

interface FunnelMetrics {
    new_to_qualified_rate: number;
    qualified_to_proposal_rate: number;
    proposal_to_negotiation_rate: number;
    negotiation_to_won_rate: number;
    overall_win_rate: number;
    leads_created: number;
    leads_qualified: number;
    proposals_sent: number;
    deals_won: number;
    deals_lost: number;
    avg_deal_size: number;
    avg_sales_cycle_days: number;
    by_stage?: Record<string, number>;
}

const props = defineProps<{
    metrics: FunnelMetrics;
    compact?: boolean;
}>();

const stages = computed(() => [
    {
        name: 'New Leads',
        count: props.metrics.leads_created,
        rate: null,
        color: 'bg-blue-500',
        width: 100,
    },
    {
        name: 'Qualified',
        count: props.metrics.leads_qualified,
        rate: props.metrics.new_to_qualified_rate,
        color: 'bg-indigo-500',
        width: Math.max(20, props.metrics.new_to_qualified_rate),
    },
    {
        name: 'Proposal',
        count: props.metrics.proposals_sent,
        rate: props.metrics.qualified_to_proposal_rate,
        color: 'bg-purple-500',
        width: Math.max(15, (props.metrics.new_to_qualified_rate / 100) * props.metrics.qualified_to_proposal_rate),
    },
    {
        name: 'Negotiation',
        count: props.metrics.by_stage?.negotiation ?? Math.round(props.metrics.proposals_sent * (props.metrics.proposal_to_negotiation_rate / 100)),
        rate: props.metrics.proposal_to_negotiation_rate,
        color: 'bg-amber-500',
        width: Math.max(10, (props.metrics.new_to_qualified_rate / 100) * (props.metrics.qualified_to_proposal_rate / 100) * props.metrics.proposal_to_negotiation_rate),
    },
    {
        name: 'Won',
        count: props.metrics.deals_won,
        rate: props.metrics.negotiation_to_won_rate,
        color: 'bg-emerald-500',
        width: Math.max(5, props.metrics.overall_win_rate),
    },
]);

const formatRate = (rate: number | null) => {
    if (rate === null) return '';
    return `${rate.toFixed(0)}%`;
};

const formatCurrency = (value: number) => {
    if (value >= 1000000) return `$${(value / 1000000).toFixed(1)}M`;
    if (value >= 1000) return `$${(value / 1000).toFixed(0)}k`;
    return `$${value.toLocaleString()}`;
};
</script>

<template>
    <div class="space-y-1">
        <!-- Funnel Visualization -->
        <div class="relative">
            <div
                v-for="(stage, index) in stages"
                :key="stage.name"
                class="flex items-center gap-3 py-2"
            >
                <!-- Stage bar -->
                <div class="flex-1 relative">
                    <div
                        :class="[stage.color, 'h-10 rounded-lg transition-all duration-500 flex items-center justify-between px-3']"
                        :style="{ width: `${stage.width}%`, minWidth: compact ? '80px' : '120px' }"
                    >
                        <span class="text-white text-sm font-medium truncate">{{ stage.name }}</span>
                        <span class="text-white/80 text-sm">{{ stage.count }}</span>
                    </div>
                </div>

                <!-- Conversion rate arrow -->
                <div v-if="stage.rate !== null" class="w-16 text-right">
                    <div class="text-xs text-zinc-400">
                        <span class="text-zinc-300">{{ formatRate(stage.rate) }}</span>
                    </div>
                </div>
                <div v-else class="w-16"></div>
            </div>
        </div>

        <!-- Summary Stats -->
        <div v-if="!compact" class="grid grid-cols-3 gap-4 pt-4 border-t border-zinc-800 mt-4">
            <div>
                <div class="text-xs text-zinc-500 uppercase">Overall Win Rate</div>
                <div class="text-lg font-semibold" :class="metrics.overall_win_rate >= 25 ? 'text-emerald-400' : 'text-amber-400'">
                    {{ Number(metrics.overall_win_rate ?? 0).toFixed(1) }}%
                </div>
            </div>
            <div>
                <div class="text-xs text-zinc-500 uppercase">Avg Deal Size</div>
                <div class="text-lg font-semibold text-white">
                    {{ formatCurrency(metrics.avg_deal_size) }}
                </div>
            </div>
            <div>
                <div class="text-xs text-zinc-500 uppercase">Avg Cycle</div>
                <div class="text-lg font-semibold text-white">
                    {{ metrics.avg_sales_cycle_days }} days
                </div>
            </div>
        </div>

        <!-- Lost deals indicator -->
        <div v-if="metrics.deals_lost > 0 && !compact" class="flex items-center gap-2 text-sm text-zinc-500 pt-2">
            <div class="w-3 h-3 bg-red-500/50 rounded"></div>
            <span>{{ metrics.deals_lost }} deals lost</span>
        </div>
    </div>
</template>
