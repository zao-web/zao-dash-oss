<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ChartIcon, PlayIcon, CloseIcon, CheckIcon, WarningIcon, SparklesIcon, TrashIcon } from '@/Components/Icons';

interface Campaign {
    id: number;
    name: string;
    objective: string;
    status: string;
    daily_budget: number;
    start_date: string;
    automation_enabled: boolean;
    performance_goal: Record<string, number> | null;
    pause_threshold: Record<string, number> | null;
    status_message: string;
    status_type: 'success' | 'warning' | 'danger' | 'info';
    action_required: boolean;
    performance: {
        spend: number;
        impressions: number;
        clicks: number;
        conversions: number;
        ctr: number;
        cpc: number;
        cpa: number | null;
        roas: number | null;
    } | null;
    adsets_count: number;
    ads_count: number;
}

interface AdAccount {
    id: number;
    name: string;
    account_id: string;
    status: string;
    currency: string;
}

interface Summary {
    total_spend: number;
    total_impressions: number;
    total_clicks: number;
    total_conversions: number;
    avg_ctr: number;
    avg_cpa: number;
    active_campaigns: number;
    pending_approval: number;
}

const props = defineProps<{
    campaigns: Campaign[];
    adAccounts: AdAccount[];
    summary: Summary;
}>();

const formatCurrency = (value: number | null) => {
    if (value === null) return '-';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
};

const formatNumber = (value: number) => {
    return new Intl.NumberFormat('en-US').format(value);
};

const formatPercent = (value: number) => {
    return `${value.toFixed(2)}%`;
};

const getStatusBadgeClass = (type: string) => {
    const classes: Record<string, string> = {
        success: 'badge-green',
        warning: 'badge-yellow',
        danger: 'badge-red',
        info: 'badge-blue',
    };
    return classes[type] || 'badge-gray';
};

const approveCampaign = (campaignId: number) => {
    router.post(`/meta-ads/campaigns/${campaignId}/approve`);
};

const pauseCampaign = (campaignId: number) => {
    router.post(`/meta-ads/campaigns/${campaignId}/pause`);
};

const resumeCampaign = (campaignId: number) => {
    router.post(`/meta-ads/campaigns/${campaignId}/resume`);
};

const deleteCampaign = (campaignId: number, campaignName: string) => {
    if (confirm(`Are you sure you want to delete "${campaignName}"? This action cannot be undone and will remove all ads, creatives, and performance data.`)) {
        router.delete(`/meta-ads/campaigns/${campaignId}`);
    }
};

const actionableCampaigns = computed(() =>
    props.campaigns.filter(c => c.action_required)
);

const activeCampaigns = computed(() =>
    props.campaigns.filter(c => c.status === 'active')
);

const draftCampaigns = computed(() =>
    props.campaigns.filter(c => c.status === 'draft')
);

const failedCampaigns = computed(() =>
    props.campaigns.filter(c => c.status === 'failed')
);

const allCampaigns = computed(() => props.campaigns);
</script>

