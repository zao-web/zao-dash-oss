<script setup lang="ts">
const props = withDefaults(defineProps<{
    name: string;
    size?: 'sm' | 'md' | 'lg' | 'xl';
    variant?: 'gradient' | 'solid';
}>(), {
    size: 'md',
    variant: 'solid',
});

const getInitials = (name: string) => {
    return name
        .split(' ')
        .map(word => word[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
};

const sizeClasses = {
    sm: 'h-7 w-7 text-xs',
    md: 'h-9 w-9 text-sm',
    lg: 'h-11 w-11 text-base',
    xl: 'h-14 w-14 text-lg',
};

const variantClasses = {
    gradient: 'bg-gradient-to-br from-pulse-cyan to-pulse-emerald text-void-950',
    solid: 'bg-void-700/50 text-void-300',
};
</script>

<template>
    <div
        :class="[
            'flex items-center justify-center rounded-xl font-semibold',
            sizeClasses[size],
            variantClasses[variant],
        ]"
    >
        {{ getInitials(name) }}
    </div>
</template>
