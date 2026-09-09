<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SequenceBuilder from '@/Components/SequenceBuilder.vue';
import { ref } from 'vue';

interface Sequence {
    id: number;
    step_number: number;
    channel: string;
    subject_template: string | null;
    body_template: string;
    delay_days: number;
    condition: string;
    requires_approval: boolean;
    is_active: boolean;
}

interface Message {
    id: number;
    prospect_name: string | null;
    contact_name: string | null;
    channel: string;
    subject: string | null;
    status: string;
    scheduled_for: string | null;
    sent_at: string | null;
    opened_at: string | null;
    replied_at: string | null;
}

interface Campaign {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    status: string;
    type: string;
    min_icp_score: number;
    target_industries: string[];
    target_titles: string[];
    use_email: boolean;
    use_linkedin: boolean;
    use_phone: boolean;
    icp: { id: number; name: string } | null;
    created_at: string;
}

const props = defineProps<{
    campaign: Campaign;
    sequences: Sequence[];
    messages: Message[];
    metrics: {
        enrolled: number;
        sent: number;
        opened: number;
        replied: number;
        converted: number;
    };
}>();

const activeTab = ref<'overview' | 'sequences' | 'messages'>('overview');

const getStatusBadge = (status: string) => {
    const badges: Record<string, { class: string; text: string }> = {
        draft: { class: 'bg-zinc-500/20 text-zinc-400', text: 'Draft' },
        active: { class: 'bg-emerald-500/20 text-emerald-400', text: 'Active' },
        paused: { class: 'bg-amber-500/20 text-amber-400', text: 'Paused' },
        completed: { class: 'bg-purple-500/20 text-purple-400', text: 'Completed' },
    };
    return badges[status] || badges.draft;
};

const getMessageStatusBadge = (status: string) => {
    const badges: Record<string, { class: string; text: string }> = {
        draft: { class: 'bg-zinc-500/20 text-zinc-400', text: 'Draft' },
        approved: { class: 'bg-blue-500/20 text-blue-400', text: 'Approved' },
        scheduled: { class: 'bg-purple-500/20 text-purple-400', text: 'Scheduled' },
        sent: { class: 'bg-emerald-500/20 text-emerald-400', text: 'Sent' },
        opened: { class: 'bg-blue-500/20 text-blue-400', text: 'Opened' },
        replied: { class: 'bg-amber-500/20 text-amber-400', text: 'Replied' },
    };
    return badges[status] || badges.draft;
};

const getChannelIcon = (channel: string) => {
    const icons: Record<string, string> = {
        email: '📧',
        linkedin: '💼',
        phone: '📞',
    };
    return icons[channel] || '📧';
};

const getConditionLabel = (condition: string) => {
    const labels: Record<string, string> = {
        always: 'Always send',
        no_reply: 'If no reply',
        opened: 'If opened',
        not_opened: 'If not opened',
    };
    return labels[condition] || condition;
};

const calculateRate = (numerator: number, denominator: number) => {
    if (denominator === 0) return '0%';
    return ((numerator / denominator) * 100).toFixed(1) + '%';
};

const activateCampaign = () => {
    router.post(`/campaigns/${props.campaign.id}/activate`);
};

const pauseCampaign = () => {
    router.post(`/campaigns/${props.campaign.id}/pause`);
};
</script>

