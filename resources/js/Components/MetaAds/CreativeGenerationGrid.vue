<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import CreativeCard from './CreativeCard.vue';
import { SparklesIcon, CheckIcon } from '@/Components/Icons';

interface Creative {
    creative_index: number;
    creative_id?: number;
    headline?: string;
    primary_text?: string;
    description?: string;
    image_url?: string;
    variant_name: string;
}

interface ProgressEvent {
    step: string;
    message: string;
    current: number;
    total: number;
    progress_percentage: number;
    metadata: {
        creative_index?: number;
        creative_id?: number;
        headline?: string;
        primary_text?: string;
        description?: string;
        image_url?: string;
        variant_name?: string;
        variation?: string;
    } | null;
}

const props = defineProps<{
    totalCreatives: number;
    isGenerating: boolean;
    isComplete: boolean;
    currentProgress: ProgressEvent | null;
    progressHistory: ProgressEvent[];
    success?: boolean;
}>();

const completedCreatives = ref<Map<number, Creative>>(new Map());
const currentlyGeneratingIndex = ref<number | null>(null);
const generatingStep = ref<string>('Waiting...');

watch(() => props.currentProgress, (progress) => {
    if (!progress) return;

    if (progress.step === 'image_generation' && progress.metadata?.variation) {
        const match = progress.message.match(/image (\d+)/);
        if (match) {
            currentlyGeneratingIndex.value = parseInt(match[1]) - 1;
            generatingStep.value = `Creating image for "${progress.metadata.variation}" angle...`;
        }
    }

    if (progress.step === 'creative_saved' && progress.metadata?.creative_index !== undefined) {
        const creative: Creative = {
            creative_index: progress.metadata.creative_index,
            creative_id: progress.metadata.creative_id,
            headline: progress.metadata.headline,
            primary_text: progress.metadata.primary_text,
            description: progress.metadata.description,
            image_url: progress.metadata.image_url,
            variant_name: progress.metadata.variant_name || chr(65 + progress.metadata.creative_index),
        };
        completedCreatives.value.set(progress.metadata.creative_index, creative);

        if (progress.metadata.creative_index + 1 < props.totalCreatives) {
            currentlyGeneratingIndex.value = progress.metadata.creative_index + 1;
        } else {
            currentlyGeneratingIndex.value = null;
        }
    }
}, { immediate: true });

watch(() => props.isGenerating, (generating) => {
    if (generating && currentlyGeneratingIndex.value === null) {
        currentlyGeneratingIndex.value = 0;
        generatingStep.value = 'Preparing creative generation...';
    }
});

function chr(code: number): string {
    return String.fromCharCode(code);
}

function getCardState(index: number): 'skeleton' | 'generating' | 'complete' | 'failed' {
    if (completedCreatives.value.has(index)) {
        return 'complete';
    }
    if (props.isGenerating && currentlyGeneratingIndex.value === index) {
        return 'generating';
    }
    if (props.isComplete && !completedCreatives.value.has(index)) {
        return 'failed';
    }
    return 'skeleton';
}

function getCreative(index: number): Creative | null {
    return completedCreatives.value.get(index) || null;
}

const completedCount = computed(() => completedCreatives.value.size);
const overallProgress = computed(() => {
    if (props.totalCreatives === 0) return 0;
    return Math.round((completedCount.value / props.totalCreatives) * 100);
});
</script>

<template>
    <div class="creative-generation-grid">
        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <div
                    :class="[
                        'w-10 h-10 rounded-full flex items-center justify-center',
                        isComplete && success ? 'bg-green-500/10' : 'bg-[var(--color-accent)]/10',
                    ]"
                >
                    <CheckIcon v-if="isComplete && success" :size="20" class="text-green-500" />
                    <SparklesIcon v-else :size="20" :class="isGenerating ? 'text-[var(--color-accent)] animate-pulse' : 'text-[var(--color-text-tertiary)]'" />
                </div>
                <div>
                    <h3 class="font-semibold text-[var(--color-text-primary)]">
                        Ad Creative Variants
                    </h3>
                    <p class="text-sm text-[var(--color-text-secondary)]">
                        <span v-if="isGenerating">
                            Generating {{ completedCount }}/{{ totalCreatives }} creatives...
                        </span>
                        <span v-else-if="isComplete && success">
                            {{ completedCount }} creatives ready for review
                        </span>
                        <span v-else>
                            {{ totalCreatives }} variants will be generated
                        </span>
                    </p>
                </div>
            </div>

            <!-- Mini Progress -->
            <div v-if="isGenerating" class="text-right">
                <span class="text-2xl font-bold text-[var(--color-accent)]">{{ overallProgress }}%</span>
            </div>
        </div>

        <!-- Progress Bar -->
        <div v-if="isGenerating || (isComplete && !success)" class="mb-6">
            <div class="h-1.5 bg-[var(--color-bg-tertiary)] rounded-full overflow-hidden">
                <div
                    class="h-full transition-all duration-700 ease-out rounded-full"
                    :class="isComplete && !success ? 'bg-red-500' : 'bg-[var(--color-accent)]'"
                    :style="{ width: `${overallProgress}%` }"
                ></div>
            </div>
        </div>

        <!-- Creative Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <TransitionGroup name="creative">
                <CreativeCard
                    v-for="index in totalCreatives"
                    :key="index - 1"
                    :creative="getCreative(index - 1)"
                    :state="getCardState(index - 1)"
                    :variant-letter="chr(64 + index)"
                    :generating-step="currentlyGeneratingIndex === index - 1 ? generatingStep : 'Waiting...'"
                />
            </TransitionGroup>
        </div>

        <!-- Generation Log (collapsed) -->
        <details v-if="progressHistory.length > 0" class="mt-6">
            <summary class="text-xs text-[var(--color-text-tertiary)] cursor-pointer hover:text-[var(--color-text-secondary)]">
                View generation log ({{ progressHistory.length }} events)
            </summary>
            <div class="mt-3 space-y-1 max-h-48 overflow-y-auto p-3 bg-[var(--color-bg-tertiary)] rounded-lg">
                <div
                    v-for="(event, idx) in progressHistory"
                    :key="idx"
                    class="text-xs font-mono text-[var(--color-text-tertiary)]"
                >
                    <span class="text-[var(--color-text-quaternary)]">{{ event.timestamp?.split('T')[1]?.split('.')[0] || '' }}</span>
                    {{ event.message }}
                </div>
            </div>
        </details>
    </div>
</template>

<style scoped>
.creative-enter-active,
.creative-leave-active {
    transition: all 0.5s ease;
}

.creative-enter-from {
    opacity: 0;
    transform: translateY(20px) scale(0.95);
}

.creative-leave-to {
    opacity: 0;
    transform: translateY(-20px) scale(0.95);
}

.creative-move {
    transition: transform 0.5s ease;
}
</style>
