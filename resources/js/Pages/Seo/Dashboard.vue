<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';

// Access Ziggy's global route function
const route = (window as any).route;

const contentGoal = ref('');
const isGenerating = ref(false);

// Agent progress tracking
const activeRunId = ref<number | null>(null);
const agentProgress = ref<{
    status: string;
    messages: Array<{ text: string; timestamp: string }>;
    currentStep: string;
}>({
    status: 'idle',
    messages: [],
    currentStep: ''
});

const triggerContentAgent = () => {
    if (!contentGoal.value.trim()) {
        window.dispatchEvent(new CustomEvent('show-toast', {
            detail: {
                title: 'Missing Goal',
                message: 'Please describe what you want to rank for.',
                type: 'warning'
            }
        }));
        return;
    }

    isGenerating.value = true;
    agentProgress.value = {
        status: 'starting',
        messages: [{ text: 'Initializing SEO content generation...', timestamp: new Date().toISOString() }],
        currentStep: 'Starting'
    };

    router.post(route('seo.generate-content'), {
        goal: contentGoal.value,
    }, {
        preserveState: true,
        onSuccess: (page) => {
            // Extract run_id from the response if available
            const runId = (page.props as any)?.run_id;
            if (runId) {
                activeRunId.value = runId;
                setupAgentListener(runId);
            }

            window.dispatchEvent(new CustomEvent('show-toast', {
                detail: {
                    title: 'Content Generation Started',
                    message: 'The agent will research competitors, create a strategy, and generate pages.',
                    type: 'success'
                }
            }));
            contentGoal.value = '';
        },
        onError: (errors) => {
            const errorMessage = errors.goal || 'Failed to start content generation. Please try again.';
            window.dispatchEvent(new CustomEvent('show-toast', {
                detail: {
                    title: 'Error',
                    message: errorMessage,
                    type: 'error'
                }
            }));
            isGenerating.value = false;
            agentProgress.value = { status: 'idle', messages: [], currentStep: '' };
        },
    });
};

// Setup Echo listener for agent run progress
let echoChannel: any = null;
const setupAgentListener = (runId: number) => {
    if (typeof window !== 'undefined' && (window as any).Echo) {
        // Clean up existing listener
        if (echoChannel) {
            echoChannel.stopListening('.run.status.changed');
            echoChannel.stopListening('.run.output.updated');
        }

        // Listen to private channel for this specific run
        echoChannel = (window as any).Echo.private(`agent-runs.${runId}`);

        // Listen for status changes
        echoChannel.listen('.run.status.changed', (event: any) => {
            agentProgress.value.status = event.status;
            agentProgress.value.messages.push({
                text: `Status changed to: ${event.status}`,
                timestamp: new Date().toISOString()
            });

            if (event.status === 'completed' || event.status === 'failed') {
                isGenerating.value = false;
                window.dispatchEvent(new CustomEvent('show-toast', {
                    detail: {
                        title: event.status === 'completed' ? 'Generation Complete' : 'Generation Failed',
                        message: event.status === 'completed'
                            ? 'SEO content has been generated successfully!'
                            : 'Content generation encountered an error.',
                        type: event.status === 'completed' ? 'success' : 'error'
                    }
                }));
            }
        });

        // Listen for output updates (streaming progress)
        echoChannel.listen('.run.output.updated', (event: any) => {
            if (event.chunk) {
                agentProgress.value.messages.push({
                    text: event.chunk,
                    timestamp: new Date().toISOString()
                });
                agentProgress.value.currentStep = event.chunk;
            }
        });
    }
};

// Cleanup on unmount
onUnmounted(() => {
    if (echoChannel) {
        echoChannel.stopListening('.run.status.changed');
        echoChannel.stopListening('.run.output.updated');
    }
});

interface HeroMetrics {
    revenue: number;
    revenue_formatted: string;
    leads: number;
    projects: number;
    cost_per_lead: number;
    cost_per_lead_formatted: string;
    roi: number;
    roi_formatted: string;
    total_costs: number;
    period: string;
}

interface ConversionFunnel {
    traffic: number;
    leads: number;
    projects: number;
    revenue: number;
    revenue_formatted: string;
    traffic_to_leads_rate: number;
    leads_to_projects_rate: number;
    avg_project_value: number;
    avg_project_value_formatted: string;
}

