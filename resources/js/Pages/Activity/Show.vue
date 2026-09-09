<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type ClientStatus = 'not_started' | 'in_progress' | 'waiting_on_client' | 'completed_recently';

interface ActivitySource {
    type: string | null;
    external_id: string;
    url: string | null;
}

interface ActivityItem {
    id: number;
    title: string;
    client_summary: string;
    client_status: ClientStatus;
    internal_status: string;
    first_raised_at: string | null;
    last_activity_at: string | null;
    sources: ActivitySource[];
    project: { id: number; name: string; slug: string } | null;
}

interface PageClient {
    id: number;
    name: string;
    slug: string;
}

interface PageProject {
    id: number;
    name: string;
    slug: string;
}

const props = defineProps<{
    client: PageClient;
    project: PageProject | null;
    items: ActivityItem[];
    generated_at: string | null;
}>();

const STATUS_LABELS: Record<ClientStatus, string> = {
    not_started: 'Open',
    in_progress: 'In progress',
    waiting_on_client: 'Waiting on you',
    completed_recently: 'Recently completed',
};

const STATUS_ORDER: ClientStatus[] = ['not_started', 'in_progress', 'waiting_on_client', 'completed_recently'];

const grouped = computed(() => {
    const buckets: Record<ClientStatus, ActivityItem[]> = {
        not_started: [],
        in_progress: [],
        waiting_on_client: [],
        completed_recently: [],
    };
    for (const item of props.items) {
        const bucket = buckets[item.client_status];
        if (bucket) {
            bucket.push(item);
        }
    }
    return buckets;
});

const refreshing = ref(false);

function refresh() {
    refreshing.value = true;
    router.post(
        `/clients/${props.client.slug}/activity/refresh`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                refreshing.value = false;
            },
        },
    );
}

function sourceLabel(source: ActivitySource): string {
    if (source.type === 'slack') return 'Slack';
    if (source.type === 'email') return 'Email';
    if (source.type === 'github_pr') {
        const num = source.external_id.split(':').pop();
        return `PR #${num}`;
    }
    if (source.type === 'github_issue') {
        const num = source.external_id.split(':').pop();
        return `Issue #${num}`;
    }
    if (source.type === 'internal_task') return 'Task';
    return source.type ?? 'source';
}

function fmtDate(d: string | null): string {
    if (!d) return '';
    try {
        return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    } catch {
        return d;
    }
}

const generatedAtLabel = computed(() => {
    if (!props.generated_at) return 'Never synced';
    try {
        return new Date(props.generated_at).toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
    } catch {
        return props.generated_at;
    }
});
</script>

<template>
    <AppLayout :title="`${client.name} — Activity`">
        <div class="px-6 py-8 max-w-5xl mx-auto">
            <div class="flex items-start justify-between gap-4 mb-6">
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <Link :href="`/clients/${client.slug}`" class="hover:underline">{{ client.name }}</Link>
                        <template v-if="project">
                            <span class="mx-1">·</span>
                            <Link :href="`/clients/${client.slug}/projects/${project.slug}`" class="hover:underline">
                                {{ project.name }}
                            </Link>
                        </template>
                    </div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Current activity</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        What's in flight across Slack, email, GitHub, and internal tasks. Last sync: {{ generatedAtLabel }}.
                    </p>
                </div>
                <button
                    type="button"
                    class="px-3 py-1.5 text-sm rounded-md border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    {{ refreshing ? 'Refreshing…' : 'Refresh now' }}
                </button>
            </div>

            <div v-if="items.length === 0" class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 p-8 text-center">
                <p class="text-gray-500 dark:text-gray-400">Nothing currently in flight.</p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">
                    The activity feed runs hourly during business hours. Click Refresh to regenerate now.
                </p>
            </div>

            <div v-else class="space-y-8">
                <section v-for="status in STATUS_ORDER" :key="status">
                    <template v-if="grouped[status].length > 0">
                        <h2 class="text-sm font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">
                            {{ STATUS_LABELS[status] }}
                            <span class="ml-1 text-gray-400">({{ grouped[status].length }})</span>
                        </h2>
                        <ul class="space-y-3">
                            <li
                                v-for="item in grouped[status]"
                                :key="item.id"
                                class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ item.title }}</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-300 mt-1 whitespace-pre-line">
                                            {{ item.client_summary }}
                                        </p>
                                        <div class="flex flex-wrap items-center gap-2 mt-3 text-xs text-gray-500 dark:text-gray-400">
                                            <span v-if="item.first_raised_at">Raised {{ fmtDate(item.first_raised_at) }}</span>
                                            <span v-if="item.last_activity_at && item.last_activity_at !== item.first_raised_at">
                                                · Last update {{ fmtDate(item.last_activity_at) }}
                                            </span>
                                            <span v-if="item.project">· {{ item.project.name }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="item.sources.length" class="flex flex-wrap gap-2 mt-3">
                                    <component
                                        v-for="src in item.sources"
                                        :key="src.external_id"
                                        :is="src.url ? 'a' : 'span'"
                                        :href="src.url ?? undefined"
                                        :target="src.url ? '_blank' : undefined"
                                        rel="noopener"
                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300"
                                        :class="src.url ? 'hover:bg-gray-50 dark:hover:bg-gray-800' : ''"
                                    >
                                        {{ sourceLabel(src) }}
                                    </component>
                                </div>
                            </li>
                        </ul>
                    </template>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
