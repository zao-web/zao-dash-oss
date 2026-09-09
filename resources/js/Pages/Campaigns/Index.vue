<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormCheckbox from '@/Components/FormCheckbox.vue';
import { ref } from 'vue';

interface Campaign {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    status: string;
    type: string;
    icp_name: string | null;
    sequences_count: number;
    messages_count: number;
    sent_count: number;
    metrics: Record<string, number>;
    created_at: string;
}

interface ICP {
    id: number;
    name: string;
}

const props = defineProps<{
    campaigns: Campaign[];
    stats: {
        total: number;
        active: number;
        messages_sent: number;
        replies: number;
        reply_rate: number;
    };
    icps: ICP[];
}>();

const showCreateModal = ref(false);
const form = ref({
    name: '',
    description: '',
    type: 'cold_outreach',
    icp_id: '',
    min_icp_score: 60,
    use_email: true,
    use_linkedin: false,
});

const createCampaign = () => {
    router.post('/campaigns', form.value, {
        onSuccess: () => {
            showCreateModal.value = false;
            form.value = {
                name: '',
                description: '',
                type: 'cold_outreach',
                icp_id: '',
                min_icp_score: 60,
                use_email: true,
                use_linkedin: false,
            };
        },
    });
};

const getStatusBadge = (status: string) => {
    const badges: Record<string, { class: string; text: string }> = {
        draft: { class: 'bg-zinc-500/20 text-zinc-400', text: 'Draft' },
        active: { class: 'bg-emerald-500/20 text-emerald-400', text: 'Active' },
        paused: { class: 'bg-amber-500/20 text-amber-400', text: 'Paused' },
        completed: { class: 'bg-purple-500/20 text-purple-400', text: 'Completed' },
    };
    return badges[status] || badges.draft;
};

const getTypeBadge = (type: string) => {
    const badges: Record<string, { class: string; text: string; icon: string }> = {
        cold_outreach: { class: 'bg-blue-500/20 text-blue-400', text: 'Cold Outreach', icon: '❄️' },
        nurture: { class: 'bg-emerald-500/20 text-emerald-400', text: 'Nurture', icon: '🌱' },
        reengagement: { class: 'bg-purple-500/20 text-purple-400', text: 'Re-engagement', icon: '🔄' },
    };
    return badges[type] || badges.cold_outreach;
};
</script>

<template>
    <AppLayout>
        <Head title="Outreach Campaigns" />

        <div class="p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-white">Outreach Campaigns</h1>
                    <p class="text-zinc-400 text-sm mt-1">Automated multi-channel prospect outreach</p>
                </div>
                <button
                    @click="showCreateModal = true"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-lg transition-colors"
                >
                    New Campaign
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-5 gap-4">
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-white">{{ stats.total }}</div>
                    <div class="text-xs text-zinc-500 uppercase">Campaigns</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-emerald-400">{{ stats.active }}</div>
                    <div class="text-xs text-zinc-500 uppercase">Active</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-blue-400">{{ stats.messages_sent }}</div>
                    <div class="text-xs text-zinc-500 uppercase">Sent</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-purple-400">{{ stats.replies }}</div>
                    <div class="text-xs text-zinc-500 uppercase">Replies</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-white">{{ stats.reply_rate }}%</div>
                    <div class="text-xs text-zinc-500 uppercase">Reply Rate</div>
                </div>
            </div>

            <!-- Campaigns List -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl">
                <div class="border-b border-zinc-800 px-6 py-4">
                    <h2 class="text-lg font-medium text-white">All Campaigns</h2>
                </div>

                <div v-if="campaigns.length" class="divide-y divide-zinc-800">
                    <Link
                        v-for="campaign in campaigns"
                        :key="campaign.id"
                        :href="`/campaigns/${campaign.id}`"
                        class="block px-6 py-4 hover:bg-zinc-800/50 transition-colors"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-zinc-800 rounded-lg flex items-center justify-center">
                                    <span class="text-2xl">{{ getTypeBadge(campaign.type).icon }}</span>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-white font-medium">{{ campaign.name }}</span>
                                        <span :class="[getStatusBadge(campaign.status).class, 'px-2 py-0.5 rounded text-xs']">
                                            {{ getStatusBadge(campaign.status).text }}
                                        </span>
                                    </div>
                                    <div class="text-zinc-500 text-sm flex items-center gap-3 mt-1">
                                        <span>{{ campaign.sequences_count }} steps</span>
                                        <span>{{ campaign.sent_count }}/{{ campaign.messages_count }} sent</span>
                                        <span v-if="campaign.icp_name">ICP: {{ campaign.icp_name }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-6">
                                <div class="text-right">
                                    <div class="text-white font-medium">
                                        {{ campaign.metrics.enrolled || 0 }}
                                    </div>
                                    <div class="text-zinc-500 text-xs">Enrolled</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-emerald-400 font-medium">
                                        {{ campaign.metrics.converted || 0 }}
                                    </div>
                                    <div class="text-zinc-500 text-xs">Converted</div>
                                </div>
                                <span :class="[getTypeBadge(campaign.type).class, 'px-2 py-1 rounded text-xs']">
                                    {{ getTypeBadge(campaign.type).text }}
                                </span>
                            </div>
                        </div>
                    </Link>
                </div>

                <div v-else class="px-6 py-12 text-center">
                    <div class="text-4xl mb-3">📧</div>
                    <h3 class="text-lg font-medium text-white mb-2">No Campaigns Yet</h3>
                    <p class="text-zinc-400 text-sm mb-4">
                        Create your first outreach campaign to start engaging prospects.
                    </p>
                    <button
                        @click="showCreateModal = true"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-lg transition-colors"
                    >
                        Create Campaign
                    </button>
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <Modal :show="showCreateModal" @close="showCreateModal = false" max-width="lg">
            <div class="p-6">
                <h3 class="text-lg font-medium text-white mb-4">Create Campaign</h3>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm text-zinc-400 mb-1">Campaign Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="Q1 SaaS Outreach"
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                        />
                    </div>

                    <div>
                        <label class="block text-sm text-zinc-400 mb-1">Description</label>
                        <textarea
                            v-model="form.description"
                            rows="2"
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                        ></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-zinc-400 mb-1">Type</label>
                            <select
                                v-model="form.type"
                                class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                            >
                                <option value="cold_outreach">Cold Outreach</option>
                                <option value="nurture">Nurture</option>
                                <option value="reengagement">Re-engagement</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-zinc-400 mb-1">Target ICP</label>
                            <select
                                v-model="form.icp_id"
                                class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                            >
                                <option value="">All ICPs</option>
                                <option v-for="icp in icps" :key="icp.id" :value="icp.id">
                                    {{ icp.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm text-zinc-400 mb-1">Minimum ICP Score</label>
                        <input
                            v-model.number="form.min_icp_score"
                            type="number"
                            min="0"
                            max="100"
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"
                        />
                    </div>

                    <div>
                        <label class="block text-sm text-zinc-400 mb-2">Channels</label>
                        <div class="flex gap-4">
                            <FormCheckbox v-model="form.use_email" label="Email" />
                            <FormCheckbox v-model="form.use_linkedin" label="LinkedIn" />
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button
                        @click="showCreateModal = false"
                        class="px-4 py-2 text-zinc-400 hover:text-white transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="createCampaign"
                        :disabled="!form.name"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-lg transition-colors disabled:opacity-50"
                    >
                        Create
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