interface Page {
    id: number;
    page_url: string;
    page_title: string;
    target_keyword: string;
    traffic: number;
    leads: number;
    projects: number;
    revenue: number;
    revenue_formatted: string;
    conversion_rate: number;
    conversion_rate_formatted: string;
}

interface Keyword {
    id: number;
    keyword: string;
    position: number;
    position_change: number;
    position_change_formatted: string;
    leads: number;
    revenue: number;
    revenue_formatted: string;
    intent: string;
}

interface ContentMetrics {
    count: number;
    traffic: number;
    leads: number;
    revenue: number;
    revenue_formatted: string;
    conversion_rate: number;
    conversion_rate_formatted: string;
    avg_revenue_per_page: number;
    roi?: number;
    roi_formatted?: string;
    cost_per_page?: number;
}

interface TrendData {
    date: string;
    value: number;
}

interface Kpis {
    hero_metrics: HeroMetrics;
    conversion_funnel: ConversionFunnel;
    top_pages: Page[];
    top_keywords: Keyword[];
    content_comparison: {
        service_pages: ContentMetrics;
        blog_posts: ContentMetrics;
        comparison_pages: ContentMetrics;
    };
    programmatic_vs_manual: {
        programmatic: ContentMetrics;
        manual: ContentMetrics;
    };
    trends: {
        leads_trend: TrendData[];
        revenue_trend: TrendData[];
    };
}

interface OrchestratorRun {
    id: number;
    status: string;
    task: string;
    started_at: string;
    completed_at: string | null;
    pages: Array<{
        id: number;
        page_url: string;
        target_keyword: string;
        page_type: string;
        status: string;
        meta_title: string;
    }>;
    pages_count: number;
    pages_completed: number;
}

const props = defineProps<{
    kpis: Kpis;
    recent_orchestrator_runs?: OrchestratorRun[];
}>();

const getRoiColor = (roi: number) => {
    if (roi >= 50) return 'color: var(--color-status-green)';
    if (roi >= 10) return 'color: var(--color-status-yellow)';
    return 'color: var(--color-status-red)';
};
</script>