<template>
    <AppLayout>
        <Head :title="`Campaign: ${campaign.name}`" />

        <div class="p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <Link href="/campaigns" class="text-zinc-500 hover:text-zinc-300">
                            ← Campaigns
                        </Link>
                    </div>
                    <h1 class="text-2xl font-semibold text-white">{{ campaign.name }}</h1>
                    <p v-if="campaign.description" class="text-zinc-400 text-sm mt-1">{{ campaign.description }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        v-if="campaign.status === 'draft' || campaign.status === 'paused'"
                        @click="activateCampaign"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg transition-colors"
                    >
                        Activate
                    </button>
                    <button
                        v-if="campaign.status === 'active'"
                        @click="pauseCampaign"
                        class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded-lg transition-colors"
                    >
                        Pause
                    </button>
                    <span :class="[getStatusBadge(campaign.status).class, 'px-3 py-1 rounded-full text-sm font-medium']">
                        {{ getStatusBadge(campaign.status).text }}
                    </span>
                </div>
            </div>

            <!-- Metrics -->
            <div class="grid grid-cols-5 gap-4">
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-white">{{ metrics.enrolled }}</div>
                    <div class="text-xs text-zinc-500 uppercase">Enrolled</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-blue-400">{{ metrics.sent }}</div>
                    <div class="text-xs text-zinc-500 uppercase">Sent</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-purple-400">{{ metrics.opened }}</div>
                    <div class="text-xs text-zinc-500 uppercase">
                        Opened ({{ calculateRate(metrics.opened, metrics.sent) }})
                    </div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-amber-400">{{ metrics.replied }}</div>
                    <div class="text-xs text-zinc-500 uppercase">
                        Replied ({{ calculateRate(metrics.replied, metrics.sent) }})
                    </div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-2xl font-semibold text-emerald-400">{{ metrics.converted }}</div>
                    <div class="text-xs text-zinc-500 uppercase">
                        Converted ({{ calculateRate(metrics.converted, metrics.enrolled) }})
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-zinc-800">
                <div class="flex gap-6">
                    <button
                        @click="activeTab = 'overview'"
                        :class="activeTab === 'overview' ? 'text-white border-blue-500' : 'text-zinc-400 border-transparent'"
                        class="pb-3 border-b-2 transition-colors"
                    >
                        Overview
                    </button>
                    <button
                        @click="activeTab = 'sequences'"
                        :class="activeTab === 'sequences' ? 'text-white border-blue-500' : 'text-zinc-400 border-transparent'"
                        class="pb-3 border-b-2 transition-colors"
                    >
                        Sequences ({{ sequences.length }})
                    </button>
                    <button
                        @click="activeTab = 'messages'"
                        :class="activeTab === 'messages' ? 'text-white border-blue-500' : 'text-zinc-400 border-transparent'"
                        class="pb-3 border-b-2 transition-colors"
                    >
                        Messages ({{ messages.length }})
                    </button>
                </div>
            </div>

            <!-- Overview Tab -->
            <div v-if="activeTab === 'overview'" class="grid grid-cols-2 gap-6">
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                    <h3 class="text-sm font-medium text-zinc-400 uppercase mb-4">Campaign Settings</h3>
                    <div class="space-y-4">
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Type</span>
                            <span class="text-white capitalize">{{ campaign.type.replace('_', ' ') }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Target ICP</span>
                            <span class="text-white">{{ campaign.icp?.name || 'All' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Min ICP Score</span>
                            <span class="text-white">{{ campaign.min_icp_score }}+</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Channels</span>
                            <span class="text-white">
                                <span v-if="campaign.use_email">📧 Email</span>
                                <span v-if="campaign.use_linkedin" class="ml-2">💼 LinkedIn</span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                    <h3 class="text-sm font-medium text-zinc-400 uppercase mb-4">Targeting</h3>
                    <div class="space-y-4">
                        <div v-if="campaign.target_industries.length">
                            <div class="text-zinc-500 text-sm mb-2">Industries</div>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="industry in campaign.target_industries"
                                    :key="industry"
                                    class="px-2 py-1 bg-zinc-800 rounded text-sm text-white"
                                >
                                    {{ industry }}
                                </span>
                            </div>
                        </div>
                        <div v-if="campaign.target_titles.length">
                            <div class="text-zinc-500 text-sm mb-2">Titles</div>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="title in campaign.target_titles"
                                    :key="title"
                                    class="px-2 py-1 bg-zinc-800 rounded text-sm text-white"
                                >
                                    {{ title }}
                                </span>
                            </div>
                        </div>
                        <div v-if="!campaign.target_industries.length && !campaign.target_titles.length" class="text-zinc-500 text-sm">
                            No specific targeting configured.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sequences Tab -->
            <div v-if="activeTab === 'sequences'">
                <SequenceBuilder
                    :campaign-id="campaign.id"
                    :sequences="sequences"
                    :readonly="campaign.status === 'completed'"
                />
            </div>

            <!-- Messages Tab -->
            <div v-if="activeTab === 'messages'" class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">
                <table v-if="messages.length" class="w-full">
                    <thead class="bg-zinc-800/50">
                        <tr>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">Prospect</th>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">Channel</th>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">Subject</th>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">Status</th>
                            <th class="text-left text-xs text-zinc-500 uppercase px-6 py-3">Sent</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800">
                        <tr v-for="message in messages" :key="message.id">
                            <td class="px-6 py-4">
                                <div class="text-white">{{ message.prospect_name }}</div>
                                <div class="text-zinc-500 text-xs">{{ message.contact_name }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-lg">{{ getChannelIcon(message.channel) }}</span>
                            </td>
                            <td class="px-6 py-4 text-zinc-400 text-sm">
                                {{ message.subject || '—' }}
                            </td>
                            <td class="px-6 py-4">
                                <span :class="[getMessageStatusBadge(message.status).class, 'px-2 py-1 rounded text-xs']">
                                    {{ getMessageStatusBadge(message.status).text }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-zinc-500 text-sm">
                                {{ message.sent_at ? new Date(message.sent_at).toLocaleDateString() : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-else class="px-6 py-12 text-center">
                    <p class="text-zinc-400">No messages sent yet.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
