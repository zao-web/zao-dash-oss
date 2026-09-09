<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import MarkdownRenderer from '@/Components/MarkdownRenderer.vue';

interface Agent {
    id: number;
    name: string;
    slug: string;
    model: string;
}

interface AgentRun {
    id: number;
    task: string;
    context: Record<string, unknown>;
    status: string;
}

interface Approval {
    id: number;
    action_type: string;
    description: string;
    risk_level: 'low' | 'medium' | 'high' | 'critical';
    status: 'pending' | 'approved' | 'rejected' | 'expired';
    payload: Record<string, unknown>;
    agent: Agent | null;
    run: AgentRun | null;
    decided_by: string | null;
    decision_note: string | null;
    expires_at: string | null;
    decided_at: string | null;
    created_at: string;
}

interface SimilarApproval {
    id: number;
    status: string;
    description: string;
    decided_at: string | null;
}

const props = defineProps<{
    approval: Approval;
    similar_approvals: SimilarApproval[];
}>();

const approvalComment = ref('');
const rejectReason = ref('');
const isSubmitting = ref(false);
const showRawPayload = ref(false);
const rejectReasonError = ref(false);
const rejectReasonInput = ref<HTMLTextAreaElement | null>(null);

const payloadPrompt = computed(() => {
    const prompt = props.approval.payload?.prompt;
    return typeof prompt === 'string' ? prompt : null;
});

const payloadContext = computed(() => {
    const ctx = props.approval.payload?.context;
    return ctx && typeof ctx === 'object' ? ctx : null;
});

const hasStructuredPayload = computed(() => {
    return payloadPrompt.value || payloadContext.value;
});

