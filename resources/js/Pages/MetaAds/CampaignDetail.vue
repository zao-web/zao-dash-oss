<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { ChartIcon, PlayIcon, CloseIcon, CheckIcon, SparklesIcon, RefreshIcon, TrashIcon } from '@/Components/Icons';
import { useMetaAdsRealtime } from '@/composables/useMetaAdsRealtime';
import CreativeGenerationGrid from '@/Components/MetaAds/CreativeGenerationGrid.vue';
import { computed } from 'vue';

interface Campaign {
    id: number;
    name: string;
    objective: string;
    status: string;
    daily_budget: number;
    performance_goal: Record<string, number> | null;
    pause_threshold: Record<string, number> | null;
    automation_enabled: boolean;
    is_sandbox: boolean;
    creative_count: number;
    failed_creatives?: Array<{
        index: number;
        variant_name: string;
        headline: string;
        error: string;
    }>;
    failure_reason?: string | null;
    adsets: Array<{
        id: number;
        name: string;
        status: string;
        daily_budget: number;
        ads_count: number;
    }>;
    creatives: Array<{
        id: number;
        headline: string;
        primary_text: string;
        image_url: string;
        status: string;
    }>;
}

interface PerformancePoint {
    date: string;
    spend: number;
    conversions: number;
    cpa: number | null;
}

const props = defineProps<{
    campaign: Campaign;
    performanceTrend: PerformancePoint[];
}>();

// Real-time updates
const {
    isGenerating,
    isComplete,
    currentProgress,
    completionData,
    progressHistory,
} = useMetaAdsRealtime(props.campaign.id);

const progressPercentage = computed(() => {
    return currentProgress.value?.progress_percentage ?? 0;
});

const shouldShowProgress = computed(() => {
    return props.campaign.status === 'draft' || isGenerating.value || (isComplete.value && !completionData.value?.success);
});

const formatCurrency = (value: number | null) => {
    if (value === null) return '-';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
    }).format(value);
};

const approveCampaign = () => {
    router.post(`/meta-ads/campaigns/${props.campaign.id}/approve`);
};

const pauseCampaign = () => {
    router.post(`/meta-ads/campaigns/${props.campaign.id}/pause`);
};

const resumeCampaign = () => {
    router.post(`/meta-ads/campaigns/${props.campaign.id}/resume`);
};

const deleteCampaign = () => {
    if (confirm(`Are you sure you want to delete "${props.campaign.name}"? This action cannot be undone and will remove all ads, creatives, and performance data.`)) {
        router.delete(`/meta-ads/campaigns/${props.campaign.id}`, {
            onSuccess: () => {
                router.visit('/meta-ads');
            },
        });
    }
};

const canDelete = computed(() => {
    return ['draft', 'failed', 'paused'].includes(props.campaign.status);
});
</script>