<template>
    <AppLayout title="Meta Ads">
        <!-- Header with Summary Stats -->
        <div class="mb-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-2xl font-bold" style="color: var(--color-text-primary)">Meta Advertising</h2>
                    <p class="text-caption mt-1">AI-powered campaign management for Facebook & Instagram</p>
                </div>
                <Link href="/meta-ads/campaigns/create" class="btn btn-primary">
                    <SparklesIcon :size="16" />
                    Create Campaign
                </Link>
            </div>

            <!-- Summary KPI Cards -->
            <div class="grid grid-cols-2 gap-3 md:gap-4 xl:grid-cols-4">
                <div class="metric-card">
                    <div class="metric-label">TOTAL SPEND</div>
                    <div class="metric-value">{{ formatCurrency(summary.total_spend) }}</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">CONVERSIONS</div>
                    <div class="metric-value">{{ formatNumber(summary.total_conversions) }}</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">AVG CPA</div>
                    <div class="metric-value">{{ formatCurrency(summary.avg_cpa) }}</div>
                </div>
                <div class="metric-card">
                    <div class="metric-label">AVG CTR</div>
                    <div class="metric-value">{{ formatPercent(summary.avg_ctr) }}</div>
                </div>
            </div>
        </div>

        <!-- No Campaigns State -->
        <div v-if="campaigns.length === 0" class="card p-12 text-center">
            <div class="avatar avatar-lg avatar-muted mx-auto mb-4">
                <SparklesIcon :size="24" />
            </div>
            <h3 class="text-heading mb-2">No campaigns yet</h3>
            <p class="text-body mb-6">Get started by creating your first AI-powered Meta ad campaign</p>
            <Link href="/meta-ads/campaigns/create" class="btn btn-primary">
                <SparklesIcon :size="16" />
                Create Your First Campaign
            </Link>
        </div>

        <!-- Action Required Section -->
        <div v-if="actionableCampaigns.length > 0" class="mb-6">
            <div class="flex items-center gap-2 mb-3">
                <WarningIcon :size="18" style="color: var(--color-status-yellow)" />
                <h3 class="text-heading">Action Required</h3>
                <span class="count-badge">{{ actionableCampaigns.length }}</span>
            </div>

            <div class="space-y-3">
                <div
                    v-for="campaign in actionableCampaigns"
                    :key="`action-${campaign.id}`"
                    class="card p-4"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <h4 class="text-lg font-semibold" style="color: var(--color-text-primary)">
                                    {{ campaign.name }}
                                </h4>
                                <span v-if="campaign.is_sandbox" class="badge badge-blue text-xs">
                                    SANDBOX
                                </span>
                                <span :class="['badge', getStatusBadgeClass(campaign.status_type)]">
                                    {{ campaign.status_message }}
                                </span>
                            </div>

                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-3">
                                <div>
                                    <span class="text-caption">Daily Budget</span>
                                    <p class="text-mono font-semibold" style="color: var(--color-text-primary)">
                                        {{ formatCurrency(campaign.daily_budget) }}
                                    </p>
                                </div>
                                <div v-if="campaign.performance">
                                    <span class="text-caption">Spend</span>
                                    <p class="text-mono font-semibold" style="color: var(--color-text-primary)">
                                        {{ formatCurrency(campaign.performance.spend) }}
                                    </p>
                                </div>
                                <div v-if="campaign.performance">
                                    <span class="text-caption">Conversions</span>
                                    <p class="text-mono font-semibold" style="color: var(--color-text-primary)">
                                        {{ campaign.performance.conversions }}
                                    </p>
                                </div>
                                <div v-if="campaign.performance?.cpa">
                                    <span class="text-caption">CPA</span>
                                    <p class="text-mono font-semibold" style="color: var(--color-text-primary)">
                                        {{ formatCurrency(campaign.performance.cpa) }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-2">
                            <button
                                v-if="campaign.status === 'pending_approval'"
                                @click="approveCampaign(campaign.id)"
                                class="btn btn-success"
                            >
                                <CheckIcon :size="16" />
                                Approve
                            </button>
                            <button
                                v-if="campaign.status === 'active'"
                                @click="pauseCampaign(campaign.id)"
                                class="btn btn-ghost"
                            >
                                <CloseIcon :size="16" />
                                Pause
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Campaigns -->
        <div v-if="activeCampaigns.length > 0">
            <div class="flex items-center gap-2 mb-3">
                <div class="status-dot running"></div>
                <h3 class="text-heading">Active Campaigns</h3>
                <span class="text-caption text-mono">{{ activeCampaigns.length }} running</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div
                    v-for="campaign in activeCampaigns"
                    :key="campaign.id"
                    class="card p-4 hover:border-[var(--color-border-default)] transition-colors cursor-pointer"
                    @click="router.visit(`/meta-ads/campaigns/${campaign.id}`)"
                >
                    <!-- Campaign Header -->
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="text-lg font-semibold" style="color: var(--color-text-primary)">
                                    {{ campaign.name }}
                                </h4>
                                <span v-if="campaign.is_sandbox" class="badge badge-blue text-xs">
                                    SANDBOX
                                </span>
                            </div>
                            <p class="text-caption">
                                {{ campaign.objective.replace('OUTCOME_', '').toLowerCase().replace('_', ' ') }}
                                · {{ campaign.adsets_count }} ad sets · {{ campaign.ads_count }} ads
                            </p>
                        </div>
                        <span :class="['badge', getStatusBadgeClass(campaign.status_type)]">
                            {{ campaign.status_message }}
                        </span>
                    </div>

                    <!-- Performance Metrics -->
                    <div v-if="campaign.performance" class="grid grid-cols-3 gap-4 pt-3 border-t border-[var(--color-border-subtle)]">
                        <div>
                            <span class="text-caption">Spend</span>
                            <p class="text-mono font-semibold text-sm" style="color: var(--color-text-primary)">
                                {{ formatCurrency(campaign.performance.spend) }}
                            </p>
                        </div>
                        <div>
                            <span class="text-caption">CTR</span>
                            <p class="text-mono font-semibold text-sm" style="color: var(--color-text-primary)">
                                {{ formatPercent(campaign.performance.ctr) }}
                            </p>
                        </div>
                        <div>
                            <span class="text-caption">
                                {{ campaign.performance.cpa ? 'CPA' : 'Clicks' }}
                            </span>
                            <p class="text-mono font-semibold text-sm" style="color: var(--color-text-primary)">
                                {{ campaign.performance.cpa ? formatCurrency(campaign.performance.cpa) : campaign.performance.clicks }}
                            </p>
                        </div>
                    </div>

                    <!-- No Performance State -->
                    <div v-else class="pt-3 border-t border-[var(--color-border-subtle)] text-center">
                        <p class="text-caption">Gathering performance data...</p>
                    </div>

                    <!-- Automation Badge -->
                    <div v-if="campaign.automation_enabled" class="mt-3 pt-3 border-t border-[var(--color-border-subtle)]">
                        <div class="flex items-center gap-2">
                            <div class="status-dot running"></div>
                            <span class="text-caption">AI optimization enabled</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Draft Campaigns -->
        <div v-if="draftCampaigns.length > 0" class="mt-6">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-2 h-2 rounded-full bg-gray-400"></div>
                <h3 class="text-heading">Draft Campaigns</h3>
                <span class="text-caption text-mono">{{ draftCampaigns.length }} drafts</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div
                    v-for="campaign in draftCampaigns"
                    :key="`draft-${campaign.id}`"
                    class="card p-4 hover:border-[var(--color-border-default)] transition-colors"
                >
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex-1 cursor-pointer" @click="router.visit(`/meta-ads/campaigns/${campaign.id}`)">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="text-lg font-semibold" style="color: var(--color-text-primary)">
                                    {{ campaign.name }}
                                </h4>
                                <span v-if="campaign.is_sandbox" class="badge badge-blue text-xs">
                                    SANDBOX
                                </span>
                            </div>
                            <p class="text-caption">
                                {{ campaign.objective.replace('OUTCOME_', '').toLowerCase().replace('_', ' ') }}
                                · {{ formatCurrency(campaign.daily_budget) }}/day
                            </p>
                        </div>
                        <button
                            @click.stop="deleteCampaign(campaign.id, campaign.name)"
                            class="btn btn-ghost btn-sm text-[var(--color-status-red)]"
                            title="Delete campaign"
                        >
                            <TrashIcon :size="16" />
                        </button>
                    </div>
                    <div class="pt-3 border-t border-[var(--color-border-subtle)] cursor-pointer" @click="router.visit(`/meta-ads/campaigns/${campaign.id}`)">
                        <p class="text-caption">Ready to launch - needs creative generation</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Failed Campaigns -->
        <div v-if="failedCampaigns.length > 0" class="mt-6">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-2 h-2 rounded-full bg-red-500"></div>
                <h3 class="text-heading">Failed Campaigns</h3>
                <span class="text-caption text-mono">{{ failedCampaigns.length }} failed</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div
                    v-for="campaign in failedCampaigns"
                    :key="`failed-${campaign.id}`"
                    class="card p-4 hover:border-[var(--color-border-default)] transition-colors"
                >
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex-1 cursor-pointer" @click="router.visit(`/meta-ads/campaigns/${campaign.id}`)">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="text-lg font-semibold" style="color: var(--color-text-primary)">
                                    {{ campaign.name }}
                                </h4>
                                <span v-if="campaign.is_sandbox" class="badge badge-blue text-xs">
                                    SANDBOX
                                </span>
                            </div>
                            <p class="text-caption">
                                {{ campaign.objective.replace('OUTCOME_', '').toLowerCase().replace('_', ' ') }}
                                · {{ formatCurrency(campaign.daily_budget) }}/day
                            </p>
                        </div>
                        <button
                            @click.stop="deleteCampaign(campaign.id, campaign.name)"
                            class="btn btn-ghost btn-sm text-[var(--color-status-red)]"
                            title="Delete campaign"
                        >
                            <TrashIcon :size="16" />
                        </button>
                    </div>
                    <div class="pt-3 border-t border-[var(--color-border-subtle)] cursor-pointer" @click="router.visit(`/meta-ads/campaigns/${campaign.id}`)">
                        <span class="badge badge-red">Failed</span>
                        <p class="text-caption mt-2">Creative generation failed - delete to retry</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty State for Active Campaigns -->
        <div v-if="campaigns.length > 0 && activeCampaigns.length === 0 && draftCampaigns.length === 0" class="card p-8 text-center">
            <p class="text-body">No active campaigns</p>
            <p class="text-caption mt-1">All campaigns are pending approval or paused</p>
        </div>
    </AppLayout>
</template>

<style scoped>
.count-badge {
    padding: 0.125rem 0.5rem;
    background: var(--color-accent);
    color: white;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    font-family: var(--font-mono);
}
</style>
