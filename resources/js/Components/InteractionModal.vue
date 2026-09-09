<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import Modal from './Modal.vue';
import Button from './Button.vue';
import type { Interaction, InteractionState } from '@/composables/useInteractionRealtime';

const props = defineProps<{
    show: boolean;
    interaction: Interaction | null;
    state: InteractionState;
    remainingSeconds: number;
    error: string | null;
}>();

const emit = defineEmits<{
    submit: [response: string];
    dismiss: [];
    retry: [];
}>();

// Local response state
const textResponse = ref('');
const selectedOption = ref<string | null>(null);
const selectedOptions = ref<string[]>([]);

// Reset form when interaction changes
watch(() => props.interaction?.id, () => {
    textResponse.value = '';
    selectedOption.value = null;
    selectedOptions.value = [];
});

// Computed
const isMultiSelect = computed(() => props.interaction?.context?.multi_select === true);
const hasOptions = computed(() => (props.interaction?.options?.length ?? 0) > 0);
const isConfirm = computed(() => props.interaction?.question_type === 'confirm');
const isSubmitting = computed(() => props.state === 'submitting');
const isExpired = computed(() => props.state === 'expired');
const hasError = computed(() => props.state === 'error');

const canSubmit = computed(() => {
    if (props.state !== 'pending') return false;
    if (props.remainingSeconds <= 0) return false;

    if (props.interaction?.question_type === 'text') {
        return textResponse.value.trim().length > 0;
    }
    if (isMultiSelect.value) {
        return selectedOptions.value.length > 0;
    }
    return selectedOption.value !== null;
});

const formattedTime = computed(() => {
    const minutes = Math.floor(props.remainingSeconds / 60);
    const seconds = props.remainingSeconds % 60;
    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
});

const timeWarningClass = computed(() => {
    if (props.remainingSeconds <= 30) return 'text-status-failed animate-pulse';
    if (props.remainingSeconds <= 60) return 'text-status-warning';
    return 'text-text-secondary';
});

// Actions
const handleSubmit = () => {
    if (!canSubmit.value) return;

    let response: string;
    if (props.interaction?.question_type === 'text') {
        response = textResponse.value.trim();
    } else if (isMultiSelect.value) {
        response = JSON.stringify(selectedOptions.value);
    } else {
        response = selectedOption.value || '';
    }

    emit('submit', response);
};

const handleConfirm = (value: 'yes' | 'no') => {
    emit('submit', value);
};

const toggleOption = (label: string) => {
    const index = selectedOptions.value.indexOf(label);
    if (index === -1) {
        selectedOptions.value.push(label);
    } else {
        selectedOptions.value.splice(index, 1);
    }
};
</script>

<template>
    <Modal
        :show="show"
        :closeable="false"
        size="md"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <!-- Agent icon -->
                <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                    <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h2 class="text-lg font-semibold text-text-primary truncate">
                        {{ interaction?.agent_name || 'Agent' }} needs input
                    </h2>
                    <p class="text-sm text-text-secondary">
                        {{ interaction?.context?.header || 'Please respond to continue' }}
                    </p>
                </div>
                <!-- Timer -->
                <div :class="['flex items-center gap-1.5 text-sm font-medium', timeWarningClass]">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ formattedTime }}
                </div>
            </div>
        </template>

        <div class="space-y-4">
            <!-- Question -->
            <div class="text-text-primary">
                <p class="text-base leading-relaxed">{{ interaction?.question }}</p>
            </div>

            <!-- Expired State -->
            <div v-if="isExpired" class="p-4 bg-status-failed/10 border border-status-failed/20 rounded-lg">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-status-failed" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-status-failed text-sm font-medium">
                        This interaction has expired. The agent may have timed out.
                    </p>
                </div>
            </div>

            <!-- Error State -->
            <div v-else-if="hasError" class="p-4 bg-status-failed/10 border border-status-failed/20 rounded-lg">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-status-failed" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <p class="text-status-failed text-sm font-medium">{{ error }}</p>
                    </div>
                    <Button variant="ghost" size="sm" @click="emit('retry')">
                        Retry
                    </Button>
                </div>
            </div>

            <!-- Confirm Type (Yes/No) -->
            <div v-else-if="isConfirm" class="flex gap-3">
                <Button
                    variant="primary"
                    class="flex-1"
                    :loading="isSubmitting"
                    :disabled="remainingSeconds <= 0"
                    @click="handleConfirm('yes')"
                >
                    Yes
                </Button>
                <Button
                    variant="ghost"
                    class="flex-1"
                    :loading="isSubmitting"
                    :disabled="remainingSeconds <= 0"
                    @click="handleConfirm('no')"
                >
                    No
                </Button>
            </div>

            <!-- Select Type (Options) -->
            <div v-else-if="hasOptions" class="space-y-2">
                <div
                    v-for="option in interaction?.options"
                    :key="option.label"
                    :class="[
                        'p-3 rounded-lg border cursor-pointer transition-all',
                        isMultiSelect
                            ? selectedOptions.includes(option.label)
                                ? 'bg-primary/10 border-primary'
                                : 'bg-bg-tertiary border-border-subtle hover:border-border-default'
                            : selectedOption === option.label
                                ? 'bg-primary/10 border-primary'
                                : 'bg-bg-tertiary border-border-subtle hover:border-border-default',
                    ]"
                    @click="isMultiSelect ? toggleOption(option.label) : selectedOption = option.label"
                >
                    <div class="flex items-start gap-3">
                        <div :class="[
                            'mt-0.5 w-4 h-4 rounded-full border-2 flex-shrink-0 flex items-center justify-center',
                            isMultiSelect
                                ? selectedOptions.includes(option.label) ? 'border-primary bg-primary' : 'border-border-default'
                                : selectedOption === option.label ? 'border-primary bg-primary' : 'border-border-default',
                        ]">
                            <svg v-if="(isMultiSelect && selectedOptions.includes(option.label)) || (!isMultiSelect && selectedOption === option.label)" class="w-2.5 h-2.5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-text-primary font-medium">{{ option.label }}</p>
                            <p v-if="option.description" class="text-sm text-text-secondary mt-0.5">{{ option.description }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Text Type -->
            <div v-else>
                <textarea
                    v-model="textResponse"
                    rows="4"
                    :disabled="isSubmitting || remainingSeconds <= 0"
                    class="w-full px-4 py-3 bg-bg-tertiary border border-border-subtle rounded-lg text-text-primary placeholder-text-tertiary focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary resize-none"
                    placeholder="Type your response..."
                    @keydown.ctrl.enter="handleSubmit"
                    @keydown.meta.enter="handleSubmit"
                />
                <p class="text-xs text-text-tertiary mt-1">
                    Press Ctrl+Enter to submit
                </p>
            </div>
        </div>

        <template #footer>
            <Button
                variant="ghost"
                @click="emit('dismiss')"
                :disabled="isSubmitting"
            >
                Skip
            </Button>
            <Button
                v-if="!isConfirm"
                variant="primary"
                :loading="isSubmitting"
                :disabled="!canSubmit"
                @click="handleSubmit"
            >
                Submit Response
            </Button>
        </template>
    </Modal>
</template>
