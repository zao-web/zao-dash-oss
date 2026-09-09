<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// Access Ziggy's global route function
const route = (window as any).route;

interface SeoKeyword {
    id: number;
    keyword: string;
    current_position: number | null;
    search_volume: number | null;
    intent: string;
    status: string;
}

interface SeoPage {
    id: number;
    page_url: string;
    target_keyword: string;
    page_type: string;
    meta_title: string;
    meta_description: string;
    wordpress_post_id: number | null;
    generated_by_agent: boolean;
    status: string;
    published_at: string | null;
    impressions_30d: number;
    clicks_30d: number;
    avg_position_30d: number | null;
    ctr_30d: number;
    total_leads: number;
    total_projects: number;
    total_revenue: number;
    conversion_rate: number;
    optimization_count: number;
    last_optimized_at: string | null;
    created_at: string;
    updated_at: string;
    keywords?: SeoKeyword[];
}

interface Lead {
    id: number;
    company_name: string;
    contact_name: string;
    stage: string;
    deal_value: number | null;
    created_at: string;
}

interface Project {
    id: number;
    name: string;
    status: string;
    budget: number | null;
    created_at: string;
}

const props = defineProps<{
    page: SeoPage;
    leads: Lead[];
    projects: Project[];
}>();

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'var(--color-status-green)',
        draft: 'var(--color-status-yellow)',
        generating: 'var(--color-status-blue)',
        published: 'var(--color-status-green)',
        archived: 'var(--color-text-secondary)',
    };
    return colors[status] || 'var(--color-text-secondary)';
};

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
};

const formatDate = (date: string | null) => {
    if (!date) return 'Never';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};
</script>

