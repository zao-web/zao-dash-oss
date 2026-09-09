<script setup lang="ts">
const props = defineProps<{
    score: number | string;
    size?: 'sm' | 'md' | 'lg';
}>();

const numScore = typeof props.score === 'string' ? parseFloat(props.score) || 0 : (props.score ?? 0);

const colorClass = numScore >= 8
    ? 'text-pulse-emerald'
    : numScore >= 6
        ? 'text-pulse-amber'
        : 'text-pulse-rose';

const sizeClasses = {
    sm: 'text-sm',
    md: 'text-lg',
    lg: 'text-2xl',
};
</script>

<template>
    <span :class="['font-mono font-semibold', colorClass, sizeClasses[size || 'md']]">
        {{ numScore.toFixed(1) }}
    </span>
</template>