const submitApprove = () => {
    isSubmitting.value = true;
    router.post(route('approvals.approve', props.approval.id), {
        comment: approvalComment.value,
    }, {
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

const submitReject = () => {
    if (!rejectReason.value.trim()) {
        rejectReasonError.value = true;
        rejectReasonInput.value?.focus();
        rejectReasonInput.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    rejectReasonError.value = false;
    isSubmitting.value = true;
    router.post(route('approvals.reject', props.approval.id), {
        reason: rejectReason.value,
    }, {
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

const getRiskColor = (risk: string) => {
    const colors: Record<string, string> = {
        low: 'bg-emerald-500/20 text-emerald-400',
        medium: 'bg-amber-500/20 text-amber-400',
        high: 'bg-red-500/20 text-red-400',
        critical: 'bg-red-600/30 text-red-300',
    };
    return colors[risk] || colors.medium;
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        pending: 'bg-amber-500/20 text-amber-400',
        approved: 'bg-emerald-500/20 text-emerald-400',
        rejected: 'bg-red-500/20 text-red-400',
        expired: 'bg-zinc-500/20 text-zinc-400',
    };
    return colors[status] || colors.pending;
};

const getRiskDescription = (risk: string) => {
    const descriptions: Record<string, string> = {
        low: 'This action has minimal risk and can likely be auto-approved.',
        medium: 'This action may have some impact. Review before approving.',
        high: 'This action could have significant impact. Review carefully.',
        critical: 'This action could have irreversible effects. Verify all details.',
    };
    return descriptions[risk] || '';
};
</script>

<template>
    <AppLayout>
        <Head :title="`Approval: ${approval.action_type}`" />

        <div class="p-6 max-w-5xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <Link href="/approvals" class="text-zinc-400 hover:text-white">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </Link>
                        <h1 class="text-2xl font-semibold text-white">{{ approval.action_type }}</h1>
                        <span :class="[getRiskColor(approval.risk_level), 'px-2 py-0.5 rounded text-xs font-medium']">
                            {{ approval.risk_level }}
                        </span>
                        <span :class="[getStatusColor(approval.status), 'px-2 py-0.5 rounded text-xs font-medium']">
                            {{ approval.status }}
                        </span>
                    </div>
                    <p class="text-zinc-400">Requested {{ approval.created_at }}</p>
                </div>

                <div v-if="approval.status === 'pending'" class="flex gap-3">
                    <button
                        @click="submitReject"
                        :disabled="isSubmitting"
                        class="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 text-white rounded-lg transition-colors disabled:opacity-50"
                    >
                        Reject
                    </button>
                    <button
                        @click="submitApprove"
                        :disabled="isSubmitting"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg transition-colors disabled:opacity-50"
                    >
                        {{ isSubmitting ? 'Processing...' : 'Approve' }}
                    </button>
                </div>
            </div>

            <!-- Risk Warning -->
            <div v-if="approval.risk_level === 'high' || approval.risk_level === 'critical'"
                 class="bg-red-500/10 border border-red-500/20 rounded-lg p-4 flex items-start gap-3">
                <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div>
                    <p class="text-red-400 font-medium">{{ approval.risk_level === 'critical' ? 'Critical Risk' : 'High Risk' }}</p>
                    <p class="text-red-400/80 text-sm">{{ getRiskDescription(approval.risk_level) }}</p>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-6">
                <!-- Main Content -->
                <div class="col-span-2 space-y-6">
                    <!-- Description -->
                    <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-3">Description</h2>
                        <p class="text-white text-lg leading-relaxed">{{ approval.description }}</p>
                    </div>

                    <!-- Payload -->
                    <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider">Payload</h2>
                            <button
                                v-if="hasStructuredPayload"
                                @click="showRawPayload = !showRawPayload"
                                class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors"
                            >
                                {{ showRawPayload ? 'Show Formatted' : 'Show Raw JSON' }}
                            </button>
                        </div>

                        <!-- Raw JSON view -->
                        <pre v-if="showRawPayload || !hasStructuredPayload" class="bg-zinc-950 rounded-lg p-4 overflow-auto max-h-96 text-sm text-zinc-300 font-mono">{{ JSON.stringify(approval.payload, null, 2) }}</pre>

                        <!-- Structured view -->
                        <div v-else class="space-y-4">
                            <!-- Prompt -->
                            <div v-if="payloadPrompt">
                                <span class="text-zinc-500 text-sm">Prompt</span>
                                <div class="mt-2 bg-zinc-950 rounded-lg p-4 max-h-80 overflow-y-auto">
                                    <MarkdownRenderer :content="payloadPrompt" />
                                </div>
                            </div>

                            <!-- Context -->
                            <div v-if="payloadContext">
                                <span class="text-zinc-500 text-sm">Context</span>
                                <pre class="bg-zinc-950 rounded-lg p-3 mt-1 overflow-auto max-h-64 text-sm text-zinc-300 font-mono">{{ JSON.stringify(payloadContext, null, 2) }}</pre>
                            </div>
                        </div>
                    </div>

                    <!-- Agent Run Context -->
                    <div v-if="approval.run" class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-3">Agent Run Context</h2>
                        <div class="space-y-4">
                            <div>
                                <span class="text-zinc-500 text-sm">Task</span>
                                <div class="mt-2 bg-zinc-950 rounded-lg p-4 max-h-96 overflow-y-auto">
                                    <MarkdownRenderer :content="approval.run.task" />
                                </div>
                            </div>
                            <div v-if="approval.run.context && Object.keys(approval.run.context).length">
                                <span class="text-zinc-500 text-sm">Context</span>
                                <pre class="bg-zinc-950 rounded-lg p-3 mt-1 overflow-auto max-h-80 text-sm text-zinc-300 font-mono">{{ JSON.stringify(approval.run.context, null, 2) }}</pre>
                            </div>
                        </div>
                    </div>

                    <!-- Decision Form (for pending) -->
                    <div v-if="approval.status === 'pending'" class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 space-y-4">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider">Your Decision</h2>

                        <div>
                            <label class="block text-sm text-zinc-400 mb-2">Approval Comment (optional)</label>
                            <textarea
                                v-model="approvalComment"
                                rows="2"
                                placeholder="Add a note about this approval..."
                                class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white placeholder-zinc-500 resize-none"
                            ></textarea>
                        </div>

                        <div>
                            <label class="block text-sm text-zinc-400 mb-2">Rejection Reason (required to reject)</label>
                            <textarea
                                ref="rejectReasonInput"
                                v-model="rejectReason"
                                rows="2"
                                placeholder="Explain why this request should be rejected..."
                                :class="[
                                    'w-full bg-zinc-800 rounded-lg px-3 py-2 text-white placeholder-zinc-500 resize-none transition-colors',
                                    rejectReasonError && !rejectReason.trim()
                                        ? 'border-2 border-red-500 focus:border-red-500'
                                        : 'border border-zinc-700 focus:border-zinc-600'
                                ]"
                                @input="rejectReasonError = false"
                            ></textarea>
                            <p v-if="rejectReasonError && !rejectReason.trim()" class="text-red-400 text-xs mt-1">
                                Please provide a reason for rejection
                            </p>
                            <p v-else class="text-zinc-500 text-xs mt-1">The agent will use this feedback to improve future requests.</p>
                        </div>
                    </div>

                    <!-- Decision Info (for processed) -->
                    <div v-if="approval.status !== 'pending'" class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-3">Decision</h2>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-zinc-500">Status</span>
                                <span :class="[getStatusColor(approval.status), 'px-2 py-0.5 rounded text-xs font-medium']">
                                    {{ approval.status }}
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">Decided By</span>
                                <span class="text-white">{{ approval.decided_by || 'System' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">Decided At</span>
                                <span class="text-white">{{ approval.decided_at || 'N/A' }}</span>
                            </div>
                            <div v-if="approval.decision_note">
                                <span class="text-zinc-500 block mb-1">Note</span>
                                <p class="text-white bg-zinc-800 rounded-lg p-3">{{ approval.decision_note }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Agent Info -->
                    <div v-if="approval.agent" class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-3">Agent</h2>
                        <div class="space-y-3">
                            <div>
                                <span class="text-zinc-500 text-sm">Name</span>
                                <p class="text-white font-medium">{{ approval.agent.name }}</p>
                            </div>
                            <div>
                                <span class="text-zinc-500 text-sm">Model</span>
                                <p class="text-white">{{ approval.agent.model }}</p>
                            </div>
                            <Link :href="`/agents/${approval.agent.slug}`" class="text-blue-400 hover:text-blue-300 text-sm">
                                View Agent →
                            </Link>
                        </div>
                    </div>

                    <!-- Timeline -->
                    <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-3">Timeline</h2>
                        <div class="space-y-4">
                            <div class="flex gap-3">
                                <div class="w-2 h-2 rounded-full bg-blue-500 mt-2"></div>
                                <div>
                                    <p class="text-white text-sm">Created</p>
                                    <p class="text-zinc-500 text-xs">{{ approval.created_at }}</p>
                                </div>
                            </div>
                            <div v-if="approval.expires_at && approval.status === 'pending'" class="flex gap-3">
                                <div class="w-2 h-2 rounded-full bg-amber-500 mt-2"></div>
                                <div>
                                    <p class="text-white text-sm">Expires</p>
                                    <p class="text-zinc-500 text-xs">{{ approval.expires_at }}</p>
                                </div>
                            </div>
                            <div v-if="approval.decided_at" class="flex gap-3">
                                <div class="w-2 h-2 rounded-full" :class="approval.status === 'approved' ? 'bg-emerald-500' : 'bg-red-500'" style="margin-top: 0.5rem;"></div>
                                <div>
                                    <p class="text-white text-sm">{{ approval.status === 'approved' ? 'Approved' : 'Rejected' }}</p>
                                    <p class="text-zinc-500 text-xs">{{ approval.decided_at }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Similar Approvals -->
                    <div v-if="similar_approvals.length > 0" class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-3">Similar Approvals</h2>
                        <div class="space-y-3">
                            <Link
                                v-for="similar in similar_approvals"
                                :key="similar.id"
                                :href="`/approvals/${similar.id}`"
                                class="block p-3 bg-zinc-800 hover:bg-zinc-700 rounded-lg transition-colors"
                            >
                                <div class="flex items-center justify-between mb-1">
                                    <span :class="[getStatusColor(similar.status), 'px-2 py-0.5 rounded text-xs font-medium']">
                                        {{ similar.status }}
                                    </span>
                                    <span class="text-zinc-500 text-xs">{{ similar.decided_at }}</span>
                                </div>
                                <p class="text-zinc-300 text-sm">{{ similar.description }}</p>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