<template>
    <AppLayout :title="page.meta_title || page.page_url">
        <Head :title="page.meta_title || page.page_url" />

        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <!-- Header -->
            <div>
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                    <Link :href="route('seo.pages')" class="text-caption" style="color: var(--color-accent);">
                        ← Back to Pages
                    </Link>
                </div>
                <div style="display: flex; align-items: start; justify-content: space-between;">
                    <div>
                        <h1 class="text-title">{{ page.meta_title || page.page_url }}</h1>
                        <p class="text-caption" style="margin-top: 0.25rem;">
                            {{ page.page_url }}
                        </p>
                    </div>
                    <span
                        :style="{
                            padding: '0.25rem 0.75rem',
                            borderRadius: '0.375rem',
                            fontSize: '0.875rem',
                            fontWeight: '500',
                            background: getStatusColor(page.status),
                            color: 'white'
                        }"
                    >
                        {{ page.status }}
                    </span>
                </div>
            </div>

            <!-- Key Metrics -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div class="card">
                    <div style="padding: 1rem;">
                        <p class="text-caption">Traffic (30d)</p>
                        <p class="text-title" style="margin-top: 0.25rem;">{{ page.clicks_30d?.toLocaleString() || 0 }}</p>
                    </div>
                </div>
                <div class="card">
                    <div style="padding: 1rem;">
                        <p class="text-caption">Avg Position</p>
                        <p class="text-title" style="margin-top: 0.25rem;">{{ page.avg_position_30d || 'N/A' }}</p>
                    </div>
                </div>
                <div class="card">
                    <div style="padding: 1rem;">
                        <p class="text-caption">Leads</p>
                        <p class="text-title" style="margin-top: 0.25rem;">{{ page.total_leads }}</p>
                    </div>
                </div>
                <div class="card">
                    <div style="padding: 1rem;">
                        <p class="text-caption">Revenue</p>
                        <p class="text-title" style="margin-top: 0.25rem;">{{ formatCurrency(page.total_revenue) }}</p>
                    </div>
                </div>
                <div class="card">
                    <div style="padding: 1rem;">
                        <p class="text-caption">Conversion Rate</p>
                        <p class="text-title" style="margin-top: 0.25rem;">{{ page.conversion_rate || '0.00' }}%</p>
                    </div>
                </div>
            </div>

            <!-- Page Details -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Page Details</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: grid; gap: 1rem;">
                        <div>
                            <p class="text-caption">Target Keyword</p>
                            <p class="text-body" style="margin-top: 0.25rem; font-weight: 500;">{{ page.target_keyword }}</p>
                        </div>
                        <div>
                            <p class="text-caption">Page Type</p>
                            <p class="text-body" style="margin-top: 0.25rem;">{{ page.page_type }}</p>
                        </div>
                        <div>
                            <p class="text-caption">Meta Description</p>
                            <p class="text-body" style="margin-top: 0.25rem;">{{ page.meta_description || 'Not set' }}</p>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div>
                                <p class="text-caption">Generated by Agent</p>
                                <p class="text-body" style="margin-top: 0.25rem;">{{ page.generated_by_agent ? 'Yes' : 'No' }}</p>
                            </div>
                            <div>
                                <p class="text-caption">WordPress Post ID</p>
                                <p class="text-body" style="margin-top: 0.25rem;">{{ page.wordpress_post_id || 'Not published' }}</p>
                            </div>
                            <div>
                                <p class="text-caption">Published</p>
                                <p class="text-body" style="margin-top: 0.25rem;">{{ formatDate(page.published_at) }}</p>
                            </div>
                            <div>
                                <p class="text-caption">Last Optimized</p>
                                <p class="text-body" style="margin-top: 0.25rem;">{{ formatDate(page.last_optimized_at) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Keywords -->
            <div v-if="page.keywords && page.keywords.length > 0" class="card">
                <div class="card-header">
                    <h3 class="card-title">Tracked Keywords</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <div
                            v-for="keyword in page.keywords"
                            :key="keyword.id"
                            class="surface"
                            style="padding: 1rem; display: flex; align-items: center; justify-content: space-between;"
                        >
                            <div>
                                <p class="text-body" style="font-weight: 500;">{{ keyword.keyword }}</p>
                                <p class="text-caption" style="margin-top: 0.25rem;">
                                    Intent: {{ keyword.intent }} • Volume: {{ keyword.search_volume?.toLocaleString() || 'N/A' }}
                                </p>
                            </div>
                            <div style="text-align: right;">
                                <p class="text-body" style="font-weight: 600;">Position: {{ keyword.current_position || 'N/A' }}</p>
                                <p class="text-caption" style="margin-top: 0.25rem;">{{ keyword.status }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Leads Generated -->
            <div v-if="leads.length > 0" class="card">
                <div class="card-header">
                    <h3 class="card-title">Leads from This Page ({{ leads.length }})</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <div
                            v-for="lead in leads"
                            :key="lead.id"
                            class="surface"
                            style="padding: 1rem; display: flex; align-items: center; justify-content: space-between;"
                        >
                            <div>
                                <p class="text-body" style="font-weight: 500;">{{ lead.company_name }}</p>
                                <p class="text-caption" style="margin-top: 0.25rem;">{{ lead.contact_name }}</p>
                            </div>
                            <div style="text-align: right;">
                                <p class="text-body">{{ lead.stage }}</p>
                                <p class="text-caption" style="margin-top: 0.25rem;">
                                    {{ lead.deal_value ? formatCurrency(lead.deal_value) : 'N/A' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Projects -->
            <div v-if="projects.length > 0" class="card">
                <div class="card-header">
                    <h3 class="card-title">Projects from This Page ({{ projects.length }})</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <div
                            v-for="project in projects"
                            :key="project.id"
                            class="surface"
                            style="padding: 1rem; display: flex; align-items: center; justify-content: space-between;"
                        >
                            <div>
                                <p class="text-body" style="font-weight: 500;">{{ project.name }}</p>
                                <p class="text-caption" style="margin-top: 0.25rem;">{{ formatDate(project.created_at) }}</p>
                            </div>
                            <div style="text-align: right;">
                                <p class="text-body">{{ project.status }}</p>
                                <p class="text-caption" style="margin-top: 0.25rem;">
                                    {{ project.budget ? formatCurrency(project.budget) : 'N/A' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
