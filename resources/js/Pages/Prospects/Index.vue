<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref, watch } from 'vue';
import debounce from 'lodash/debounce';

interface Prospect {
    id: number;
    company_name: string;
    company_website: string | null;
    contact_name: string | null;
    contact_title: string | null;
    contact_email: string | null;
    industry: string | null;
    company_size: string | null;
    icp_score: number;
    icp_name: string | null;
    status: string;
    source: string;
    signals: string[];
    created_at: string;
}

interface ICP {
    id: number;
    name: string;
    slug: string;
}

interface Pagination {
    data: Prospect[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    prospects: Pagination;
    stats: {
        total: number;
        qualified: number;
        new: number;
        converted: number;
        avg_score: number;
    };
    icps: ICP[];
    filters: {
        status?: string;
        icp_id?: string;
        min_score?: string;
        search?: string;
    };
}>();

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');
const icpId = ref(props.filters.icp_id || '');
const minScore = ref(props.filters.min_score || '');

const applyFilters = debounce(() => {
    router.get('/prospects', {
        search: search.value || undefined,
        status: status.value || undefined,
        icp_id: icpId.value || undefined,
        min_score: minScore.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([search, status, icpId, minScore], applyFilters);

const getStatusBadge = (status: string) => {
    const badges: Record<string, { class: string; text: string }> = {
        new: { class: 'bg-blue-500/20 text-blue-400', text: 'New' },
        researching: { class: 'bg-purple-500/20 text-purple-400', text: 'Researching' },
        qualified: { class: 'bg-emerald-500/20 text-emerald-400', text: 'Qualified' },
        unqualified: { class: 'bg-zinc-500/20 text-zinc-400', text: 'Unqualified' },
        converted: { class: 'bg-amber-500/20 text-amber-400', text: 'Converted' },
    };
    return badges[status] || badges.new;
};

const getScoreColor = (score: number) => {
    if (score >= 80) return 'text-emerald-400';
    if (score >= 60) return 'text-blue-400';
    if (score >= 40) return 'text-amber-400';
    return 'text-zinc-400';
};

const getScoreBg = (score: number) => {
    if (score >= 80) return 'bg-emerald-500';
    if (score >= 60) return 'bg-blue-500';
    if (score >= 40) return 'bg-amber-500';
    return 'bg-zinc-500';
};
</script>

<template>
    <AppLayout>
        <Head title="Prospects" />

        <div class="p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-white">Prospects</h1>
                    <p class="text-zinc-400 text-sm mt-1">Pre-qualified leads from research and outreach</p>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-5 gap-4">
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-white">{{ stats.total }}</div>
                    <div class="text-xs text-zinc-500 uppercase">Total</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-blue-400">{{ stats.new }}</div>
                    <div class="text-xs text-zinc-500 uppercase">New</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-emerald-400">{{ stats.qualified }}</div>
                    <div class="text-xs text-zinc-500 uppercase">Qualified</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-amber-400">{{ stats.converted }}</div>
                    <div class="text-xs text-zinc-500 uppercase">Converted</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-white">{{ stats.avg_score }}</div>
                    <div class="text-xs text-zinc-500 uppercase">Avg Score</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                <div class="grid grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs text-zinc-500 uppercase mb-1">Search</label>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Company, contact, email..."
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500"
                        />
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-500 uppercase mb-1">Status</label>
                        <select
                            v-model="status"
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500"
                        >
                            <option value="">All Statuses</option>
                            <option value="new">New</option>
                            <option value="researching">Researching</option>
                            <option value="qualified">Qualified</option>
                            <option value="unqualified">Unqualified</option>
                            <option value="converted">Converted</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-500 uppercase mb-1">ICP</label>
                        <select
                            v-model="icpId"
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500"
                        >
                            <option value="">All ICPs</option>
                            <option v-for="icp in icps" :key="icp.id" :value="icp.id">
                                {{ icp.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-500 uppercase mb-1">Min Score</label>
                        <select
                            v-model="minScore"
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500"
                        >
                            <option value="">Any Score</option>
                            <option value="80">80+ (Hot)</option>
                            <option value="60">60+ (Qualified)</option>
                            <option value="40">40+ (Warm)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Prospects Table -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">
                <table class="w-full">
                    <thead class="bg-zinc-800/50">
                        <tr>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">Company</th>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">Contact</th>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">Industry</th>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">ICP Score</th>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">Status</th>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">Source</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800">
                        <tr
                            v-for="prospect in prospects.data"
                            :key="prospect.id"
                            class="hover:bg-zinc-800/50 cursor-pointer"
                            @click="router.visit(`/prospects/${prospect.id}`)"
                        >
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-zinc-800 rounded-lg flex items-center justify-center">
                                        <span class="text-lg">🏢</span>
                                    </div>
                                    <div>
                                        <div class="text-white font-medium">{{ prospect.company_name }}</div>
                                        <a
                                            v-if="prospect.company_website"
                                            :href="prospect.company_website"
                                            target="_blank"
                                            @click.stop
                                            class="text-xs text-zinc-500 hover:text-blue-400"
                                        >
                                            {{ prospect.company_website.replace(/^https?:\/\//, '').split('/')[0] }}
                                        </a>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div v-if="prospect.contact_name">
                                    <div class="text-white text-sm">{{ prospect.contact_name }}</div>
                                    <div class="text-zinc-500 text-xs">{{ prospect.contact_title }}</div>
                                </div>
                                <span v-else class="text-zinc-600 text-sm">—</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-zinc-400 text-sm">{{ prospect.industry || '—' }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-16 h-1.5 bg-zinc-700 rounded-full overflow-hidden">
                                        <div
                                            :class="getScoreBg(prospect.icp_score)"
                                            class="h-full rounded-full"
                                            :style="{ width: `${prospect.icp_score}%` }"
                                        ></div>
                                    </div>
                                    <span :class="[getScoreColor(prospect.icp_score), 'text-sm font-medium']">
                                        {{ prospect.icp_score }}
                                    </span>
                                </div>
                                <div v-if="prospect.icp_name" class="text-xs text-zinc-500 mt-1">
                                    {{ prospect.icp_name }}
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span :class="[getStatusBadge(prospect.status).class, 'px-2 py-1 rounded text-xs']">
                                    {{ getStatusBadge(prospect.status).text }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-zinc-500 text-sm capitalize">{{ prospect.source }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="!prospects.data.length" class="px-6 py-12 text-center">
                    <div class="text-4xl mb-3">🔍</div>
                    <p class="text-zinc-400">No prospects found matching your filters.</p>
                </div>

                <!-- Pagination -->
                <div v-if="prospects.last_page > 1" class="border-t border-zinc-800 px-6 py-4 flex items-center justify-between">
                    <div class="text-zinc-500 text-sm">
                        Showing {{ (prospects.current_page - 1) * prospects.per_page + 1 }} to
                        {{ Math.min(prospects.current_page * prospects.per_page, prospects.total) }} of
                        {{ prospects.total }}
                    </div>
                    <div class="flex gap-1">
                        <template v-for="link in prospects.links" :key="link.label">
                            <button
                                v-if="link.url"
                                @click="router.visit(link.url)"
                                :class="link.active ? 'bg-blue-600 text-white' : 'bg-zinc-800 text-zinc-400 hover:text-white'"
                                class="px-3 py-1 rounded text-sm"
                                v-html="link.label"
                            ></button>
                            <span v-else class="px-3 py-1 text-zinc-600 text-sm" v-html="link.label"></span>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
