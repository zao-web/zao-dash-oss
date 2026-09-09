<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InlineSelect from '@/Components/InlineSelect.vue';

// Access Ziggy's global route function
const route = (window as any).route;

interface SeoPage {
    id: number;
    page_url: string;
    page_title?: string;
    meta_title?: string;
    target_keyword: string;
    page_type?: 'service' | 'blog' | 'comparison' | 'other';
    status?: string;
    generated_by_agent: boolean;
    impressions_30d?: number;
    clicks_30d?: number;
    total_leads?: number;
    total_projects?: number;
    total_revenue?: number;
    total_revenue_formatted?: string;
    conversion_rate?: number;
    conversion_rate_formatted?: string;
    created_at: string;
    updated_at: string;
}

interface Filters {
    status?: string;
    page_type?: string;
    generated_by?: string;
    search?: string;
}

interface PaginatedPages {
    data: SeoPage[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const props = defineProps<{
    pages: PaginatedPages;
    filters: Filters;
}>();

const search = ref(props.filters.search || '');
const selectedStatus = ref(props.filters.status || 'all');
const selectedPageType = ref(props.filters.page_type || 'all');
const selectedGeneratedBy = ref(props.filters.generated_by || 'all');

const applyFilters = () => {
    router.get(route('seo.pages'), {
        search: search.value || undefined,
        status: selectedStatus.value !== 'all' ? selectedStatus.value : undefined,
        page_type: selectedPageType.value !== 'all' ? selectedPageType.value : undefined,
        generated_by: selectedGeneratedBy.value !== 'all' ? selectedGeneratedBy.value : undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const getPageTypeClass = (type: string) => {
    const classes: Record<string, string> = {
        service: 'badge-blue',
        blog: 'badge-blue',
        comparison: 'badge-yellow',
        'case-study': 'badge-green',
        other: 'badge-gray',
    };
    return classes[type] || 'badge-gray';
};

const getStatusClass = (status: string) => {
    const classes: Record<string, string> = {
        active: 'badge-green',
        published: 'badge-green',
        draft: 'badge-yellow',
        needs_update: 'badge-yellow',
        archived: 'badge-gray',
        generating: 'badge-blue',
        review: 'badge-blue',
        failed: 'badge-red',
    };
    return classes[status] || 'badge-gray';
};

const deletePage = (page: SeoPage) => {
    if (confirm(`Are you sure you want to delete "${page.meta_title || page.page_url}"?`)) {
        router.delete(route('seo.pages.delete', page.id), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <AppLayout title="SEO Pages">
        <Head title="SEO Pages" />

        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <!-- Header -->
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <h1 class="text-title">SEO Pages</h1>
                    <p class="text-caption" style="margin-top: 0.25rem;">
                        Track performance of all SEO pages
                    </p>
                </div>
                <Link :href="route('seo.dashboard')" class="btn btn-secondary">
                    ← Back to Dashboard
                </Link>
            </div>

            <!-- Filters -->
            <div class="card">
                <div style="padding: 1rem;">
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;">
                        <div>
                            <label class="text-caption" style="display: block; margin-bottom: 0.5rem;">
                                Search
                            </label>
                            <input
                                v-model="search"
                                @keyup.enter="applyFilters"
                                type="text"
                                placeholder="Search pages..."
                                class="input"
                                style="width: 100%;"
                            />
                        </div>

                        <InlineSelect
                            v-model="selectedStatus"
                            :options="[
                                { value: 'all', label: 'All Statuses' },
                                { value: 'active', label: 'Active' },
                                { value: 'draft', label: 'Draft' },
                                { value: 'published', label: 'Published' },
                                { value: 'archived', label: 'Archived' },
                            ]"
                            @update:modelValue="applyFilters"
                        />

                        <InlineSelect
                            v-model="selectedPageType"
                            :options="[
                                { value: 'all', label: 'All Types' },
                                { value: 'service', label: 'Service Pages' },
                                { value: 'blog', label: 'Blog Posts' },
                                { value: 'comparison', label: 'Comparison Pages' },
                                { value: 'other', label: 'Other' },
                            ]"
                            @update:modelValue="applyFilters"
                        />

                        <InlineSelect
                            v-model="selectedGeneratedBy"
                            :options="[
                                { value: 'all', label: 'All Pages' },
                                { value: 'agent', label: 'AI Generated' },
                                { value: 'manual', label: 'Manual' },
                            ]"
                            @update:modelValue="applyFilters"
                        />
                    </div>
                </div>
            </div>

            <!-- Pages Table -->
            <div class="card">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--color-border-subtle);">
                            <th class="text-caption" style="padding: 0.75rem 1rem; text-align: left; text-transform: uppercase; letter-spacing: 0.05em;">
                                Page
                            </th>
                            <th class="text-caption" style="padding: 0.75rem 1rem; text-align: left; text-transform: uppercase; letter-spacing: 0.05em;">
                                Type
                            </th>
                            <th class="text-caption" style="padding: 0.75rem 1rem; text-align: left; text-transform: uppercase; letter-spacing: 0.05em;">
                                Status
                            </th>
                            <th class="text-caption" style="padding: 0.75rem 1rem; text-align: right; text-transform: uppercase; letter-spacing: 0.05em;">
                                Impressions
                            </th>
                            <th class="text-caption" style="padding: 0.75rem 1rem; text-align: right; text-transform: uppercase; letter-spacing: 0.05em;">
                                Leads
                            </th>
                            <th class="text-caption" style="padding: 0.75rem 1rem; text-align: right; text-transform: uppercase; letter-spacing: 0.05em;">
                                Revenue
                            </th>
                            <th class="text-caption" style="padding: 0.75rem 1rem; text-align: right; text-transform: uppercase; letter-spacing: 0.05em;">
                                Conv Rate
                            </th>
                            <th class="text-caption" style="padding: 0.75rem 1rem; text-align: right; text-transform: uppercase; letter-spacing: 0.05em;">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="page in pages.data"
                            :key="page.id"
                            style="border-bottom: 1px solid var(--color-border-subtle);"
                            class="table-row-hover"
                        >
                            <td style="padding: 0.75rem 1rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <div style="min-width: 0; flex: 1;">
                                        <Link
                                            :href="route('seo.pages.show', page.id)"
                                            class="text-body"
                                            style="font-weight: 500; color: var(--color-text-primary); display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                                        >
                                            {{ page.meta_title || page.page_url }}
                                        </Link>
                                        <p class="text-caption" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            {{ page.target_keyword }}
                                        </p>
                                    </div>
                                    <span
                                        v-if="page.generated_by_agent"
                                        class="badge badge-blue"
                                    >
                                        AI
                                    </span>
                                </div>
                            </td>
                            <td style="padding: 0.75rem 1rem;">
                                <span
                                    v-if="page.page_type"
                                    class="badge"
                                    :class="getPageTypeClass(page.page_type)"
                                >
                                    {{ page.page_type }}
                                </span>
                                <span v-else class="text-caption">—</span>
                            </td>
                            <td style="padding: 0.75rem 1rem;">
                                <span
                                    v-if="page.status"
                                    class="badge"
                                    :class="getStatusClass(String(page.status))"
                                >
                                    {{ String(page.status).replace('_', ' ') }}
                                </span>
                                <span v-else class="text-caption">—</span>
                            </td>
                            <td class="text-body" style="padding: 0.75rem 1rem; text-align: right;">
                                {{ (page.impressions_30d ?? 0).toLocaleString() }}
                            </td>
                            <td class="text-body" style="padding: 0.75rem 1rem; text-align: right;">
                                {{ page.total_leads ?? 0 }}
                            </td>
                            <td style="padding: 0.75rem 1rem; text-align: right; color: var(--color-status-green); font-weight: 500;">
                                {{ page.total_revenue_formatted || `$${Number(page.total_revenue ?? 0).toLocaleString()}` }}
                            </td>
                            <td class="text-body" style="padding: 0.75rem 1rem; text-align: right;">
                                {{ page.conversion_rate_formatted || `${Number(page.conversion_rate ?? 0).toFixed(1)}%` }}
                            </td>
                            <td style="padding: 0.75rem 1rem; text-align: right;">
                                <button
                                    @click="deletePage(page)"
                                    class="text-caption"
                                    style="color: var(--color-status-red); font-weight: 500; background: none; border: none; cursor: pointer;"
                                >
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <tr v-if="pages.data.length === 0">
                            <td colspan="8" class="text-caption" style="padding: 3rem 1rem; text-align: center;">
                                No pages found
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="pages.last_page > 1" style="padding: 1rem; border-top: 1px solid var(--color-border-subtle); display: flex; align-items: center; justify-content: space-between;">
                    <p class="text-caption">
                        Showing {{ pages.data.length }} of {{ pages.total }} pages
                    </p>
                    <div style="display: flex; gap: 0.5rem;">
                        <Link
                            v-if="pages.current_page > 1"
                            :href="route('seo.pages', { ...filters, page: pages.current_page - 1 })"
                            class="btn btn-secondary btn-sm"
                        >
                            Previous
                        </Link>
                        <Link
                            v-if="pages.current_page < pages.last_page"
                            :href="route('seo.pages', { ...filters, page: pages.current_page + 1 })"
                            class="btn btn-secondary btn-sm"
                        >
                            Next
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.table-row-hover:hover {
    background: var(--color-bg-tertiary);
}
</style>
