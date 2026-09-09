<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import FormCheckbox from '@/Components/FormCheckbox.vue';

interface Sequence {
    id?: number;
    step_number: number;
    channel: string;
    subject_template: string | null;
    body_template: string;
    delay_days: number;
    condition: string;
    requires_approval: boolean;
    is_active: boolean;
}

const props = defineProps<{
    campaignId: number;
    sequences: Sequence[];
    readonly?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update', sequences: Sequence[]): void;
}>();

const showAddModal = ref(false);
const editingSequence = ref<Sequence | null>(null);
const saving = ref(false);

const channels = [
    { value: 'email', label: 'Email', icon: '📧' },
    { value: 'linkedin', label: 'LinkedIn', icon: '💼' },
    { value: 'phone', label: 'Phone', icon: '📞' },
    { value: 'manual', label: 'Manual Task', icon: '✋' },
];

const conditions = [
    { value: 'always', label: 'Always send' },
    { value: 'no_reply', label: 'If no reply' },
    { value: 'opened', label: 'If opened' },
    { value: 'not_opened', label: 'If not opened' },
];

const defaultSequence: Sequence = {
    step_number: 1,
    channel: 'email',
    subject_template: '',
    body_template: '',
    delay_days: 0,
    condition: 'always',
    requires_approval: true,
    is_active: true,
};

const formData = ref<Sequence>({ ...defaultSequence });

const sortedSequences = computed(() =>
    [...props.sequences].sort((a, b) => a.step_number - b.step_number)
);

const getChannelIcon = (channel: string) => {
    return channels.find(c => c.value === channel)?.icon || '📧';
};

const getConditionLabel = (condition: string) => {
    return conditions.find(c => c.value === condition)?.label || condition;
};

const openAddModal = () => {
    formData.value = {
        ...defaultSequence,
        step_number: props.sequences.length + 1,
        delay_days: props.sequences.length > 0 ? 3 : 0,
    };
    editingSequence.value = null;
    showAddModal.value = true;
};

const openEditModal = (sequence: Sequence) => {
    formData.value = { ...sequence };
    editingSequence.value = sequence;
    showAddModal.value = true;
};

const closeModal = () => {
    showAddModal.value = false;
    editingSequence.value = null;
    formData.value = { ...defaultSequence };
};

const saveSequence = async () => {
    saving.value = true;

    try {
        if (editingSequence.value?.id) {
            // Update existing
            router.put(`/campaigns/${props.campaignId}/sequences/${editingSequence.value.id}`, formData.value, {
                preserveScroll: true,
                onSuccess: () => closeModal(),
                onFinish: () => saving.value = false,
            });
        } else {
            // Create new
            router.post(`/campaigns/${props.campaignId}/sequences`, formData.value, {
                preserveScroll: true,
                onSuccess: () => closeModal(),
                onFinish: () => saving.value = false,
            });
        }
    } catch (e) {
        saving.value = false;
    }
};

const deleteSequence = (sequence: Sequence) => {
    if (!sequence.id) return;
    if (!confirm('Delete this sequence step?')) return;

    router.delete(`/campaigns/${props.campaignId}/sequences/${sequence.id}`, {
        preserveScroll: true,
    });
};

const toggleActive = (sequence: Sequence) => {
    if (!sequence.id) return;

    router.put(`/campaigns/${props.campaignId}/sequences/${sequence.id}`, {
        ...sequence,
        is_active: !sequence.is_active,
    }, {
        preserveScroll: true,
    });
};

const moveUp = (sequence: Sequence) => {
    if (sequence.step_number <= 1) return;
    reorderSequence(sequence, sequence.step_number - 1);
};

const moveDown = (sequence: Sequence) => {
    if (sequence.step_number >= props.sequences.length) return;
    reorderSequence(sequence, sequence.step_number + 1);
};

