<script setup lang="ts">
defineProps<{
    title: string;
    subtitle: string;
    actionLabel: string;
    accentColor?: 'violet' | 'cyan' | 'emerald' | 'amber';
}>();

const emit = defineEmits<{
    action: [];
}>();

const accentClasses = {
    violet: 'hover:border-pulse-violet/30 hover:shadow-pulse-violet/5 text-pulse-violet hover:text-pulse-cyan',
    cyan: 'hover:border-pulse-cyan/30 hover:shadow-pulse-cyan/5 text-pulse-cyan hover:text-pulse-emerald',
    emerald: 'hover:border-pulse-emerald/30 hover:shadow-pulse-emerald/5 text-pulse-emerald hover:text-pulse-amber',
    amber: 'hover:border-pulse-amber/30 hover:shadow-pulse-amber/5 text-pulse-amber hover:text-pulse-violet',
};
</script>

<template>
    <div
        :class="[
            'rounded-xl bg-void-900/80 border border-void-800/50 p-5 transition-all hover:shadow-lg',
            accentClasses[accentColor || 'violet'],
        ]"
    >
        <p class="text-sm text-void-100 mb-2">{{ title }}</p>
        <p class="text-xs text-void-500 mb-4">{{ subtitle }}</p>
        <button
            class="text-xs font-medium transition-colors"
            @click="emit('action')"
        >
            {{ actionLabel }} →
        </button>
    </div>
</template>
