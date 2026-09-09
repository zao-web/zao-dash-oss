<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InlineSelect from '@/Components/InlineSelect.vue';

// Access Ziggy's global route function
const route = (window as any).route;

interface SeoPage {
    id: number;
    page_url: string;
    page_title: string;
}

interface SeoKeyword {
    id: number;
    keyword: string;
    seo_page_id: number;
    seo_page: SeoPage;
    current_position: number;
    previous_position: number | null;
    position_change: number;
    position_change_formatted: string;
    intent: 'informational' | 'commercial' | 'transactional' | 'navigational';
    status: 'tracking' | 'paused' | 'archived';
    total_leads: number;
    total_revenue: number;
    total_revenue_formatted: string;
    created_at: string;
    updated_at: string;
}

interface Filters {
    intent?: string;
    status?: string;
    top_only?: boolean;
    search?: string;
}

interface PaginatedKeywords {
    data: SeoKeyword[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const props = defineProps<{
    keywords: PaginatedKeywords;
    filters: Filters;
}>();

const search = ref(props.filters.search || '');
const selectedIntent = ref(props.filters.intent || 'all');
const selectedStatus = ref(props.filters.status || 'all');
const topOnly = ref(props.filters.top_only || false);

const applyFilters = () => {
    router.get(route('seo.keywords'), {
        search: search.value || undefined,
        intent: selectedIntent.value !== 'all' ? selectedIntent.value : undefined,
        status: selectedStatus.value !== 'all' ? selectedStatus.value : undefined,
        top_only: topOnly.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const getIntentColor = (intent: string) => {
    const colors = {
        informational: 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        commercial: 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300',
        transactional: 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-300',
        navigational: 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300',
    };
    return colors[intent as keyof typeof colors] || colors.informational;
};

const getStatusColor = (status: string) => {
    const colors = {
        tracking: 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-300',
        paused: 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300',
        archived: 'bg-zinc-100 dark:bg-zinc-800 text-zinc-800 dark:text-zinc-300',
    };
    return colors[status as keyof typeof colors] || colors.tracking;
};

const getPositionColor = (position: number) => {
    if (position <= 3) return 'text-emerald-600 dark:text-emerald-400 font-semibold';
    if (position <= 10) return 'text-blue-600 dark:text-blue-400 font-semibold';
    if (position <= 20) return 'text-amber-600 dark:text-amber-400';
    return 'text-zinc-600 dark:text-zinc-400';
};

const getPositionChangeIcon = (change: number) => {
    if (change > 0) {
        return '<svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" /></svg>';
    }
    if (change < 0) {
        return '<svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" /></svg>';
    }
    return '<svg class="w-4 h-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14" /></svg>';
};
</script>

<template>
    <AppLayout title="SEO Keywords">
        <Head title="SEO Keywords" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
                        SEO Keywords
                    </h1>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Track keyword rankings and performance
                    </p>
                </div>
                <Link
                    :href="route('seo.dashboard')"
                    class="inline-flex items-center px-4 py-2 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm font-medium text-zinc-700 dark:text-zinc-300 bg-white dark:bg-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-700"
                >
                    ← Back to Dashboard
                </Link>
            </div>

            <!-- Filters -->
            <div class="bg-white dark:bg-zinc-900 rounded-lg border border-zinc-200 dark:border-zinc-800 p-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">
                            Search
                        </label>
                        <input
                            v-model="search"
                            @keyup.enter="applyFilters"
                            type="text"
                            placeholder="Search keywords..."
                            class="w-full px-3 py-2 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 dark:placeholder-zinc-500"
                        />
                    </div>

                    <InlineSelect
                        v-model="selectedIntent"
                        label="Intent"
                        :options="[
                            { value: 'all', label: 'All Intents' },
                            { value: 'informational', label: 'Informational' },
                            { value: 'commercial', label: 'Commercial' },
                            { value: 'transactional', label: 'Transactional' },
                            { value: 'navigational', label: 'Navigational' },
                        ]"
                        @update:modelValue="applyFilters"
                    />

                    <InlineSelect
                        v-model="selectedStatus"
                        label="Status"
                        :options="[
                            { value: 'all', label: 'All Statuses' },
                            { value: 'tracking', label: 'Tracking' },
                            { value: 'paused', label: 'Paused' },
                            { value: 'archived', label: 'Archived' },
                        ]"
                        @update:modelValue="applyFilters"
                    />

                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">
                            Filters
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                v-model="topOnly"
                                type="checkbox"
                                @change="applyFilters"
                                class="rounded border-zinc-300 dark:border-zinc-700 text-blue-600 focus:ring-blue-500 dark:bg-zinc-800"
                            />
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">Top 20 only</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Keywords Table -->
            <div class="bg-white dark:bg-zinc-900 rounded-lg border border-zinc-200 dark:border-zinc-800 overflow-hidden">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Keyword
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Page
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Intent
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Position
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Change
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Leads
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Revenue
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        <tr
                            v-for="keyword in keywords.data"
                            :key="keyword.id"
                            class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition"
                        >
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <div>
                                        <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ keyword.keyword }}
                                        </p>
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium mt-1"
                                            :class="getStatusColor(keyword.status)"
                                        >
                                            {{ keyword.status }}
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 max-w-xs truncate">
                                    {{ keyword.seo_page.page_title }}
                                </p>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                    :class="getIntentColor(keyword.intent)"
                                >
                                    {{ keyword.intent }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <span
                                    class="text-lg font-bold"
                                    :class="getPositionColor(keyword.current_position)"
                                >
                                    {{ keyword.current_position }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <span v-html="getPositionChangeIcon(keyword.position_change)"></span>
                                    <span
                                        v-if="keyword.position_change !== 0"
                                        class="text-xs font-medium"
                                        :class="keyword.position_change > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'"
                                    >
                                        {{ Math.abs(keyword.position_change) }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-zinc-900 dark:text-white">
                                {{ keyword.total_leads }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                {{ keyword.total_revenue_formatted }}
                            </td>
                        </tr>
                        <tr v-if="keywords.data.length === 0">
                            <td colspan="7" class="px-6 py-12 text-center text-zinc-500 dark:text-zinc-400">
                                No keywords found
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div v-if="keywords.last_page > 1" class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">
                            Showing {{ keywords.data.length }} of {{ keywords.total }} keywords
                        </div>
                        <div class="flex gap-2">
                            <Link
                                v-if="keywords.current_page > 1"
                                :href="route('seo.keywords', { ...filters, page: keywords.current_page - 1 })"
                                class="px-3 py-1 text-sm border border-zinc-300 dark:border-zinc-700 rounded text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                            >
                                Previous
                            </Link>
                            <Link
                                v-if="keywords.current_page < keywords.last_page"
                                :href="route('seo.keywords', { ...filters, page: keywords.current_page + 1 })"
                                class="px-3 py-1 text-sm border border-zinc-300 dark:border-zinc-700 rounded text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                            >
                                Next
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