<template>
    <AppLayout title="SEO Dashboard">
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <!-- Header -->
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <h1 class="text-title">SEO Dashboard</h1>
                    <p class="text-caption" style="margin-top: 0.25rem;">Track SEO performance, leads, and revenue attribution</p>
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <Link :href="route('seo.pages')" class="btn btn-secondary">
                        View All Pages
                    </Link>
                    <Link :href="route('seo.keywords')" class="btn btn-secondary">
                        View Keywords
                    </Link>
                </div>
            </div>

            <!-- Agent-Driven Content Generation -->
            <div class="card" style="border: 2px solid var(--color-accent); background: var(--color-bg-elevated);">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Generate Programmatic SEO Content</h3>
                        <p class="text-caption" style="margin-top: 0.25rem;">Tell the agent what you want to rank for, and it will research competitors, create a content strategy, and generate pages</p>
                    </div>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <div>
                            <label class="text-body" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">
                                What do you want to rank for?
                            </label>
                            <textarea
                                v-model="contentGoal"
                                placeholder="Example: I want to rank for Laravel development, React Native development, and AI development services in Oregon and Portland..."
                                class="input"
                                style="min-height: 100px; resize: vertical;"
                            ></textarea>
                        </div>

                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <p class="text-caption">
                                The agent will analyze competitors, identify content gaps, and create a strategy with target pages
                            </p>
                            <button
                                @click="triggerContentAgent"
                                :disabled="!contentGoal.trim() || isGenerating"
                                class="btn btn-primary"
                                style="padding: 0.75rem 1.5rem;"
                            >
                                {{ isGenerating ? 'Generating Strategy...' : 'Generate Content Strategy' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Agent Progress Display -->
            <div v-if="isGenerating || agentProgress.messages.length > 0" class="card">
                <div class="card-header">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div v-if="isGenerating" style="width: 16px; height: 16px; border: 2px solid var(--color-accent); border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                        <div>
                            <h3 class="card-title">Agent Progress</h3>
                            <p class="text-caption" style="margin-top: 0.25rem;">
                                {{ agentProgress.currentStep || 'Initializing...' }}
                            </p>
                        </div>
                    </div>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 300px; overflow-y: auto;">
                        <div
                            v-for="(message, index) in agentProgress.messages.slice().reverse()"
                            :key="index"
                            class="surface"
                            style="padding: 0.75rem; font-size: 0.8125rem;"
                        >
                            <div style="display: flex; justify-content: space-between; align-items: start; gap: 1rem;">
                                <span style="color: var(--color-text-secondary);">{{ message.text }}</span>
                                <span class="text-caption" style="white-space: nowrap;">
                                    {{ new Date(message.timestamp).toLocaleTimeString() }}
                                </span>
                            </div>
                        </div>
                        <div v-if="agentProgress.messages.length === 0" class="text-caption" style="text-align: center; padding: 2rem;">
                            Waiting for agent updates...
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Orchestrator Runs -->
            <div v-if="props.recent_orchestrator_runs && props.recent_orchestrator_runs.length > 0" class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Recent Content Generation Runs</h3>
                        <p class="text-caption" style="margin-top: 0.25rem;">
                            Track multi-piece content generation from the orchestrator agent
                        </p>
                    </div>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <div
                            v-for="run in props.recent_orchestrator_runs"
                            :key="run.id"
                            class="surface"
                            style="padding: 1rem;"
                        >
                            <!-- Run Header -->
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.75rem;">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <h4 class="text-body" style="font-weight: 600;">Run #{{ run.id }}</h4>
                                        <span
                                            :style="{
                                                padding: '0.125rem 0.5rem',
                                                borderRadius: '0.25rem',
                                                fontSize: '0.75rem',
                                                fontWeight: '500',
                                                background: run.status === 'completed' ? 'var(--color-status-green)' : run.status === 'failed' ? 'var(--color-status-red)' : 'var(--color-status-yellow)',
                                                color: 'white'
                                            }"
                                        >
                                            {{ run.status }}
                                        </span>
                                    </div>
                                    <p class="text-caption" style="margin-top: 0.25rem;">
                                        {{ run.task || 'Generate programmatic SEO content strategy and pages' }}
                                    </p>
                                </div>
                                <div class="text-caption">
                                    {{ new Date(run.started_at).toLocaleString() }}
                                </div>
                            </div>

                            <!-- Progress Bar -->
                            <div v-if="run.pages_count > 0" style="margin-bottom: 0.75rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.375rem;">
                                    <span class="text-caption">Content Pieces Progress</span>
                                    <span class="text-caption" style="font-weight: 500;">
                                        {{ run.pages_completed }} / {{ run.pages_count }} completed
                                    </span>
                                </div>
                                <div style="width: 100%; height: 8px; background: var(--color-border); border-radius: 4px; overflow: hidden;">
                                    <div
                                        :style="{
                                            width: `${(run.pages_completed / run.pages_count) * 100}%`,
                                            height: '100%',
                                            background: 'var(--color-accent)',
                                            transition: 'width 0.3s ease'
                                        }"
                                    ></div>
                                </div>
                            </div>

                            <!-- Pages List -->
                            <div v-if="run.pages.length > 0" style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <p class="text-caption" style="font-weight: 500; margin-bottom: 0.25rem;">Generated Pages:</p>
                                <div
                                    v-for="page in run.pages"
                                    :key="page.id"
                                    style="display: flex; align-items: center; justify-content: space-between; padding: 0.5rem; background: var(--color-bg); border-radius: 0.375rem;"
                                >
                                    <div style="flex: 1;">
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <Link
                                                :href="`/seo/pages/${page.id}`"
                                                class="text-body"
                                                style="font-weight: 500; text-decoration: none; color: var(--color-accent);"
                                            >
                                                {{ page.meta_title || page.page_url }}
                                            </Link>
                                            <span
                                                :style="{
                                                    padding: '0.125rem 0.375rem',
                                                    borderRadius: '0.25rem',
                                                    fontSize: '0.6875rem',
                                                    fontWeight: '500',
                                                    background: page.status === 'draft' || page.status === 'published' ? 'var(--color-status-green)' : 'var(--color-status-yellow)',
                                                    color: 'white'
                                                }"
                                            >
                                                {{ page.status }}
                                            </span>
                                        </div>
                                        <p class="text-caption" style="margin-top: 0.125rem;">
                                            {{ page.target_keyword }} • {{ page.page_type }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Empty State -->
                            <div v-else class="text-caption" style="text-align: center; padding: 1rem; color: var(--color-text-secondary);">
                                No pages generated yet...
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hero Metrics -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem;">
                <div class="metric-card">
                    <div class="metric-label">SEO Revenue ({{ kpis.hero_metrics.period }})</div>
                    <div class="metric-value" style="color: var(--color-status-green);">
                        {{ kpis.hero_metrics.revenue_formatted }}
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-label">SEO Leads</div>
                    <div class="metric-value">
                        {{ kpis.hero_metrics.leads }} <span class="metric-suffix">leads</span>
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-label">Cost / Lead</div>
                    <div class="metric-value" style="color: var(--color-status-blue);">
                        {{ kpis.hero_metrics.cost_per_lead_formatted }}
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-label">ROI</div>
                    <div class="metric-value" :style="getRoiColor(kpis.hero_metrics.roi)">
                        {{ kpis.hero_metrics.roi_formatted }}
                    </div>
                </div>
            </div>

            <!-- Conversion Funnel -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Conversion Funnel</h3>
                    <span class="text-caption">Traffic → Leads → Projects → Revenue</span>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 2rem;">
                        <div style="text-align: center;">
                            <div class="metric-value">{{ kpis.conversion_funnel.traffic.toLocaleString() }}</div>
                            <div class="text-caption" style="margin-top: 0.25rem;">Traffic</div>
                        </div>
                        <div style="text-align: center;">
                            <div class="metric-value" style="color: var(--color-status-blue);">{{ kpis.conversion_funnel.leads }}</div>
                            <div class="text-caption" style="margin-top: 0.25rem;">Leads</div>
                            <div class="text-caption" style="font-size: 11px; margin-top: 0.25rem;">
                                {{ kpis.conversion_funnel.traffic_to_leads_rate.toFixed(2) }}% conversion
                            </div>
                        </div>
                        <div style="text-align: center;">
                            <div class="metric-value" style="color: var(--color-accent);">{{ kpis.conversion_funnel.projects }}</div>
                            <div class="text-caption" style="margin-top: 0.25rem;">Projects</div>
                            <div class="text-caption" style="font-size: 11px; margin-top: 0.25rem;">
                                {{ kpis.conversion_funnel.leads_to_projects_rate.toFixed(2) }}% close rate
                            </div>
                        </div>
                        <div style="text-align: center;">
                            <div class="metric-value" style="color: var(--color-status-green);">{{ kpis.conversion_funnel.revenue_formatted }}</div>
                            <div class="text-caption" style="margin-top: 0.25rem;">Revenue</div>
                            <div class="text-caption" style="font-size: 11px; margin-top: 0.25rem;">
                                {{ kpis.conversion_funnel.avg_project_value_formatted }} avg
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem;">
                <!-- Top Performing Pages -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Top Performing Pages</h3>
                    </div>
                    <div class="card-body">
                        <div v-for="page in kpis.top_pages" :key="page.id" class="list-item">
                            <div class="list-item-content">
                                <div class="list-item-title">{{ page.page_title }}</div>
                                <div class="list-item-subtitle">{{ page.target_keyword }}</div>
                            </div>
                            <div class="list-item-meta">
                                <div style="font-size: 0.8125rem; font-weight: 600; color: var(--color-status-green);">
                                    {{ page.revenue_formatted }}
                                </div>
                                <div class="text-caption">{{ page.leads }} leads</div>
                            </div>
                        </div>
                        <div v-if="kpis.top_pages.length === 0" style="padding: 3rem; text-align: center; color: var(--color-text-tertiary);">
                            No pages with revenue yet
                        </div>
                    </div>
                </div>

                <!-- Top Keywords -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Top Keywords</h3>
                    </div>
                    <div class="card-body">
                        <div v-for="keyword in kpis.top_keywords" :key="keyword.id" class="list-item">
                            <div class="list-item-content">
                                <div class="list-item-title">{{ keyword.keyword }}</div>
                                <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                                    <span class="badge badge-blue" style="font-size: 10px;">
                                        Position {{ keyword.position }}
                                    </span>
                                    <span v-if="keyword.position_change_formatted" class="text-caption">
                                        {{ keyword.position_change_formatted }}
                                    </span>
                                </div>
                            </div>
                            <div class="list-item-meta">
                                <div style="font-size: 0.8125rem; font-weight: 500;">{{ keyword.leads }} leads</div>
                                <div class="text-caption">{{ keyword.revenue_formatted }}</div>
                            </div>
                        </div>
                        <div v-if="kpis.top_keywords.length === 0" style="padding: 3rem; text-align: center; color: var(--color-text-tertiary);">
                            No keywords generating leads yet
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Performance Comparison -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Content Performance by Type</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                        <div class="surface" style="padding: 1rem;">
                            <h4 style="font-size: 0.8125rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--color-text-primary);">Service Pages</h4>
                            <dl style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Count</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.content_comparison.service_pages.count }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Leads</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.content_comparison.service_pages.leads }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Revenue</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 600; color: var(--color-status-green);">{{ kpis.content_comparison.service_pages.revenue_formatted }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Conv Rate</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.content_comparison.service_pages.conversion_rate_formatted }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="surface" style="padding: 1rem;">
                            <h4 style="font-size: 0.8125rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--color-text-primary);">Blog Posts</h4>
                            <dl style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Count</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.content_comparison.blog_posts.count }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Leads</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.content_comparison.blog_posts.leads }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Revenue</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 600; color: var(--color-status-green);">{{ kpis.content_comparison.blog_posts.revenue_formatted }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Conv Rate</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.content_comparison.blog_posts.conversion_rate_formatted }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="surface" style="padding: 1rem;">
                            <h4 style="font-size: 0.8125rem; font-weight: 600; margin-bottom: 0.75rem; color: var(--color-text-primary);">Comparison Pages</h4>
                            <dl style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Count</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.content_comparison.comparison_pages.count }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Leads</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.content_comparison.comparison_pages.leads }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Revenue</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 600; color: var(--color-status-green);">{{ kpis.content_comparison.comparison_pages.revenue_formatted }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-caption">Conv Rate</dt>
                                    <dd style="font-size: 0.75rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.content_comparison.comparison_pages.conversion_rate_formatted }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Programmatic vs Manual -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Programmatic vs Manual Content</h3>
                        <p class="text-caption" style="margin-top: 0.25rem;">AI-generated pages vs manually created content</p>
                    </div>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                        <div class="surface-elevated" style="padding: 1.5rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                                <h4 style="font-size: 0.9375rem; font-weight: 600; color: var(--color-text-primary);">Programmatic (AI)</h4>
                                <span class="badge badge-blue">{{ kpis.programmatic_vs_manual.programmatic.count }} pages</span>
                            </div>
                            <dl style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-body">Leads</dt>
                                    <dd style="font-size: 0.8125rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.programmatic_vs_manual.programmatic.leads }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-body">Revenue</dt>
                                    <dd style="font-size: 0.8125rem; font-weight: 600; color: var(--color-status-green);">{{ kpis.programmatic_vs_manual.programmatic.revenue_formatted }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-body">Cost/Page</dt>
                                    <dd style="font-size: 0.8125rem; font-weight: 500; color: var(--color-text-primary);">${{ kpis.programmatic_vs_manual.programmatic.cost_per_page }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-top: 0.75rem; border-top: 1px solid var(--color-border-subtle);">
                                    <dt style="font-size: 0.8125rem; font-weight: 600; color: var(--color-text-secondary);">ROI</dt>
                                    <dd style="font-size: 1.125rem; font-weight: 700;" :style="getRoiColor(kpis.programmatic_vs_manual.programmatic.roi || 0)">
                                        {{ kpis.programmatic_vs_manual.programmatic.roi_formatted }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div class="surface-elevated" style="padding: 1.5rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                                <h4 style="font-size: 0.9375rem; font-weight: 600; color: var(--color-text-primary);">Manual</h4>
                                <span class="badge badge-gray">{{ kpis.programmatic_vs_manual.manual.count }} pages</span>
                            </div>
                            <dl style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-body">Leads</dt>
                                    <dd style="font-size: 0.8125rem; font-weight: 500; color: var(--color-text-primary);">{{ kpis.programmatic_vs_manual.manual.leads }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-body">Revenue</dt>
                                    <dd style="font-size: 0.8125rem; font-weight: 600; color: var(--color-status-green);">{{ kpis.programmatic_vs_manual.manual.revenue_formatted }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <dt class="text-body">Cost/Page</dt>
                                    <dd style="font-size: 0.8125rem; font-weight: 500; color: var(--color-text-primary);">${{ kpis.programmatic_vs_manual.manual.cost_per_page }}</dd>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding-top: 0.75rem; border-top: 1px solid var(--color-border-subtle);">
                                    <dt style="font-size: 0.8125rem; font-weight: 600; color: var(--color-text-secondary);">ROI</dt>
                                    <dd style="font-size: 1.125rem; font-weight: 700;" :style="getRoiColor(kpis.programmatic_vs_manual.manual.roi || 0)">
                                        {{ kpis.programmatic_vs_manual.manual.roi_formatted }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>