<template>
    <AppLayout :title="campaign.name">
        <div class="mb-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <Link href="/meta-ads" class="text-caption hover:text-[var(--color-accent)]">
                            ← Back to Campaigns
                        </Link>
                    </div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold" style="color: var(--color-text-primary)">
                            {{ campaign.name }}
                        </h1>
                        <span v-if="campaign.is_sandbox" class="badge badge-blue">
                            SANDBOX
                        </span>
                    </div>
                    <p class="text-caption mt-1">
                        {{ campaign.objective.replace('OUTCOME_', '').toLowerCase().replace('_', ' ') }}
                        · {{ campaign.adsets.length }} ad sets
                    </p>
                </div>

                <div class="flex gap-2">
                    <button
                        v-if="campaign.status === 'pending_approval'"
                        @click="approveCampaign"
                        class="btn btn-success"
                    >
                        <CheckIcon :size="16" />
                        Approve & Launch
                    </button>
                    <button
                        v-if="campaign.status === 'active'"
                        @click="pauseCampaign"
                        class="btn btn-ghost"
                    >
                        <CloseIcon :size="16" />
                        Pause
                    </button>
                    <button
                        v-if="campaign.status === 'paused'"
                        @click="resumeCampaign"
                        class="btn btn-primary"
                    >
                        <PlayIcon :size="16" />
                        Resume
                    </button>
                    <button
                        v-if="canDelete"
                        @click="deleteCampaign"
                        class="btn btn-ghost text-[var(--color-status-red)]"
                        title="Delete campaign"
                    >
                        <TrashIcon :size="16" />
                        Delete
                    </button>
                </div>
            </div>

            <!-- Creative Generation Grid (shows skeleton cards with progressive reveal) -->
            <div v-if="shouldShowProgress || campaign.creatives?.length > 0" class="mb-6">
                <div class="card p-6">
                    <CreativeGenerationGrid
                        :total-creatives="campaign.creative_count"
                        :is-generating="isGenerating"
                        :is-complete="isComplete"
                        :current-progress="currentProgress"
                        :progress-history="progressHistory"
                        :success="completionData?.success ?? false"
                    />

                    <!-- Completion Actions -->
                    <div v-if="completionData?.success" class="flex items-center gap-3 mt-6 pt-6 border-t border-[var(--color-border-subtle)]">
                        <button
                            @click="approveCampaign"
                            class="btn btn-success"
                        >
                            <CheckIcon :size="16" />
                            Approve & Launch
                        </button>
                        <Link :href="`/meta-ads/campaigns/${campaign.id}`" class="btn btn-ghost">
                            <RefreshIcon :size="16" />
                            Refresh Page
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Failed Creatives Warning -->
            <div v-if="campaign.failed_creatives && campaign.failed_creatives.length > 0" class="mb-6">
                <div class="card p-6 border-l-4 border-l-[var(--color-status-red)]">
                    <div class="flex items-start gap-3">
                        <CloseIcon :size="20" style="color: var(--color-status-red)" class="flex-shrink-0 mt-0.5" />
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary)">
                                {{ campaign.failed_creatives.length }} Creative{{ campaign.failed_creatives.length > 1 ? 's' : '' }} Failed
                            </h3>
                            <p class="text-body mb-4">
                                {{ campaign.failure_reason || 'Some creatives failed during generation. Review the errors below.' }}
                            </p>
                            <div class="space-y-3">
                                <div
                                    v-for="failed in campaign.failed_creatives"
                                    :key="failed.index"
                                    class="p-3 surface-elevated rounded-lg"
                                >
                                    <div class="flex items-start gap-2">
                                        <span class="badge badge-red text-xs flex-shrink-0">Variant {{ failed.variant_name }}</span>
                                        <div class="flex-1">
                                            <p class="font-semibold text-sm" style="color: var(--color-text-primary)">
                                                {{ failed.headline }}
                                            </p>
                                            <p class="text-caption mt-1">{{ failed.error }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-if="campaign.status === 'failed'" class="mt-4 pt-4 border-t border-[var(--color-border-subtle)]">
                                <p class="text-caption mb-3">All creatives failed. Delete this campaign and try again.</p>
                                <button
                                    @click="deleteCampaign"
                                    class="btn btn-ghost text-[var(--color-status-red)]"
                                >
                                    <TrashIcon :size="16" />
                                    Delete Campaign
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Campaign Settings Summary -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="card p-4">
                    <span class="text-caption">Daily Budget</span>
                    <p class="text-xl font-bold text-mono mt-1" style="color: var(--color-text-primary)">
                        {{ formatCurrency(campaign.daily_budget) }}
                    </p>
                </div>
                <div class="card p-4">
                    <span class="text-caption">Target CPA</span>
                    <p class="text-xl font-bold text-mono mt-1" style="color: var(--color-text-primary)">
                        {{ formatCurrency(campaign.performance_goal?.target_cpa ?? null) }}
                    </p>
                </div>
                <div class="card p-4">
                    <span class="text-caption">Max CPA</span>
                    <p class="text-xl font-bold text-mono mt-1" style="color: var(--color-text-primary)">
                        {{ formatCurrency(campaign.pause_threshold?.cpa_max ?? null) }}
                    </p>
                </div>
                <div class="card p-4">
                    <span class="text-caption">Automation</span>
                    <p class="text-xl font-bold mt-1" style="color: var(--color-text-primary)">
                        {{ campaign.automation_enabled ? 'Enabled' : 'Disabled' }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Ad Sets -->
        <div class="card p-6 mb-6">
            <h3 class="text-lg font-semibold mb-4" style="color: var(--color-text-primary)">
                Ad Sets
            </h3>

            <div v-if="campaign.adsets.length > 0" class="space-y-3">
                <div
                    v-for="adset in campaign.adsets"
                    :key="adset.id"
                    class="p-4 surface-elevated rounded-lg"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="font-semibold" style="color: var(--color-text-primary)">
                                {{ adset.name }}
                            </h4>
                            <p class="text-caption mt-1">
                                {{ adset.ads_count }} ads · {{ formatCurrency(adset.daily_budget) }}/day
                            </p>
                        </div>
                        <span :class="['badge', adset.status === 'active' ? 'badge-green' : 'badge-gray']">
                            {{ adset.status }}
                        </span>
                    </div>
                </div>
            </div>

            <div v-else class="text-center py-8">
                <p class="text-body">No ad sets yet</p>
                <p class="text-caption mt-1">
                    {{ campaign.status === 'pending_approval'
                        ? 'AI is generating your ad creatives. This takes 2-3 minutes.'
                        : 'Create ad sets to launch this campaign' }}
                </p>
            </div>
        </div>

        <!-- Performance Trend -->
        <div v-if="performanceTrend.length > 0" class="card p-6">
            <div class="flex items-center gap-2 mb-4">
                <ChartIcon :size="18" />
                <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">
                    Performance Trend (14 Days)
                </h3>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-[var(--color-border-subtle)]">
                            <th class="text-left py-2 text-caption">Date</th>
                            <th class="text-right py-2 text-caption">Spend</th>
                            <th class="text-right py-2 text-caption">Conversions</th>
                            <th class="text-right py-2 text-caption">CPA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="point in performanceTrend"
                            :key="point.date"
                            class="border-b border-[var(--color-border-subtle)]"
                        >
                            <td class="py-2 text-body">{{ point.date }}</td>
                            <td class="py-2 text-right text-mono">{{ formatCurrency(point.spend) }}</td>
                            <td class="py-2 text-right text-mono">{{ point.conversions }}</td>
                            <td class="py-2 text-right text-mono">{{ formatCurrency(point.cpa) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.surface-elevated {
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
}
</style>