const reorderSequence = (sequence: Sequence, newPosition: number) => {
    if (!sequence.id) return;

    router.post(`/campaigns/${props.campaignId}/sequences/${sequence.id}/reorder`, {
        position: newPosition,
    }, {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="space-y-4">
        <!-- Header -->
        <div v-if="!readonly" class="flex items-center justify-between">
            <h3 class="text-lg font-medium text-white">Outreach Sequence</h3>
            <button
                @click="openAddModal"
                class="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white text-sm rounded-lg transition-colors"
            >
                + Add Step
            </button>
        </div>

        <!-- Empty State -->
        <div v-if="sequences.length === 0" class="bg-zinc-900 border border-zinc-800 border-dashed rounded-xl p-8 text-center">
            <div class="text-3xl mb-2">📬</div>
            <p class="text-zinc-400 mb-4">No sequence steps yet</p>
            <button
                v-if="!readonly"
                @click="openAddModal"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-lg transition-colors"
            >
                Create First Step
            </button>
        </div>

        <!-- Sequence Steps -->
        <div v-else class="space-y-3">
            <div
                v-for="(sequence, index) in sortedSequences"
                :key="sequence.id || index"
                :class="[
                    'bg-zinc-900 border rounded-xl p-4 transition-all',
                    sequence.is_active ? 'border-zinc-800' : 'border-zinc-800/50 opacity-60'
                ]"
            >
                <div class="flex items-start gap-4">
                    <!-- Step Number -->
                    <div class="flex flex-col items-center gap-1">
                        <div
                            :class="[
                                'w-10 h-10 rounded-full flex items-center justify-center text-lg font-medium',
                                sequence.is_active ? 'bg-blue-600 text-white' : 'bg-zinc-800 text-zinc-500'
                            ]"
                        >
                            {{ sequence.step_number }}
                        </div>
                        <!-- Reorder buttons -->
                        <div v-if="!readonly" class="flex flex-col gap-0.5">
                            <button
                                @click="moveUp(sequence)"
                                :disabled="sequence.step_number <= 1"
                                class="p-0.5 text-zinc-500 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed"
                            >
                                ▲
                            </button>
                            <button
                                @click="moveDown(sequence)"
                                :disabled="sequence.step_number >= sequences.length"
                                class="p-0.5 text-zinc-500 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed"
                            >
                                ▼
                            </button>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-xl">{{ getChannelIcon(sequence.channel) }}</span>
                            <span class="text-white font-medium capitalize">{{ sequence.channel }}</span>
                            <span v-if="sequence.requires_approval" class="px-2 py-0.5 bg-amber-500/20 text-amber-400 rounded text-xs">
                                Approval
                            </span>
                            <span v-if="!sequence.is_active" class="px-2 py-0.5 bg-zinc-700 text-zinc-400 rounded text-xs">
                                Disabled
                            </span>
                        </div>

                        <div v-if="sequence.subject_template" class="text-zinc-400 text-sm mb-1">
                            <span class="text-zinc-500">Subject:</span> {{ sequence.subject_template }}
                        </div>

                        <div class="text-zinc-500 text-sm line-clamp-2">
                            {{ sequence.body_template }}
                        </div>

                        <div class="flex items-center gap-4 mt-2 text-xs text-zinc-500">
                            <span>{{ sequence.delay_days === 0 ? 'Immediately' : `Wait ${sequence.delay_days} days` }}</span>
                            <span>•</span>
                            <span>{{ getConditionLabel(sequence.condition) }}</span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div v-if="!readonly" class="flex items-center gap-2">
                        <button
                            @click="toggleActive(sequence)"
                            :class="[
                                'p-2 rounded-lg transition-colors',
                                sequence.is_active ? 'text-emerald-400 hover:bg-emerald-500/20' : 'text-zinc-500 hover:bg-zinc-800'
                            ]"
                            :title="sequence.is_active ? 'Disable' : 'Enable'"
                        >
                            {{ sequence.is_active ? '✓' : '○' }}
                        </button>
                        <button
                            @click="openEditModal(sequence)"
                            class="p-2 text-zinc-400 hover:text-white hover:bg-zinc-800 rounded-lg transition-colors"
                            title="Edit"
                        >
                            ✎
                        </button>
                        <button
                            @click="deleteSequence(sequence)"
                            class="p-2 text-zinc-400 hover:text-red-400 hover:bg-red-500/20 rounded-lg transition-colors"
                            title="Delete"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <!-- Connector line -->
                <div v-if="index < sortedSequences.length - 1" class="ml-5 mt-3 h-4 border-l-2 border-dashed border-zinc-700"></div>
            </div>
        </div>

        <!-- Add/Edit Modal -->
        <Teleport to="body">
            <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="absolute inset-0 bg-black/50" @click="closeModal"></div>
                <div class="relative bg-zinc-900 border border-zinc-800 rounded-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-white mb-4">
                            {{ editingSequence ? 'Edit Step' : 'Add Sequence Step' }}
                        </h3>

                        <div class="space-y-4">
                            <!-- Channel -->
                            <div>
                                <label class="block text-sm text-zinc-400 mb-2">Channel</label>
                                <div class="grid grid-cols-4 gap-2">
                                    <button
                                        v-for="channel in channels"
                                        :key="channel.value"
                                        @click="formData.channel = channel.value"
                                        :class="[
                                            'p-3 rounded-lg border text-center transition-all',
                                            formData.channel === channel.value
                                                ? 'border-blue-500 bg-blue-500/20'
                                                : 'border-zinc-700 hover:border-zinc-600'
                                        ]"
                                    >
                                        <div class="text-xl mb-1">{{ channel.icon }}</div>
                                        <div class="text-xs text-zinc-300">{{ channel.label }}</div>
                                    </button>
                                </div>
                            </div>

                            <!-- Subject (for email) -->
                            <div v-if="formData.channel === 'email'">
                                <label class="block text-sm text-zinc-400 mb-2">Subject Template</label>
                                <input
                                    v-model="formData.subject_template"
                                    type="text"
                                    placeholder="Re: {{company_name}} - Quick question"
                                    class="w-full px-3 py-2 bg-zinc-800 border border-zinc-700 rounded-lg text-white placeholder-zinc-500 focus:outline-none focus:border-blue-500"
                                />
                                <p class="text-xs text-zinc-500 mt-1">Use {{variable}} for personalization</p>
                            </div>

                            <!-- Body Template -->
                            <div>
                                <label class="block text-sm text-zinc-400 mb-2">Message Template</label>
                                <textarea
                                    v-model="formData.body_template"
                                    rows="4"
                                    placeholder="Hi {{first_name}}, I noticed that {{company_name}}..."
                                    class="w-full px-3 py-2 bg-zinc-800 border border-zinc-700 rounded-lg text-white placeholder-zinc-500 focus:outline-none focus:border-blue-500 resize-none"
                                ></textarea>
                            </div>

                            <!-- Timing -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-zinc-400 mb-2">Delay (days)</label>
                                    <input
                                        v-model.number="formData.delay_days"
                                        type="number"
                                        min="0"
                                        class="w-full px-3 py-2 bg-zinc-800 border border-zinc-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm text-zinc-400 mb-2">Condition</label>
                                    <select
                                        v-model="formData.condition"
                                        class="w-full px-3 py-2 bg-zinc-800 border border-zinc-700 rounded-lg text-white focus:outline-none focus:border-blue-500"
                                    >
                                        <option v-for="cond in conditions" :key="cond.value" :value="cond.value">
                                            {{ cond.label }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <!-- Options -->
                            <div class="flex items-center gap-4">
                                <FormCheckbox
                                    v-model="formData.requires_approval"
                                    label="Requires approval before sending"
                                />
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-zinc-800">
                            <button
                                @click="closeModal"
                                class="px-4 py-2 text-zinc-400 hover:text-white transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                @click="saveSequence"
                                :disabled="saving || !formData.body_template"
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-500 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg transition-colors"
                            >
                                {{ saving ? 'Saving...' : (editingSequence ? 'Update' : 'Add Step') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
