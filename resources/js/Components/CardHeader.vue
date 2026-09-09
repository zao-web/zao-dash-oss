<script setup lang="ts">
defineProps<{
    title: string;
    count?: number;
    countColor?: 'amber' | 'emerald' | 'rose' | 'violet';
}>();

const countColors = {
    amber: 'bg-pulse-amber/20 text-pulse-amber',
    emerald: 'bg-pulse-emerald/20 text-pulse-emerald',
    rose: 'bg-pulse-rose/20 text-pulse-rose',
    violet: 'bg-pulse-violet/20 text-pulse-violet',
};
</script>

<template>
    <div class="flex items-center justify-between p-6 border-b border-void-800/50">
        <div class="flex items-center gap-3">
            <slot name="icon" />
            <h2 class="font-display text-lg font-semibold text-void-50">{{ title }}</h2>
        </div>
        <div class="flex items-center gap-3">
            <slot name="actions" />
            <span
                v-if="count !== undefined"
                :class="[
                    'flex h-6 min-w-6 items-center justify-center rounded-full px-2 text-xs font-mono font-bold',
                    countColors[countColor || 'amber']
                ]"
            >
                {{ count }}
            </span>
        </div>
    </div>
</template>
