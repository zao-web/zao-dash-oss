<script setup lang="ts">
import { computed } from 'vue';
import { CheckIcon, RefreshIcon, SparklesIcon } from '@/Components/Icons';

interface Creative {
    creative_index: number;
    creative_id?: number;
    headline?: string;
    primary_text?: string;
    description?: string;
    image_url?: string;
    variant_name: string;
}

type CardState = 'skeleton' | 'generating' | 'complete' | 'failed';

const props = withDefaults(defineProps<{
    creative?: Creative | null;
    state: CardState;
    variantLetter: string;
    generatingStep?: string;
}>(), {
    creative: null,
    state: 'skeleton',
    generatingStep: 'Waiting...',
});

const displayHeadline = computed(() => {
    if (props.state === 'complete' && props.creative?.headline) {
        return props.creative.headline;
    }
    return null;
});

const displayText = computed(() => {
    if (props.state === 'complete' && props.creative?.primary_text) {
        return props.creative.primary_text.length > 120
            ? props.creative.primary_text.substring(0, 120) + '...'
            : props.creative.primary_text;
    }
    return null;
});
</script>

<template>
    <div
        :class="[
            'creative-card relative overflow-hidden rounded-xl border transition-all duration-500',
            state === 'complete' ? 'border-green-500/30 bg-green-500/5' : 'border-[var(--color-border-subtle)] bg-[var(--color-bg-secondary)]',
            state === 'generating' && 'ring-2 ring-[var(--color-accent)]/50',
        ]"
    >
        <!-- Variant Badge -->
        <div
            :class="[
                'absolute top-3 left-3 z-10 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold',
                state === 'complete' ? 'bg-green-500 text-white' : 'bg-[var(--color-bg-tertiary)] text-[var(--color-text-secondary)]',
            ]"
        >
            {{ variantLetter }}
        </div>

        <!-- Status Badge -->
        <div class="absolute top-3 right-3 z-10">
            <span v-if="state === 'complete'" class="badge badge-green flex items-center gap-1">
                <CheckIcon :size="12" />
                Ready
            </span>
            <span v-else-if="state === 'generating'" class="badge badge-blue flex items-center gap-1">
                <RefreshIcon :size="12" class="animate-spin" />
                Generating
            </span>
            <span v-else-if="state === 'failed'" class="badge badge-red">
                Failed
            </span>
            <span v-else class="badge badge-gray">
                Pending
            </span>
        </div>

        <!-- Image Area -->
        <div class="aspect-[1.91/1] relative">
            <!-- Skeleton State -->
            <div
                v-if="state === 'skeleton'"
                class="absolute inset-0 bg-[var(--color-bg-tertiary)] animate-pulse"
            >
                <div class="absolute inset-0 flex items-center justify-center">
                    <SparklesIcon :size="32" class="text-[var(--color-text-tertiary)]" />
                </div>
            </div>

            <!-- Generating State -->
            <div
                v-else-if="state === 'generating'"
                class="absolute inset-0 bg-gradient-to-br from-[var(--color-accent)]/10 to-[var(--color-accent)]/5"
            >
                <div class="absolute inset-0 flex flex-col items-center justify-center gap-3">
                    <div class="relative">
                        <div class="w-16 h-16 rounded-full border-4 border-[var(--color-accent)]/20 border-t-[var(--color-accent)] animate-spin"></div>
                        <SparklesIcon :size="24" class="absolute inset-0 m-auto text-[var(--color-accent)]" />
                    </div>
                    <span class="text-xs text-[var(--color-text-secondary)] px-4 text-center">
                        {{ generatingStep }}
                    </span>
                </div>
            </div>

            <!-- Complete State - Actual Image -->
            <img
                v-else-if="state === 'complete' && creative?.image_url"
                :src="creative.image_url"
                :alt="creative.headline || 'Ad creative'"
                class="absolute inset-0 w-full h-full object-cover"
            />

            <!-- Failed State -->
            <div
                v-else-if="state === 'failed'"
                class="absolute inset-0 bg-red-500/10 flex items-center justify-center"
            >
                <span class="text-red-400 text-sm">Generation failed</span>
            </div>
        </div>

        <!-- Content Area -->
        <div class="p-4 space-y-2">
            <!-- Headline -->
            <div v-if="state === 'skeleton' || state === 'generating'" class="space-y-2">
                <div class="h-5 bg-[var(--color-bg-tertiary)] rounded animate-pulse w-3/4"></div>
                <div class="h-4 bg-[var(--color-bg-tertiary)] rounded animate-pulse w-full"></div>
                <div class="h-4 bg-[var(--color-bg-tertiary)] rounded animate-pulse w-5/6"></div>
            </div>

            <div v-else-if="state === 'complete'" class="space-y-2">
                <h4 class="font-semibold text-[var(--color-text-primary)] line-clamp-2">
                    {{ displayHeadline }}
                </h4>
                <p class="text-sm text-[var(--color-text-secondary)] line-clamp-3">
                    {{ displayText }}
                </p>
            </div>

            <div v-else-if="state === 'failed'" class="space-y-2">
                <p class="text-sm text-[var(--color-text-tertiary)]">
                    This variant could not be generated. The campaign will continue with other variants.
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.creative-card {
    transform: translateY(0);
    transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.5s ease, background-color 0.5s ease;
}

.creative-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
}

.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.line-clamp-3 {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
