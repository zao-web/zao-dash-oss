<script setup lang="ts">
type Variant = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const props = withDefaults(defineProps<{
    variant?: Variant;
    size?: 'sm' | 'md';
    pill?: boolean;
}>(), {
    variant: 'neutral',
    size: 'md',
    pill: true,
});

const variants = {
    success: 'bg-status-running/10 text-status-running',
    warning: 'bg-status-pending/10 text-status-pending',
    danger: 'bg-status-failed/10 text-status-failed',
    info: 'bg-status-completed/10 text-status-completed',
    neutral: 'bg-void-700/50 text-void-300',
};

const sizes = {
    sm: 'px-2 py-0.5 text-xs',
    md: 'px-2.5 py-1 text-xs',
};
</script>

<template>
    <span
        :class="[
            'inline-flex items-center font-medium',
            variants[variant],
            sizes[size],
            pill ? 'rounded-full' : 'rounded-lg',
        ]"
    >
        <slot />
    </span>
</template>
